<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\acquia_migrate\Migration;
use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\Recommendations;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\migrate\Plugin\migrate\id_map\NullIdMap;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigratePluginManagerInterface;
use Drupal\migrate\Plugin\MigrateSourceInterface;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Row;
use Drush\Commands\DrushCommands;

/**
 * Acquia Migrate drush commands.
 */
final class AcquiaMigrateCommands extends DrushCommands {

  /**
   * The migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $migrationRepository;

  /**
   * Key-value store service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The Acquia Migrate: Accelerate recommendations.
   *
   * @var \Drupal\acquia_migrate\Recommendations
   */
  protected $recommendations;

  /**
   * The migration source plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigratePluginManagerInterface
   */
  protected $sourcePluginManager;

  /**
   * AcquiaMigrateCommands constructor.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $migration_repository
   *   The migration repository.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value
   *   Key-value store service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\acquia_migrate\Recommendations $recommendations
   *   The Acquia Migrate: Accelerate recommendations.
   * @param \Drupal\migrate\Plugin\MigratePluginManagerInterface $source_plugin_manager
   *   The migration source plugin manager.
   */
  public function __construct(MigrationRepository $migration_repository, KeyValueFactoryInterface $key_value, ModuleExtensionList $module_extension_list, Recommendations $recommendations, MigratePluginManagerInterface $source_plugin_manager) {
    parent::__construct();
    $this->migrationRepository = $migration_repository;
    $this->keyValue = $key_value;
    $this->moduleExtensionList = $module_extension_list;
    $this->recommendations = $recommendations;
    $this->sourcePluginManager = $source_plugin_manager;
  }

