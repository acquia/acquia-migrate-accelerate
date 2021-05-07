<?php

namespace Drupal\acquia_migrate;

use Drupal\acquia_migrate\Clusterer\Heuristics\SharedLanguageConfig;
use Drupal\acquia_migrate\Controller\HttpApi;
use Drupal\acquia_migrate\Exception\RowPreviewException;
use Drupal\acquia_migrate\Plugin\migrate\id_map\SqlWithCentralizedMessageStorage;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * A "migration" as this module defines them, contains >=1 "Drupal" migrations.
 *
 * @internal
 */
final class Migration {

  /**
   * The regular expression pattern that migration IDs conform to.
   *
   * @var string
   */
  const ID_PATTERN = '/^[a-f0-9]{32}-[^\/]{1,192}$/';

  /**
   * The minimum import ratio that all dependencies are required to reach.
   *
   * Note: we consider intentionally skipped rows also as imported. Put
   * differently: what matters is that a max ratio has errors.
   *
   * @see ::isImportable()
   * @see ::allDependencyRowsProcessed()
   *
   * @var int
   */
  const MINIMUM_IMPORT_RATIO = 0.70;

  /**
   * Nothing is happening for this migration.
   *
   * @var string
   */
  const ACTIVITY_IDLE = 'idle';

  /**
   * This migration is being imported.
   *
   * @var string
   */
  const ACTIVITY_IMPORTING = 'importing';

  /**
   * This migration is being rolled back.
   *
   * @var string
   */
  const ACTIVITY_ROLLING_BACK = 'rollingBack';

  /**
   * This migration is being refreshed.
   *
   * @var string
   */
  const ACTIVITY_REFRESHING = 'refreshing';

  /**
   * The migration ID: an opaque identifier, without meaning.
   *
   * @var string
   */
  protected $id;

  /**
   * The migration label: a human-readable name.
   *
   * @var string
   */
  protected $label;

  /**
   * Migration plugins instances that cause dependencies, keyed by migration ID.
   *
   * @var array
   */
  protected $dependencies;

  /**
   * List of migration plugin instances, keyed by ID, sorted by execution order.
   *
   * Executing this migration requires executing these migration plugins, in
   * this order.
   *
   * @var \Drupal\migrate\Plugin\MigrationInterface[]
   */
  protected $migrationPlugins;

  /**
   * A set of migration plugin IDs that represent the actual migration of data.
   *
   * All other migration plugins are for migration the supporting "data model".
   * Logically speaking, this means all these plugin IDs must be at the end of
   * the $migrationPlugins list.
   *
   * @var string[]
   */
  protected $dataMigrationPluginIds;

  /**
   * Whether this migration is marked as completed.
   *
   * @var bool
   */
  protected $completed;

  /**
   * Whether this migration is marked as skipped.
   *
   * @var bool
   */
  protected $skipped;

  /**
   * The source data fingerprint taken when the migration was last imported.
   *
   * @var string
   */
  protected $lastImportFingerprint;

  /**
   * The source data fingerprint that was most recently computed.
   *
   * @var string
   */
  protected $lastComputedFingerprint;

  /**
   * The UNIX timestamp when the last import started, if any.
   *
   * @var int|null
   */
  protected $lastImportTimestamp;

  /**
   * The duration in seconds of the last import, if any.
   *
   * @var int|null
   */
  protected $lastImportDuration;

  /**
   * Whether this migration supports rollbacks.
   *
   * @var bool
   */
  private $supportsRollback;

  /**
   * Whether all rows have been processed.
   *
   * @var bool
   *
   * @see ::allRowsProcessed()
   */
  private $allRowsProcessed;

  /**
   * Constructs a new Migration.
   *
   * @param string $id
   *   The migration ID.
   * @param string $label
   *   The migration label.
   * @param array $dependencies
   *   The IDs of the migrations this migration depends on. Migration IDs as
   *   keys, with an array of migration plugin instances that cause this
   *   dependency as the value for each key.
   * @param \Drupal\migrate\Plugin\Migration[] $migration_plugins
   *   List of migration plugin instances this migration consists of, keyed by
   *   ID, sorted by execution order.
   * @param string[] $data_migration_plugin_ids
   *   The set of migration plugin IDs that represent the actual migration of
   *   data.
   * @param bool $completed
   *   Whether this migration is marked as completed.
   * @param bool $skipped
   *   Whether this migration is marked as skipped.
   * @param string $last_import_fingerprint
   *   The data fingerprint taken when the migration was last imported.
   * @param string $last_computed_fingerprint
   *   The data fingerprint that was most recently computed.
   * @param int|null $last_import_timestamp
   *   The UNIX timestamp when the last import started, if any.
   * @param int|null $last_import_duration
   *   The duration in seconds of the last import, if any.
   */
  public function __construct(string $id, string $label, array $dependencies, array $migration_plugins, array $data_migration_plugin_ids, bool $completed, bool $skipped, string $last_import_fingerprint, string $last_computed_fingerprint, ?int $last_import_timestamp, ?int $last_import_duration) {
    if (!preg_match(static::ID_PATTERN, $id)) {
      throw new \InvalidArgumentException("Invalid migration ID: $id.");
    }
    $this->id = $id;
    $this->label = $label;
    assert(Inspector::assertAllStrings(array_keys($dependencies)));
    $this->dependencies = $dependencies;
    assert(Inspector::assertAllObjects($migration_plugins, MigrationPlugin::class));
    $this->migrationPlugins = $migration_plugins;
    assert(Inspector::assertAllStrings($data_migration_plugin_ids));
    $this->dataMigrationPluginIds = $data_migration_plugin_ids;
    $this->completed = $completed;
    $this->skipped = $skipped;
    $this->lastImportFingerprint = $last_import_fingerprint;
    $this->lastComputedFingerprint = $last_computed_fingerprint;
    $this->lastImportTimestamp = $last_import_timestamp;
    $this->lastImportDuration = $last_import_duration;

    // Computed properties, that can only change when the migration
    // change, which automatically causes these objects to be reconstructed.
    // @see \Drupal\acquia_migrate\MigrationRepository::getMigrations()
    $this->supportsRollback = $this->computeSupportsRollback();
  }