  /**
   * Audits installed modules.
   *
   * @command ama:module-audit
   * @filter-output
   *
   * @option installed Installed modules only. Default.
   * @option risky
   *
   * @default $options []
   *
   * @usage ama:module-audit
   *   Audits all installed modules.
   * @usage ama:module-audit --risky-only
   *   Returns only the risky modules.
   * @usage ama:module-audit --filter="risk!="
   *   Alias of --risky-only
   * @usage ama:module-audit --filter="risk~=/medium|high/"
   *   Returns only medium and high risk modules.
   * @usage ama:module-audit --installed=0
   *   Returns only modules that are not installed.
   * @usage ama:module-audit --filter="installed!="
   *   Alias of --installed=0.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amama, ama-module-audit
   *
   * @field-labels
   *   module: Module
   *   vetted: Vetted
   *   stable: Stable
   *   has_migrations: Has Migrations
   *   alters_migrations: Alters Migrations
   *   risk: Risk
   *   installed: Installed
   *   installation_time: Installation time
   * @default-fields module,vetted,stable,has_migrations,alters_migrations,risk,installed,installation_time
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations status formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function auditModules($options = [
    'installed' => TRUE,
    'risky' => self::OPT,
  ]) : RowsOfFields {
    $table = [];

    $vetted = $this->recommendations->getVettedDestinationModules();

    foreach ($this->moduleExtensionList->getList() as $module) {
      // Always ignore test-only modules.
      if ($module->info['package'] === 'Testing') {
        continue;
      }

      $installed = $module->status ? 'yes' : FALSE;
      if ($options['installed'] && !$installed) {
        continue;
      }

      // This assumes the recommended version of the vetted module is used.
      $is_vetted = in_array($module->getName(), $vetted);
      $has_migrations = $this->recommendations::moduleHasMigrations($module);
      $alters_migrations = $this->recommendations::moduleAltersMigrations($module);
      $is_stable = $this->recommendations::moduleIsStable($module);
      $risk = $this->recommendations::assessModuleInstallationMigrationBreakingRisk($is_vetted, $has_migrations, $alters_migrations, $is_stable);

      if ($options['risky'] && $risk === NULL) {
        continue;
      }

      $key = "module_install_timestamp:" . $module->getName();
      $installation_time = $this->keyValue->get('acquia_migrate')->get($key, NULL);
      $row = [
        'module' => $module->getName(),
        'installed' => $installed,
        'vetted' => $is_vetted ? 'yes' : FALSE,
        'stable' => $is_stable ? 'yes' : FALSE,
        'has_migrations' => $has_migrations ? 'yes' : FALSE,
        'alters_migrations' => $alters_migrations ? 'yes' : FALSE,
        'risk' => $risk,
        'installation_time' => $installation_time === NULL ? FALSE : (new \DateTime())->setTimestamp($installation_time)->format(\DateTime::RFC3339),
      ];

      $table[] = $row;
    }
    return new RowsOfFields($table);
  }

  /**
   * Lists remaining rows.
   *
   * @param string $migration_label_or_id
   *   A migration label or ID.
   * @param array $options
   *   The options to pass.
   *
   * @command ama:remaining-rows
   * @filter-output
   *
   * @option unprocessed-only
   * @option processed-only
   *
   * @default $options []
   *
   * @usage ama:remaining-rows
   *   Audits all remaining source rows in a migration.
   * @usage ama:module-audit --unprocessed-only
   *   Returns only the unprocessed source rows (below the high water property).
   * @usage ama:module-audit --processed-only
   *   Returns only the processed but unimported source rows.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amarr, ama-remaining-rows
   *
   * @field-labels
   *   migration_plugin_id: Migration Plugin ID
   *   source_id: Source ID
   *   assessment: Assessment
   *   belowhighwater: Below High Water
   * @default-fields migration_plugin_id,source_id,assessment,belowhighwater
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations' remaining rows formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function remainingRows(string $migration_label_or_id, array $options = [
    'unprocessed-only' => self::OPT,
    'processed-only' => self::OPT,
  ]) {
    if ($options['processed-only'] && $options['unprocessed-only']) {
      throw new \Exception('Cannot use both --processed-only and --processed-only. Use either or neither, not both.');
    }

    $migration_id = Migration::isValidMigrationId($migration_label_or_id)
      ? $migration_label_or_id
      : Migration::generateIdFromLabel($migration_label_or_id);

    $migration = $this->migrationRepository->getMigration($migration_id);
    assert($migration instanceof Migration);
    $data_migration_plugin_ids = $migration->getDataMigrationPluginIds();
    $this->say(dt('Analyzing @count data migration plugins for remaining rows. Unprocessed rows require a complete scan, to cross-reference the complete set of source rows against the migrate ID mapping.', ['@count' => count($data_migration_plugin_ids)]));

    $table = [];

    $index = 0;
    foreach ($data_migration_plugin_ids as $migration_plugin_id) {
      $index++;
      $migration_plugin = $migration->getMigrationPluginInstances()[$migration_plugin_id];
      $definition = $migration_plugin->getPluginDefinition();

      // High water metadata.
      $source_configuration = $definition['source'];
      $high_water_property = array_key_exists('high_water_property', $source_configuration)
        ? $source_configuration['high_water_property']['name']
        : NULL;
      $high_water_mark = $this->getHighWater($migration_plugin_id);

      $source_plugin = $this->getAnalysisOnlySourcePluginFor($migration_plugin);

      try {
        $map = $migration_plugin->getIdMap();
        assert($map instanceof MigrateIdMapInterface);
        $imported_count = $map->importedCount();
      }
      catch (\Exception $e) {
        $error = dt(
          'Failure retrieving information on @migration: @message',
          ['@migration' => $migration_id, '@message' => $e->getMessage()]
        );
        $this->logger()->error($error);
        continue;
      }

      try {
        $total_count = $source_plugin->count();
        // -1 indicates uncountable sources.
        if ($total_count != -1) {
          $unprocessed_count = $total_count - $map->processedCount();
        }
        else {
          $unprocessed_count = NULL;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error(
          dt(
            'Could not retrieve source count from @migration: @message',
            ['@migration' => $migration_id, '@message' => $e->getMessage()]
          )
        );
        continue;
      }

      // Gather the set of processed but unimported rows. This is efficient.
      if (!$options['unprocessed-only'] && ($total_count == -1 || $imported_count < $total_count - $unprocessed_count)) {
        $processed_remaining_rows[$migration_plugin_id] = $map->getUnimportedRows();
        foreach ($processed_remaining_rows[$migration_plugin_id] as $row) {
          $table[] = [
            'migration_plugin_id' => $migration_plugin_id,
            'source_id' => $row->sourceid1,
            'assessment' => static::assessProcessedRow($row),
            'belowhighwater' => NULL,
          ];
        }
      }

      if ($options['processed-only']) {
        continue;
      }

      if ($unprocessed_count === $total_count) {
        $this->say(dt('[@index/@total] %migration-plugin-id has no processed rows, no need to scan.', [
          '@index' => $index,
          '@total' => count($data_migration_plugin_ids),
          '%migration-plugin-id' => $migration_plugin_id,
        ]));
      }
      elseif ($unprocessed_count === 0) {
        $this->say(dt('[@index/@total] %migration-plugin-id has no unprocessed rows, no need to scan.', [
          '@index' => $index,
          '@total' => count($data_migration_plugin_ids),
          '%migration-plugin-id' => $migration_plugin_id,
        ]));
      }
      // Gather the set of unprocessed rows. This is inefficient. (It is
      // inefficient because it requires looking up data in both the source DB
      // and the destination DB, which usually cannot be joined.)
      else {
        $unprocessed_remaining_rows = [];
        try {
          $this->say(dt('[@index/@total] %migration-plugin-id has @count unprocessed rows, scanning…', [
            '@count' => $unprocessed_count,
            '@index' => $index,
            '@total' => count($data_migration_plugin_ids),
            '%migration-plugin-id' => $migration_plugin_id,
          ]));
          $this->io()->progressStart($total_count);
          $source_plugin->rewind();
          while ($source_plugin->valid()) {
            $this->io()->progressAdvance();
            $row = $source_plugin->current();
            assert($row instanceof Row);
            $ids = $row->getSourceIdValues();
            if (static::isUnprocessedSourceRow($map, $ids)) {
              $unprocessed_remaining_rows[] = $row;
              $table[] = [
                'migration_plugin_id' => $migration_plugin_id,
                'source_id' => implode(':', $ids),
                'assessment' => 'unprocessed',
                // @see \Drupal\migrate\Plugin\migrate\source\SqlBase::initializeIterator
                'belowhighwater' => $high_water_mark !== NULL && (int) $row->getSourceProperty($high_water_property) <= $high_water_mark,
              ];
            }
            $source_plugin->next();
          }
          $this->io()->progressFinish();
        }
        catch (\Throwable $e) {
          $this->logger()->error(
            dt(
              'Failed while scanning for unprocessed rows in @migration: @message',
              ['@migration' => $migration_id, '@message' => $e->getMessage()]
            )
          );
          continue;
        }

        if (count($unprocessed_remaining_rows) === $unprocessed_count) {
          $this->logger()
            ->success(dt('Scan complete, found !unprocessed-count-found of !unprocessed-count-expected unprocessed rows.', [
              '!unprocessed-count-found' => count($unprocessed_remaining_rows),
              '!unprocessed-count-expected' => $unprocessed_count,
            ]));
        }
        else {
          $this->logger()
            ->error(dt('Scan complete, found !unprocessed-count-found of !unprocessed-count-expected unprocessed rows.', [
              '!unprocessed-count-found' => count($unprocessed_remaining_rows),
              '!unprocessed-count-expected' => $unprocessed_count,
            ]));
        }
        $this->say(dt('ℹ️  %migration-plugin-id @does-or-not use high_water_property. It is currently at @current-high-water-property.', [
          '%migration-plugin-id' => $migration_plugin_id,
          '@does-or-not' => $migration_plugin->getSourceConfiguration('high_water_property') ? 'DOES' : 'does NOT',
          '@current-high-water-property' => static::getHighWater($migration_plugin_id),
        ]));
      }
    }
    return new RowsOfFields($table);
  }

  /**
   * Gets the analysis-only instantiation of the source plugin for a migration.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin instance whose source plugin to get an analysis-only
   *   instantiation for.
   *
   * @return \Drupal\migrate\Plugin\MigrateSourceInterface
   *   The analysis-only source plugin for the given migration plugin.
   */
  private function getAnalysisOnlySourcePluginFor(MigrationPlugin $migration_plugin) : MigrateSourceInterface {
    $definition = $migration_plugin->getPluginDefinition();

    // Also ensure there is no query alteration for a high water property.
    if ($definition['source']['high_water_property']) {
      unset($definition['source']['high_water_property']);
    }

    // Analyses should look at uncached data.
    if ($definition['source']['cache_counts']) {
      unset($definition['source']['cache_counts']);
    }

    $source_plugin = $this->sourcePluginManager->createInstance(
      $definition['source']['plugin'],
      $definition['source'],
      // Ensure that there _is_ no map data, to be able to analyze every source
      // row without side effects. This also ensures the map is not joinable, so
      // there is no need to set `ignore_map`.
      // @see \Drupal\migrate\Plugin\migrate\source\SqlBase::initializeIterator()
      new NullMigration()
    );
    assert($source_plugin instanceof MigrateSourceInterface);
    return $source_plugin;
  }