  /**
   * Computes whether this migration supports rollbacks.
   *
   * @return bool
   *   Whether this migration supports rollbacks.
   */
  private function computeSupportsRollback() : bool {
    // @todo: should there be a special case here when *some*, but not *all*, plugins support rollback?
    $rollback_capable_plugins = array_reduce($this->migrationPlugins, function (array $rollback_capable_plugins, MigrationInterface $migration_plugin) {
      return $migration_plugin->getDestinationPlugin()->supportsRollback()
        ? array_merge($rollback_capable_plugins, [$migration_plugin])
        : $rollback_capable_plugins;
    }, []);
    return count($rollback_capable_plugins) > 0 && count($rollback_capable_plugins) === count($this->migrationPlugins);
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep() {
    $this->_migrationPlugins = [];
    $this->_dependencies = [];

    $vars = get_object_vars($this);

    $migration_plugin_ids = array_keys($this->migrationPlugins);
    $this->_migrationPlugins = array_combine($migration_plugin_ids, $migration_plugin_ids);
    unset($vars['migrationPlugins']);

    foreach ($this->dependencies as $key => $dependency_migration_plugins) {
      $dependency_migration_plugin_ids = array_keys($dependency_migration_plugins);
      $this->_dependencies[$key] = array_combine($dependency_migration_plugin_ids, $dependency_migration_plugin_ids);
    }
    unset($vars['dependencies']);

    // @todo In the future, consider caching this and manually invalidating this.
    unset($vars['allRowsProcessed']);

    return array_keys($vars);
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup() {
    static $all_migration_plugins;
    static $all_flags;

    // Load fresh data once, to ensure that when many objects of this type are
    // unserialized, they do not all trigger the same expensive operations.
    // @see \Drupal\acquia_migrate\MigrationRepository::getMigrations()
    if (!isset($all_migration_plugins)) {
      $container = \Drupal::getContainer();
      $all_migration_plugins = $container->get('plugin.manager.migration')
        ->createInstancesByTag('Drupal 7');
      $all_flags = $container->get('acquia_migrate.migration_repository')
        ->getMigrationFlags();
    }

    // Restore migration plugin instances.
    foreach (array_keys($this->_migrationPlugins) as $id) {
      $this->migrationPlugins[$id] = $all_migration_plugins[$id];
    }
    $this->_migrationPlugins = [];

    $this->dependencies = [];
    foreach ($this->_dependencies as $key => $dependency_migration_plugin_ids) {
      foreach (array_keys($dependency_migration_plugin_ids) as $id) {
        $this->dependencies[$key][$id] = $all_migration_plugins[$id];
      }
    }
    $this->_dependencies = [];

    // Update flags.
    $flags = $all_flags[$this->id];
    $this->completed = $flags->completed;
    $this->skipped = $flags->skipped;
    $this->lastImportFingerprint = $flags->last_import_fingerprint;
    $this->lastComputedFingerprint = $flags->last_computed_fingerprint;
    $this->lastImportTimestamp = $flags->last_import_timestamp;
    $this->lastImportDuration = $flags->last_import_duration;
  }

  /**
   * Gets the migration ID.
   *
   * @return string
   *   The migration ID.
   */
  public function id() : string {
    return $this->id;
  }

  /**
   * Gets the migration label.
   *
   * @return string
   *   The migration label.
   */
  public function label() : string {
    return $this->label;
  }

  /**
   * Generates a migration ID from the provided migration label.
   *
   * @return string
   *   A migration ID.
   *
   * @see ::labelForId()
   * @see \Drupal\acquia_migrate\Plugin\migrate\destination\RollbackableInterface::ROLLBACK_DATA_TABLE
   * @see acquia_migrate_schema()
   */
  public static function generateIdFromLabel(string $label) : string {
    // Generates an opaque identifier with a legible suffix at the end, to
    // balance:
    // - not baking in assumptions as we get this project off the ground
    // - easier debugging while we get this project off the ground
    // The intent is to change the opaque identifiers in the future, and if they
    // truly are treated as opaque, that will be easy to do. This opaque
    // identifier must be at most 225 ASCII characters long due to MySQL 5.6
    // restrictions (767-255-255-32). An MD5-hash is 32 characters, the dash is
    // one and that leaves 192 characters of the label.
    $migration_id = md5($label) . '-' . substr(str_replace('/', '-', $label), 0, 192);
    assert(static::isValidMigrationId($migration_id));
    return $migration_id;
  }

  /**
   * Checks whether the given string is a valid migration ID.
   *
   * @param string $potential_migration_id
   *   A string to evaluate.
   *
   * @return bool
   *   Whether the given string is a valid migration ID.
   */
  public static function isValidMigrationId(string $potential_migration_id) : bool {
    return preg_match(Migration::ID_PATTERN, $potential_migration_id);
  }

  /**
   * Gets the migration label for a given migration ID.
   *
   * @param string $migration_id
   *   A migration ID.
   *
   * @return string
   *   The corresponding migration label.
   *
   * @see ::generateIdFromLabel()
   */
  public static function labelForId(string $migration_id) : string {
    assert(preg_match(static::ID_PATTERN, $migration_id));
    return explode('-', $migration_id, 2)[1];
  }

  /**
   * Whether this is a supporting config-only migration.
   *
   * @return bool
   *   TRUE if this is a migration containing no data, but only supporting
   *   configuration.
   */
  public function isSupportingConfigOnly() : bool {
    // @todo Somehow tie this to \Drupal\acquia_migrate\Clusterer\Heuristics\SharedEntityStructure?
    return $this->label === SharedLanguageConfig::cluster() || strpos($this->label, 'Shared structure for ') !== FALSE;
  }

  /**
   * Gets the IDs of the migrations that this migration depends on.
   *
   * @return string[]
   *   A list of migration IDs.
   */
  public function getDependencies() : array {
    return array_keys($this->dependencies);
  }

  /**
   * Gets the IDs of the migrations that this migration depends on.
   *
   * @return string[]
   *   The IDs of the migrations this migration depends on. Migration IDs as
   *   keys, with an array of migration plugin IDs that cause this dependency as
   *   the value for each key.
   */
  public function getDependenciesWithReasons() : array {
    $dependencies_with_reasons = [];
    foreach ($this->dependencies as $migration_id => $migration_plugins) {
      $dependencies_with_reasons[$migration_id] = array_keys($migration_plugins);
    }
    return $dependencies_with_reasons;
  }

  /**
   * Gets the IDs of the migration plugins that this migration consists of.
   *
   * @return string[]
   *   A list of migration plugin IDs.
   */
  public function getMigrationPluginIds() : array {
    return array_keys($this->migrationPlugins);
  }

  /**
   * Gets the IDs of the data migration plugins that this migration consists of.
   *
   * @return string[]
   *   A list of data migration plugin IDs.
   */
  public function getDataMigrationPluginIds() : array {
    return $this->dataMigrationPluginIds;
  }

  /**
   * Gets the the migration plugins that this migration consists of.
   *
   * @return \Drupal\migrate\Plugin\Migration[]
   *   A list of migration plugin instances.
   */
  public function getMigrationPluginInstances() : array {
    return $this->migrationPlugins;
  }

  /**
   * Gets the imported count (*data* migration plugins only).
   *
   * @return int
   *   The imported count.
   */
  public function getImportedCount() : int {
    return array_reduce($this->dataMigrationPluginIds, function (int $sum, string $id) {
      $sum += $this->migrationPlugins[$id]->getIdMap()->importedCount();
      return $sum;
    }, 0);
  }

  /**
   * Gets the imported count (*data* migration plugins only).
   *
   * @return int
   *   The imported count.
   */
  public function getUiImportedCount() : int {
    return array_reduce($this->dataMigrationPluginIds, function (int $sum, string $id) {
      $sum += $this->migrationPlugins[$id]->getIdMap()->importedCountWithoutNeedsUpdateItems();
      return $sum;
    }, 0);
  }

  /**
   * Gets the processed count (*data* migration plugins only).
   *
   * @return int
   *   The total count.
   */
  public function getProcessedCount() : int {
    return array_reduce($this->dataMigrationPluginIds, function (int $sum, string $id) {
      $sum += $this->migrationPlugins[$id]->getIdMap()->processedCount();
      return $sum;
    }, 0);
  }

  /**
   * Gets the processed count (*data* migration plugins only).
   *
   * @return int
   *   The total count.
   */
  public function getUiProcessedCount() : int {
    return array_reduce($this->dataMigrationPluginIds, function (int $sum, string $id) {
      $sum += $this->migrationPlugins[$id]->getIdMap()->processedCountWithoutNeedsUpdateItems();
      return $sum;
    }, 0);
  }

  /**
   * Gets the total count (*data* migration plugins only).
   *
   * @return int
   *   The total count.
   */
  public function getTotalCount() : int {
    return array_reduce($this->dataMigrationPluginIds, function (int $sum, string $id) {
      $sum += $this->migrationPlugins[$id]->getSourcePlugin()->count();
      return $sum;
    }, 0);
  }

  /**
   * Gets the message count (*data* migration plugins only).
   *
   * @param string|null $category
   *   (optional) One of \Drupal\acquia_migrate\Controller\HttpApi::MESSAGE_*.
   *
   * @return int
   *   The message count.
   */
  public function getMessageCount(string $category = NULL) : int {
    // @codingStandardsIgnoreStart
    assert($category === NULL || in_array($category, [HttpApi::MESSAGE_CATEGORY_OTHER, HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION]));
    $connection = \Drupal::database();
    // @codingStandardsIgnoreEnd

    if (!$connection->schema()->tableExists(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE)) {
      return 0;
    }

    $query = $connection->select(SqlWithCentralizedMessageStorage::CENTRALIZED_MESSAGE_TABLE)
      ->condition(SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID, $this->id);

    if ($category !== NULL) {
      $query->condition(SqlWithCentralizedMessageStorage::COLUMN_CATEGORY, $category);
    }

    return $query
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Whether this migration is preselectable or not.
   *
   * A preselectable migration is one that a site builder would probably like to
   * be able to skip. In contrast to a preselectable migration, for example,
   * there are some migrations that cannot be skipped because they are
   * essential to any successful migration.
   *
   * @return bool
   *   TRUE if the migration is preselectable, FALSE otherwise.
   *
   * @see \Drupal\Component\Plugin\PluginBase::getBaseId()
   */
  public function isPreselectable() : bool {
    $migration_plugin_ids = $this->getMigrationPluginIds();
    $data_migration_plugin_ids = $this->getDataMigrationPluginIds();
    $has_structural_migration_plugins = !empty(array_diff($migration_plugin_ids, $data_migration_plugin_ids));
    $has_data_migration_plugins = !empty($data_migration_plugin_ids);
    // Copied from PluginBase::getBaseId().
    $get_base_plugin_id = function ($plugin_id) {
      if (strpos($plugin_id, PluginBase::DERIVATIVE_SEPARATOR)) {
        list($plugin_id) = explode(PluginBase::DERIVATIVE_SEPARATOR, $plugin_id, 2);
      }
      return $plugin_id;
    };
    $data_migration_plugins_are_for_single_type = count(array_unique(array_map($get_base_plugin_id, $data_migration_plugin_ids))) === 1;
    return $has_data_migration_plugins && ($has_structural_migration_plugins || $data_migration_plugins_are_for_single_type);
  }

  /**
   * Whether this migration is marked as completed.
   *
   * @return bool
   *   Whether this migration is marked as completed.
   */
  public function isCompleted() : bool {
    return $this->completed;
  }

  /**
   * Whether this migration is marked as skipped.
   *
   * @return bool
   *   Whether this migration is marked as skipped.
   */
  public function isSkipped() : bool {
    return $this->skipped;
  }

  /**
   * Whether this migration has been imported.
   *
   * @return bool
   *   Whether this migration has been imported.
   */
  public function isImported() : bool {
    return $this->lastImportTimestamp !== NULL;
  }

  /**
   * Whether this migration's imported data is stale.
   *
   * @return bool
   *   Whether this migration is stale.
   */
  public function isStale() : bool {
    return MigrationFingerprinter::detectChange($this->lastImportFingerprint, $this->lastComputedFingerprint) && $this->canBeRolledBack();
  }

  /**
   * The UNIX timestamp when the last import started, if any.
   *
   * @return int|null
   *   The timestamp, if any.
   */
  public function getLastImportTimestamp() : ?int {
    return $this->lastImportTimestamp;
  }

  /**
   * The duration in seconds of the last import, if any.
   *
   * @return int|null
   *   The duration, if any.
   */
  public function getLastImportDuration() : ?int {
    // Ensure the last import duration is always up-to-date.
    // @todo Somehow we can get a Migration object with a stale "last import
    // duration" value, even though it is never cached: not in Page Cache,
    // Dynamic Page Cache, HttpApi, MigrationConverter or MigrationRepository.
    // For now, this work-around solves the mystery…
    // @codingStandardsIgnoreStart
    $before = $this->lastImportDuration;
    $fresh_flags = (array) \Drupal::database()->select('acquia_migrate_migration_flags', 'm')
      ->fields('m', [
        'migration_id',
        'last_import_duration',
      ])
      ->condition('migration_id', $this->id)
      ->execute()
      ->fetchAllAssoc('migration_id')[$this->id];
    $this->lastImportDuration = $after = $fresh_flags['last_import_duration'];
    if ($before != $after) {
      \Drupal::logger('acquia_migrate')->debug('last_import_duration race condition work-around saved the day again: instead of ' . (string) $before . ' it now is ' . (string) $after . ' for the @migration-id migration.', ['@migration-id' => $this->id]);
    }
    // @codingStandardsIgnoreEnd
    return $this->lastImportDuration;
  }

  /**
   * Get state-dependent URLs for the migration.
   *
   * @return array[string]\Drupal\Core\Url
   *   An array of link URLs.
   */
  protected function getAvailableLinkUrls() : array {
    // This may be called repeatedly within a request, but the service will not
    // change. Hence this is safe to statically cache.
    static $previewer;
    if (!isset($previewer)) {
      $previewer = \Drupal::service('acquia_migrate.previewer');
      assert($previewer instanceof MigrationPreviewer);
    }

    $urls = [];

    $update_resource_url = Url::fromRoute('acquia_migrate.api.migration.patch')
      ->setRouteParameter('migration', $this->id());

    // If this migration is not idle, do not allow any other activities, except
    // stopping the current activity.
    if ($this->getActivity() !== self::ACTIVITY_IDLE) {
      $urls['stop'] = $update_resource_url;
      return $urls;
    }

    // Until preselections have been made, only preselectable migrations should
    // have skip/unskip links. This unintuitive boolean order exists to prevent
    // unnecessary loading and querying of the repository service.
    $provide_skip_links = $this->isPreselectable() || \Drupal::service('acquia_migrate.migration_repository')->migrationsHaveBeenPreselected();

    // Handle the "skipped" migration flag first, because a skipped migration
    // only has a single action available: unskipping.
    if ($provide_skip_links && $this->isSkipped()) {
      $urls['unskip'] = $update_resource_url;
      return $urls;
    }

    if (!$this->allRowsProcessed() && $this->isImportable()) {
      $urls['import'] = Url::fromRoute('acquia_migrate.api.migration.import')->setOption('query', [
        'migrationId' => $this->id(),
      ]);
    }

    if ($this->canBeRolledBack()) {
      $urls['rollback'] = Url::fromRoute('acquia_migrate.api.migration.rollback')->setOption('query', [
        'migrationId' => $this->id(),
      ]);
      $urls['rollback-and-import'] = Url::fromRoute('acquia_migrate.api.migration.rollback_import')->setOption('query', [
        'migrationId' => $this->id(),
      ]);
    }

    if ($this->isStale() && $this->isImportable()) {
      $urls['refresh'] = Url::fromRoute('acquia_migrate.api.migration.refresh')->setOption('query', [
        'migrationId' => $this->id(),
      ]);
    }

    if ($this->allRowsProcessed()) {
      if (!$this->isCompleted()) {
        $urls['complete'] = $update_resource_url;
      }
      else {
        $urls['uncomplete'] = $update_resource_url;
      }
    }

    // It does not make sense to mark an already completed migration as skipped.
    if ($provide_skip_links && !$this->isSkipped() && !$this->isCompleted()) {
      $urls['skip'] = $update_resource_url;
    }

    if ($previewer->isPreviewableMigration($this)) {
      $unmet_requirements = [];
      // There's nothing to preview if not all supporting configuration has been
      // processed.
      if ($previewer->isReadyForPreview($this)) {
        $unmet_requirements[] = 'https://github.com/acquia/acquia_migrate#application-concept-no-unprocessed-supporting-configuration';
      }

      // If not all requirements are met, then for example previewing an
      // article might fail because its author has not yet been migrated.
      if (!empty($unmet_requirements)) {
        for ($i = 0; $i < count($unmet_requirements); $i++) {
          $urls["preview-unmet-requirement:$i"] = Url::fromUri($unmet_requirements[$i]);
        }
      }
      else {
        $urls['preview-by-offset'] = Url::fromRoute('acquia_migrate.api.migration.preview')
          ->setRouteParameter('migration', $this->id());
        $urls['preview-by-url'] = Url::fromRoute('acquia_migrate.api.migration.preview')
          ->setRouteParameter('migration', $this->id());
      }
    }

    if ($previewer->isPreviewableMigration($this)) {
      $urls['field-mapping'] = Url::fromRoute('acquia_migrate.api.migration.mapping')
        ->setRouteParameter('migration', $this->id());
    }

    return $urls;
  }

  /**
   * Whether this migration is capable of being rolled back.
   *
   * @return bool
   *   TRUE if a migration has processed at least 1 row and all of its migration
   *   plugins can be rolled back, FALSE otherwise.
   */
  protected function canBeRolledBack() : bool {
    return $this->supportsRollback && $this->getProcessedCount() > 0;
  }

  /**
   * Whether all (internal) migration plugins have been processed.
   *
   * @return bool
   *   Whether all migration plugins in this migration have been processed.
   */
  public function allRowsProcessed() {
    if (!isset($this->allRowsProcessed)) {
      $all_rows_processed = TRUE;
      foreach ($this->migrationPlugins as $migration_plugin) {
        $all_rows_processed = $all_rows_processed && $migration_plugin->allRowsProcessed();
        if (!$all_rows_processed) {
          break;
        }
      }
      $this->allRowsProcessed = $all_rows_processed;
    }

    return $this->allRowsProcessed;
  }

  /**
   * Whether all dependent (external) migration plugins have been processed.
   *
   * @return bool
   *   Whether all dependent migration plugins in other migrations have been
   *   processed.
   */
  public function allDependencyRowsProcessed() {
    if (empty($this->dependencies)) {
      return TRUE;
    }

    $all_dependency_rows_processed = TRUE;
    foreach ($this->dependencies as $migration_plugin_dependencies) {
      foreach ($migration_plugin_dependencies as $migration_plugin) {
        /** @var \Drupal\migrate\Plugin\Migration $migration_plugin */
        // This is a partial reimplementation of
        // \Drupal\migrate\Plugin\Migration::allRowsProcessed() to avoid
        // retrieving the source count twice. We have an extra requirement that
        // not only all rows have been processed, but that a minimum ratio has
        // been imported (or explicitly skipped).
        $id_map = $migration_plugin->getIdMap();
        $source_count = $migration_plugin->getSourcePlugin()->count();
        $processed_count = $id_map->processedCount();
        // TRICKY: "imported or skipped" = "total" - "error".
        // @see \Drupal\migrate\Plugin\MigrateIdMapInterface::STATUS_*
        $imported_or_skipped_count = $source_count - $id_map->errorCount();
        $all_dependency_rows_processed = $all_dependency_rows_processed && (
          $source_count <= 0
          || ($source_count <= $processed_count && $imported_or_skipped_count / $source_count >= self::MINIMUM_IMPORT_RATIO)
        );
        if (!$all_dependency_rows_processed) {
          break;
        }
      }
    }
    return $all_dependency_rows_processed;
  }

  /**
   * Gets all migration plugins that provide supporting configuration.
   *
   * @return array
   *   An array of key-value pairs of migrations containing supporting
   *   configuration that this migration depends upon. Keys are migration plugin
   *   IDs, values are the corresponding migrations.
   *
   * @see ::allDependencyRowsProcessed()
   * @see \Drupal\acquia_migrate\MigrationRepository::getInitialMigrationPluginIds()
   */
  public function getSupportingConfigurationMigrationPluginIds() : array {
    // 1. supporting configuration migration plugin IDs *within* this migration.
    $non_data_migration_plugin_ids = array_diff($this->getMigrationPluginIds(), $this->getDataMigrationPluginIds());
    $supporting_config_migration_plugin_ids = array_fill_keys($non_data_migration_plugin_ids, $this->id());

    // Return early if there are no dependencies.
    if (empty($this->dependencies)) {
      return $supporting_config_migration_plugin_ids;
    }

    $repository = \Drupal::service('acquia_migrate.migration_repository');
    assert($repository instanceof MigrationRepository);
    $initial_migration_plugin_ids = $repository->getInitialMigrationPluginIds();

    // 2. supporting configuration migration plugin IDs in dependencies.
    foreach ($this->dependencies as $migration_id => $migration_plugin_dependencies) {
      foreach ($migration_plugin_dependencies as $migration_plugin_id => $migration_plugin) {
        // Only consider migration plugins within the dependencies that migrate
        // supporting configuration.
        if (!in_array($migration_plugin_id, $initial_migration_plugin_ids, TRUE)) {
          continue;
        }
        $supporting_config_migration_plugin_ids[$migration_plugin_id] = $migration_id;
      }
    }
    return $supporting_config_migration_plugin_ids;
  }

  /**
   * Returns subset of supp. conf. mig. plugins: those with rows to process.
   *
   * @return array
   *   An array of key-value pairs of migrations containing supporting
   *   configuration that this migration depends upon. Keys are migration plugin
   *   IDs, values are the corresponding migrations.
   *
   * @see ::allDependencyRowsProcessed()
   * @see \Drupal\acquia_migrate\MigrationRepository::getInitialMigrationPluginIds()
   * @see \Drupal\acquia_migrate\MigrationRepository::getInitialMigrationPluginIdsWithRowsToProcess()
   */
  public function getSupportingConfigurationMigrationsPluginIdsWithRowsToProcess() : array {
    $supporting_config_migration_plugin_ids = $this->getSupportingConfigurationMigrationPluginIds();

    $repository = \Drupal::service('acquia_migrate.migration_repository');
    assert($repository instanceof MigrationRepository);
    $todo = $repository->getInitialMigrationPluginIdsWithRowsToProcess();

    return array_intersect_key($supporting_config_migration_plugin_ids, array_combine($todo, $todo));
  }

  /**
   * Whether this migration is importable.
   *
   * @return bool
   *   Whether this migration is importable, which requires the requirements of
   *   all internal migration plugins to be met plus all (external) migration
   *   plugins this migration depends on (in other migrations) must have been
   *   run.
   *
   * @see \Drupal\migrate\Plugin\Migration::checkRequirements()
   * @see \Drupal\acquia_migrate\MigrationClusterer::getAvailableMigrations()
   */
  private function isImportable() {
    // The simplest possible implementation here would be to call
    // \Drupal\migrate\Plugin\Migration::checkRequirements(). But that would
    // repeat source & destination requirements checks that
    // \Drupal\acquia_migrate\MigrationClusterer::getAvailableMigrations()
    // already performed, at this point we only need to check if the required
    // migration dependencies have finished running!
    return $this->allDependencyRowsProcessed();
  }

  /**
   * Gets the current activity of the migration.
   *
   * @return string
   *   Either:
   *   - Migration::ACTIVITY_IDLE
   *   - Migration::ACTIVITY_IMPORTING
   *   - Migration::ACTIVITY_ROLLING_BACK
   *   - Migration::ACTIVITY_REFRESHING
   */
  public function getActivity() : string {
    $max_migration_plugin_status = array_reduce($this->dataMigrationPluginIds, function (int $max, string $id) {
      $max = max($max, $this->migrationPlugins[$id]->getStatus());
      return $max;
    }, MigrationInterface::STATUS_IDLE);

    switch ($max_migration_plugin_status) {
      case MigrationInterface::STATUS_IMPORTING:
        // Refreshing is a special case of importing as far as the migration
        // system is concerned. Therefore we need to carefully detect this. Note
        // this is specifically not using ::getUiProcecessedCount()!
        // @see ::getProcessedCount()
        // @see ::getUiProcessedCount()
        if ($this->getProcessedCount() == $this->getTotalCount()) {
          return self::ACTIVITY_REFRESHING;
        }
        return self::ACTIVITY_IMPORTING;

      case MigrationInterface::STATUS_ROLLING_BACK:
        return self::ACTIVITY_ROLLING_BACK;

      default:
        // Note that MigrationInterface::STATUS_STOPPING is irrelevant to us,
        // since it is used by MigrateUpgradeImportBatch to signal the end of
        // the processing within a single batch request. It is also used for
        // instantaneously triggering a stop.
        // @see \Drupal\acquia_migrate\EventSubscriber\InstantaneousBatchInterruptor::interruptMigrateExecutable
        // Note that MigrationInterface::STATUS_DISABLED is irrelevant to us,
        // since we do not use this functionality and it does not actually
        // reflect an activity.
        return self::ACTIVITY_IDLE;
    }
  }

  /**
   * Maps a Migration object to JSON:API resource object array.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   A migration to map.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   An object capable of capturing any cacheability metadata generated by the
   *   normalization of the given migration into a resource object.
   *
   * @return array
   *   A JSON:API resource object array.
   */
  public static function toResourceObject(Migration $migration, RefinableCacheableDependencyInterface $cacheability) : array {
    Timer::start(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION);
    Timer::start(__CLASS__ . __METHOD__ . $migration->id());

    // This may be called repeatedly within a request, but the service will not
    // change. Hence this is safe to statically cache.
    static $previewer;
    if (!isset($previewer)) {
      $previewer = \Drupal::service('acquia_migrate.previewer');
      assert($previewer instanceof MigrationPreviewer);
    }

    Timer::start(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_ATTRIBUTES);
    $dependencies_relationship_data = array_map(function (string $migration_id, array $reasons) {
      return [
        'type' => 'migration',
        'id' => $migration_id,
        'meta' => [
          'dependencyReasons' => $reasons,
        ],
      ];
    }, $migration->getDependencies(), $migration->getDependenciesWithReasons());

    $data_migration_plugin_ids = $migration->getDataMigrationPluginIds();
    $consists_of_relationship_data = array_map(function (string $migration_plugin_id) use ($data_migration_plugin_ids, $migration) {
      $suffix = '';
      if (in_array($migration_plugin_id, $data_migration_plugin_ids)) {
        $migration_plugin = $migration->getMigrationPluginInstances()[$migration_plugin_id];
        $source_records_count = $migration_plugin->getSourcePlugin()->count();
        $plugin_idmap = $migration_plugin->getIdMap();
        // Imported count also contains fully imported records and records which
        // need an update (e.g stubs).
        $fully_imported_count = $plugin_idmap->importedCount() - $plugin_idmap->updateCount();
        $suffix = " ($fully_imported_count of $source_records_count)";
      }

      return [
        'type' => 'migrationPlugin',
        'id' => $migration_plugin_id . $suffix,
      ];
    }, $migration->getMigrationPluginIds());

    $self_url = Url::fromRoute('acquia_migrate.api.migration.get')
      ->setRouteParameter('migration', $migration->id())
      ->setAbsolute()
      ->toString(TRUE);
    $cacheability->addCacheableDependency($self_url);

    $resource_object = [
      'type' => 'migration',
      'id' => $migration->id(),
      'attributes' => [
        'label' => $migration->label(),
        'importedCount' => $migration->getUiImportedCount(),
        'processedCount' => $migration->getUiProcessedCount(),
        'totalCount' => $migration->getTotalCount(),
        'completed' => $migration->isCompleted(),
        'stale' => $migration->isStale(),
        'skipped' => $migration->isSkipped(),
        'lastImported' => NULL,
        'activity' => $migration->getActivity(),
      ],
      'relationships' => [
        'dependencies' => [
          'data' => $dependencies_relationship_data,
        ],
        'consistsOf' => [
          'data' => $consists_of_relationship_data,
        ],
      ],
      'links' => [
        'self' => [
          'href' => $self_url->getGeneratedUrl(),
        ],
      ],
    ];

    if ($migration->isImported()) {
      $end_time = NULL;
      if ($migration->getLastImportDuration() !== NULL) {
        $end_time = (new \DateTime())->setTimestamp($migration->getLastImportTimestamp() + $migration->getLastImportDuration())->setTimezone(new \DateTimeZone('UTC'))->format(\DateTime::RFC3339);
      }
      $resource_object['attributes']['lastImported'] = [
        'startTime' => (new \DateTime())->setTimestamp($migration->getLastImportTimestamp())->setTimezone(new \DateTimeZone('UTC'))->format(\DateTime::RFC3339),
        'endTime' => $end_time,
        'duration' => $migration->getLastImportDuration(),
      ];
    }

    if ($migration->getMessageCount() > 0) {
      $messages_route_url = Url::fromRoute('acquia_migrate.migrations.messages')
        ->setOption('query', [
          'filter' => implode(',', [
            ':eq',
            SqlWithCentralizedMessageStorage::COLUMN_MIGRATION_ID,
            $migration->id(),
          ]),
        ])
        ->setAbsolute()
        ->toString(TRUE);
      $cacheability->addCacheableDependency($messages_route_url);
      $resource_object['links']['migration-messages'] = [
        'href' => $messages_route_url->getGeneratedUrl(),
        'rel' => UriDefinitions::LINK_REL_MIGRATION_MESSAGES,
        'type' => 'text/html',
        'title' => t("@messageCount", [
          '@messageCount' => $migration->getMessageCount(),
        ]),
      ];
    }
    Timer::stop(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_ATTRIBUTES);

    // Get links to act on this migration based on the it current state. E.g. a
    // migration that doesn't have any imported data will not have a 'rollback'
    // link.
    Timer::start(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_LINKS);
    $available_urls = $migration->getAvailableLinkUrls();
    Timer::stop(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_LINKS);

    foreach ($available_urls as $key => $url) {
      assert($url instanceof Url);
      $link_params = [];
      switch ($key) {
        case 'import':
          $link_rel = UriDefinitions::LINK_REL_START_BATCH_PROCESS;
          $link_title = t('Import');
          break;

        case 'rollback':
          $link_rel = UriDefinitions::LINK_REL_START_BATCH_PROCESS;
          $link_title = t('Rollback');
          break;

        case 'rollback-and-import':
          $link_rel = UriDefinitions::LINK_REL_START_BATCH_PROCESS;
          $link_title = t('Rollback and import');
          break;

        case 'refresh':
          $link_rel = UriDefinitions::LINK_REL_START_BATCH_PROCESS;
          $link_title = t('Refresh');
          break;

        case 'stop':
          $link_rel = UriDefinitions::LINK_REL_UPDATE_RESOURCE;
          $link_title = t('Stop operation');
          $link_params = [
            'data' => [
              'type' => 'migration',
              'id' => $migration->id(),
              'attributes' => [
                'activity' => 'idle',
              ],
            ],
          ];
          break;

        case 'complete':
          $link_rel = UriDefinitions::LINK_REL_UPDATE_RESOURCE;
          $link_title = t('Mark as completed');
          $link_params = [
            'confirm' => t("I'm sure, I'm ready with this migration, at least for now."),
            'data' => [
              'type' => 'migration',
              'id' => $migration->id(),
              'attributes' => [
                'completed' => TRUE,
              ],
            ],
          ];
          break;

        case 'uncomplete':
          $link_rel = UriDefinitions::LINK_REL_UPDATE_RESOURCE;
          $link_title = t('Unmark as completed');
          $link_params = [
            'confirm' => FALSE,
            'data' => [
              'type' => 'migration',
              'id' => $migration->id(),
              'attributes' => [
                'completed' => FALSE,
              ],
            ],
          ];
          break;

        case 'skip':
          $link_rel = UriDefinitions::LINK_REL_UPDATE_RESOURCE;
          $link_title = t('Skip');
          $link_params = [
            'confirm' => t("I'm sure, this does not need to be migrated, at least not for now."),
            'data' => [
              'type' => 'migration',
              'id' => $migration->id(),
              'attributes' => [
                'skipped' => TRUE,
              ],
            ],
          ];
          break;

        case 'unskip':
          $link_rel = UriDefinitions::LINK_REL_UPDATE_RESOURCE;
          $link_title = t('Unskip');
          $link_params = [
            'confirm' => FALSE,
            'data' => [
              'type' => 'migration',
              'id' => $migration->id(),
              'attributes' => [
                'skipped' => FALSE,
              ],
            ],
          ];
          break;

        case 'preview-by-offset':
          $link_rel = UriDefinitions::LINK_REL_PREVIEW;
          $link_title = t('Preview first row');
          $url->setOption('query', ['byOffset' => 0]);
          break;

        case 'preview-by-url':
          $link_rel = UriDefinitions::LINK_REL_PREVIEW;
          $link_title = t('Preview by URL');
          $link_template = [
            'uri-template:href' => $url->setAbsolute()->toString(TRUE)->getGeneratedUrl() . '{?byUrl}',
            'uri-template:suggestions' => [
              [
                'label' => t('By source site URL'),
                'variable' => urlencode('byUrl'),
                'cardinality' => 1,
              ],
            ],
            'rel' => UriDefinitions::LINK_REL_PREVIEW,
          ];
          break;

        case 'preview-unmet-requirement:0':
        case 'preview-unmet-requirement:1':
          $link_rel = UriDefinitions::LINK_REL_UNMET_REQUIREMENT;
          if ($url->getUri() === 'https://github.com/acquia/acquia_migrate#application-concept-no-unprocessed-supporting-configuration') {
            $exception = $previewer->isReadyForPreview($migration);
            // This is guaranteed to return an exception.
            // @see \Drupal\acquia_migrate\Migration::getAvailableLinkUrls()
            if (!($exception instanceof RowPreviewException)) {
              throw new \LogicException();
            };
            $link_title = $exception->getMessage();
          }
          else {
            throw new \InvalidArgumentException();
          }
          break;

        case 'field-mapping':
          $link_rel = UriDefinitions::LINK_REL_MAPPING;
          $link_title = t('View mapping');
          $link_template = [];
          break;

        default:
          assert(FALSE, 'Unrecognized migration action: ' . $key);
          $link_rel = $key;
          $link_title = $key;
      }
      $link = $url->setAbsolute()->toString(TRUE);
      assert($link instanceof GeneratedUrl);
      $cacheability->addCacheableDependency($link);
      $resource_object['links'][$key] = [
        'href' => $link->getGeneratedUrl(),
        'title' => $link_title,
        'rel' => $link_rel,
      ];
      if (!empty($link_params)) {
        $resource_object['links'][$key]['params'] = $link_params;
      }
      if (!empty($link_template)) {
        $resource_object['links'][$key] += $link_template;
      }
    }

    Timer::stop(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION);
    $duration = Timer::stop(__CLASS__ . __METHOD__ . $migration->id())['time'];
    if ($duration > 500) {
      \Drupal::service('logger.channel.acquia_migrate_profiling_statistics')
        ->info(
          sprintf("stats_type=jsonapi_resource_object_migration|migration_id=%s|duration=%d|links=%s",
            $migration->id(),
            round($duration),
            implode(',', array_keys($resource_object['links']))
          )
        );
    }

    return $resource_object;
  }

}