  /**
   * Checks whether a source row exists in the given ID map plugin instance.
   *
   * @param \Drupal\migrate\Plugin\MigrateIdMapInterface $map
   *   Map plugin.
   * @param array $source_id_values
   *   The source identifier keyed values of the record, e.g. ['nid' => 5].
   *
   * @return bool
   *   Whether the source row is unprocessed or not.
   */
  private static function isUnprocessedSourceRow(MigrateIdMapInterface $map, array $source_id_values) : bool {
    $raw_row = $map->getRowBySource($source_id_values);
    $is_unprocessed = $raw_row['source_row_status'] === NULL;
    return $is_unprocessed;
  }

  /**
   * Assesses a processed row.
   *
   * @param object $row
   *   A raw row from SqlWithCentralizedMessageStorage::getUnimportedRows().
   *
   * @return string
   *   Whether the source row is unprocessed or not.
   *
   * @throws \Exception
   *   When an unknown migrate map status is encountered.
   *
   * @see \Drupal\acquia_migrate\Plugin\migrate\id_map\SqlWithCentralizedMessageStorage::getUnimportedRows()
   */
  private static function assessProcessedRow(\stdClass $row) : string {
    switch ($row->source_row_status) {
      case MigrateIdMapInterface::STATUS_IMPORTED:
        $assessment = 'imported';
        break;

      case MigrateIdMapInterface::STATUS_NEEDS_UPDATE:
        $assessment = 'needs_update';
        break;

      case MigrateIdMapInterface::STATUS_IGNORED:
        $assessment = 'ignored';
        break;

      case MigrateIdMapInterface::STATUS_FAILED:
        $assessment = 'failed';
        break;

      default:
        throw new \Exception('Unknown migration map status!');
    }
    return $assessment;
  }

  /**
   * The current value of the high water mark for the given migration plugin.
   *
   * The high water mark defines a timestamp stating the time the import was
   * last run. If the mark is set, only content with a higher timestamp will be
   * imported.
   *
   * @return int|null
   *   A Unix timestamp representing the high water mark, or NULL if no high
   *   water mark has been stored.
   *
   * @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::getHighWater
   */
  private function getHighWater(string $migration_plugin_id) : ?int {
    return (int) $this->keyValue->get('migrate:high_water')->get($migration_plugin_id, 0);
  }

}

/**
 * A "null" migration: one without a source and an empty ID map.
 */
final class NullMigration extends MigrationPlugin {

  /**
   * {@inheritdoc}
   */
  public function __construct() {
    // This is needed to avoid crashing SourcePluginBase.
    // @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::__construct()
    $this->idMapPlugin = new NullIdMap([], 'null', []);
  }

}
