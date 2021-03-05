<?php

namespace Drupal\acquia_migrate;

use Drupal\acquia_migrate\Clusterer\Heuristics\SharedEntityData;
use Drupal\acquia_migrate\Clusterer\MigrationClusterer;
use Drupal\acquia_migrate\EventSubscriber\ServerTimingHeaderForResponseSubscriber;
use Drupal\acquia_migrate\Exception\MissingSourceDatabaseException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;

/**
 * Provides the repository of available migrations.
 *
 * "migrations" as this module defines them are actually clusters of migrations.
 *
 * "migrations" as Drupal defines them are individual migration plugin
 * instances. Here they are annotated with cluster metadata so we can transform
 * them to "migrations" as this module defines them.
 *
 * @internal
 */
class MigrationRepository {

  /**
   * The cache ID to use when caching the computed migrations.
   *
   * @var string
   */
  const CID = 'acquia_migrate__migration_repository';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The migrate cache bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Whether any migration pre-selections were ever made.
   *
   * @var bool
   *   TRUE if any migration was even had a preselection made, regardless if it
   *   was chosen for initial import or not. FALSE if no user has yet made a
   *   decision about what to import initially.
   */
  protected $migrationsHaveBeenPreselected;

  /**
   * Constructs a new MigrationRepository.
   *
   * @param \Drupal\acquia_migrate\Clusterer\MigrationClusterer $clusterer
   *   The migration clusterer service.
   * @param \Drupal\Core\Database\Connection $connection
   *   A Database connection to use for reading migration messages.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The migrate cache bin.
   */
  public function __construct(MigrationClusterer $clusterer, Connection $connection, CacheBackendInterface $cache) {
    $this->clusterer = $clusterer;
    $this->connection = $connection;
    $this->cache = $cache;
  }

  /**
   * Gets a migration.
   *
   * @param string $migration_id
   *   A migration ID.
   *
   * @return \Drupal\acquia_migrate\Migration
   *   The requested migration, if it exists.
   *
   * @throws \InvalidArgumentException
   *   If the requested migration does not exist.
   */
  public function getMigration(string $migration_id) : Migration {
    $migrations = $this->getMigrations();

    if (!isset($migrations[$migration_id])) {
      throw new \InvalidArgumentException("Requested migration '$migration_id'' does not exist.");
    }

    return $migrations[$migration_id];
  }

  /**
   * Whether any migrations have been pre-selected for initial import.
   *
   * @return bool
   *   TRUE if any migration has been pre-selected, FALSE otherwise.
   */
  public function migrationsHaveBeenPreselected() : bool {
    if (!isset($this->migrationsHaveBeenPreselected)) {
      $preselected_count = intval($this->connection->select('acquia_migrate_migration_flags')
        ->isNotNull('preselection')
        ->countQuery()
        ->execute()
        ->fetchField());
      $this->migrationsHaveBeenPreselected = $preselected_count > 0;
    }
    return $this->migrationsHaveBeenPreselected;
  }

  /**
   * Gets all migration flags.
   *
   * @return array[object]
   *   Migration flags, keyed by migration ID.
   */
  public function getMigrationFlags() : array {
    return $this->connection->select('acquia_migrate_migration_flags', 'm')
      ->fields('m', [
        'migration_id',
        'completed',
        'skipped',
        'last_import_fingerprint',
        'last_computed_fingerprint',
        'last_import_timestamp',
        'last_import_duration',
      ])
      ->execute()
      ->fetchAllAssoc('migration_id');
  }

  /**
   * Gets all available migrations, in recommended execution order.
   *
   * Guarantees live results despite its caching.
   *
   * @return \Drupal\acquia_migrate\Migration[]
   *   The available migrations, keyed by ID.
   *
   * @throws \Drupal\acquia_migrate\Exception\MissingSourceDatabaseException
   *   Thrown if the user has not configured a migrate source database.
   *
   * @see \Drupal\acquia_migrate\Migration::__sleep()
   * @see \Drupal\acquia_migrate\Migration::__wakeup()
   */
  public function getMigrations(bool $reset = FALSE) : array {
    Timer::start(Timers::CACHE_MIGRATIONS);

    // Static caching added only for the to-JSON:API-normalization logic,
    // because it can potentially call this many times.
    // @see \Drupal\acquia_migrate\Migration::toResourceObject()
    static $migrations;
    if (!$reset && isset($migrations)) {
      Timer::stop(Timers::CACHE_MIGRATIONS);
      return $migrations;
    }

    $cached = $this->cache->get(static::CID);
    if ($reset || !$cached) {
      Timer::start(Timers::COMPUTE_MIGRATIONS);
      Database::startLog(Timers::COMPUTE_MIGRATIONS . '-dst', 'default');
      Database::startLog(Timers::COMPUTE_MIGRATIONS . '-src', 'migrate');
      $migrations = $this->doGetMigrations();
      $duration = Timer::stop(Timers::COMPUTE_MIGRATIONS)['time'];
      $dst_queries = Database::getLog(Timers::COMPUTE_MIGRATIONS . '-dst', 'default');
      ServerTimingHeaderForResponseSubscriber::trackQueryLog(Timers::COMPUTE_MIGRATIONS . '-dst', count($dst_queries));
      $src_queries = Database::getLog(Timers::COMPUTE_MIGRATIONS . '-src', 'migrate');
      ServerTimingHeaderForResponseSubscriber::trackQueryLog(Timers::COMPUTE_MIGRATIONS . '-src', count($src_queries));
      \Drupal::service('logger.channel.acquia_migrate_profiling_statistics')->info(
        sprintf("stats_type=migration_repository|migration_count=%d|duration=%d|query_count_dst_db=%d|query_count_src_db=%d",
          count($migrations),
          round($duration),
          count($dst_queries),
          count($src_queries)
        )
      );
      $this->cache->set(static::CID, $migrations, Cache::PERMANENT, ['migration_plugins']);
      // Also compute the virtual initial migration once, to ensure its flags
      // have also been populated.
      $this->computeVirtualInitialMigration();
    }
    else {
      $migrations = $cached->data;
    }

    Timer::stop(Timers::CACHE_MIGRATIONS);

    return $migrations;
  }

  /**
   * Returns all initial migration plugins: non-data migration plugins and deps.
   *
   * @return string[]
   *   A set of plugin IDs.
   */
  public function getInitialMigrationPluginIds() : array {
    // This may be called repeatedly within a request, but the computed value
    // will not change. Hence this is safe to statically cache.
    // @see Migration::getSupportingConfigurationMigrationsPluginIds()
    static $initial_migration_plugin_ids;
    if (!isset($initial_migration_plugin_ids)) {
      $initial_migration_plugin_ids = [];
    }
    else {
      return $initial_migration_plugin_ids;
    }

    Timer::start(Timers::COMPUTE_MIGRATION_PLUGINS_INITIAL);

    $migrations = $this->getMigrations();
    $all_instances = [];

    // Gather the list of all data migration plugin IDs in non-data-only
    // migrations, as well as all migration plugin IDs in "Shared data"
    // migrations. These migration plugins should be not be imported initially,
    // because they are for content entities with supporting configuration, and
    // we should never import content entity migrations automatically.
    // Note: inspecting the destination plugin seems more logical, except that
    // we do not have a reliable way to determine which entity destinations are
    // for content entity types.
    $data_migration_plugin_ids_of_content_entity_migrations = array_reduce($migrations, function (array $result, Migration $migration) {
      $is_shared_data_migration = strpos($migration->label(), 'Shared data for ') === 0;
      $is_data_only_migration = count($migration->getDataMigrationPluginIds()) == count($migration->getMigrationPluginIds());
      if ($is_shared_data_migration || !$is_data_only_migration) {
        $result = array_merge($result, $migration->getDataMigrationPluginIds());
      }
      return $result;
    }, []);

    foreach ($migrations as $migration) {
      $non_data_migration_plugin_ids = array_diff($migration->getMigrationPluginIds(), $migration->getDataMigrationPluginIds());

      // Also include all dependencies of non-data migration plugins.
      // For example, d7_field_instance:node:blog depends on d7_field:node, and
      // that is *not* a non-data migration plugin, so without this dependency
      // resolution, it would not be picked up.
      $dependencies = [];
      $instances = $migration->getMigrationPluginInstances();
      $all_instances += $instances;
      foreach ($non_data_migration_plugin_ids as $id) {
        $dependencies = array_merge($dependencies, array_diff($instances[$id]->getMigrationDependencies()['required'], $non_data_migration_plugin_ids, $initial_migration_plugin_ids, $data_migration_plugin_ids_of_content_entity_migrations));
      }

      // @todo Rename $shared_structure_migration_plugin_ids
      // @todo Remove the resolving of dependencies above; including the supporting-config-only migrations like we're doing here covers it all. But it will require adding  "Filter format configuration" to Migration::isSupportingConfigOnly().
      // @todo Rename this method
      $shared_structure_migration_plugin_ids = [];
      if ($migration->isSupportingConfigOnly()) {
        $shared_structure_migration_plugin_ids = array_diff($migration->getMigrationPluginIds(), $non_data_migration_plugin_ids, $initial_migration_plugin_ids);
      }

      $initial_migration_plugin_ids = array_merge($initial_migration_plugin_ids, array_unique($dependencies), $non_data_migration_plugin_ids, $shared_structure_migration_plugin_ids);
    }

    // Remove missing initial migration plugins and also those plugins whose
    // dependency is not an initial migration.
    // Right now the latter will be "d7_shortcut_set_users": this depends on
    // "d7_shortcut_set" and "d7_user". "d7_shortcut_set" considered as an
    // initial migration plugin, it is in the "$initial_migration_plugin_ids"
    // array. But "d7_user" is not an initial migration, so
    // "d7_shortcut_set_users" cannot be migrated.
    $initial_migration_plugin_ids = array_filter($initial_migration_plugin_ids, function (string $initial_plugin_id) use ($all_instances, $initial_migration_plugin_ids) {
      // The initial migration plugin is not present.
      if (!array_key_exists($initial_plugin_id, $all_instances)) {
        return FALSE;
      }
      foreach ($all_instances[$initial_plugin_id]->getMigrationDependencies()['required'] as $initial_plugin_requirement_plugin_id) {
        if (!in_array($initial_plugin_requirement_plugin_id, $initial_migration_plugin_ids, TRUE)) {
          // The current dependency of the actual initial migration plugin ID is
          // not an initial migration.
          return FALSE;
        }
      }

      return TRUE;
    });

    Timer::stop(Timers::COMPUTE_MIGRATION_PLUGINS_INITIAL);

    return $initial_migration_plugin_ids;
  }

  /**
   * Returns a subset of initial migration plugins: those with rows to process.
   *
   * @return string[]
   *   A set of plugin IDs.
   */
  public function getInitialMigrationPluginIdsWithRowsToProcess() : array {
    // This may be called repeatedly within a request, but the computed value
    // will not change. Hence this is safe to statically cache.
    // @see Migration::getSupportingConfigurationMigrationsPluginIdsWithRowsToProcess()
    static $todo;
    if (!isset($todo)) {
      $todo = [];
    }
    else {
      return $todo;
    }

    Timer::start(Timers::COMPUTE_MIGRATION_PLUGINS_INITIAL_TODO);

    $initial_migration_plugin_ids = $this->getInitialMigrationPluginIds();
    $migrations = $this->getMigrations();

    $all_instances = [];

    // All migration plugins in migrations which have finished importing do not
    // need any further processing. This metadata is tracked by acquia_migrate,
    // and is hence much faster to retrieve than invoking ::allRowsProcessed()
    // on an instance of every migration plugin.
    // IOW: this is a performance optimization because it lives in the critical
    // path.
    // @see \Drupal\acquia_migrate\Controller\HttpApi::migrationsCollection()
    $ignore = [];
    foreach ($migrations as $migration) {
      $all_instances += $migration->getMigrationPluginInstances();
      if ($migration->isImported()) {
        $ignore += array_combine($migration->getMigrationPluginIds(), $migration->getMigrationPluginIds());
      }
    }

    // Determine which of the initial migration plugins still have rows that
    // need to be processed.
    foreach (array_unique(array_diff($initial_migration_plugin_ids, $ignore)) as $id) {
      if (isset($all_instances[$id]) && !$all_instances[$id]->allRowsProcessed()) {
        $todo[] = $id;
      }
    }

    Timer::stop(Timers::COMPUTE_MIGRATION_PLUGINS_INITIAL_TODO);

    return $todo;
  }

  /**
   * Computes the list of initial migration plugins, in import order.
   *
   * TRICKY: we keep ::getInitialMigrationPluginIdsWithRowsToProcess() around
   * for the superior performance, we only use this virtual Migration object to
   * be able to standardize logging and tracking of import duration.
   *
   * @return string
   *   A list of initial migration plugin IDs, in import order.
   *
   * @todo Improve Migration::isSupportingConfigOnly() by stopping hardcoding it to specific patterns and moving the patterns into the specific heuristics.
   * @todo Try to refactor ::getInitialMigrationPluginIds() and ::getInitialMigrationPluginIdsWithRowsToProcess() away, and make it all rely on this computed virtual migration instead.
   * @todo Everywhere in this + related functions: s/initial/supportingConfig/
   */
  public function computeVirtualInitialMigration() : Migration {
    $inital_migration_plugin_ids = $this->getInitialMigrationPluginIds();

    $migrations = $this->getMigrations();
    $all_instances = [];
    foreach ($migrations as $migration) {
      $instances = $migration->getMigrationPluginInstances();
      $all_instances += $instances;
    }

    // We can trust that the required migration dependencies are listed in
    // the correct dependency order, because MigrationRepository does not
    // reorder the migration plugins it receives from the clusterer.
    $initial_migrations = array_intersect_key($all_instances, array_combine($inital_migration_plugin_ids, $inital_migration_plugin_ids));
    $ordered_initial_migration_plugin_ids = array_keys($initial_migrations);

    $virtual_migration_label = '_INITIAL_';
    $flags = $this->getMigrationFlags();
    $migration_id = Migration::generateIdFromLabel($virtual_migration_label);
    if (!isset($flags[$migration_id])) {
      // Insert default flags.
      $total_count = array_reduce($initial_migrations, function (int $sum, $initial_migration_plugin) {
        $sum += $initial_migration_plugin->getSourcePlugin()->count();
        return $sum;
      }, 0);
      $this->connection->insert('acquia_migrate_migration_flags')
        ->fields([
          'migration_id' => $migration_id,
          'last_computed_fingerprint' => sprintf("%d/%d", 0, $total_count),
          'last_import_fingerprint' => sprintf("%d/%d", 0, $total_count),
          'completed' => 0,
          'skipped' => 0,
        ])
        ->execute();
    }
    return new Migration(
      $migration_id,
      $virtual_migration_label,
      [],
      $initial_migrations,
      $ordered_initial_migration_plugin_ids,
      FALSE,
      FALSE,
      'n/a',
      'n/a',
      NULL,
      NULL
    );
  }

  /**
   * Gets all available migrations, in recommended execution order. Uncached.
   *
   * @return \Drupal\acquia_migrate\Migration[]
   *   The available migrations, keyed by ID.
   *
   * @throws \Drupal\acquia_migrate\Exception\MissingSourceDatabaseException
   *   Thrown if the user has not configured a migrate source database.
   */
  private function doGetMigrations() : array {
    if (!SourceDatabase::isConnected()) {
      throw new MissingSourceDatabaseException();
    }

    $migration_plugins = $this->clusterer->getClusteredMigrationPlugins();

    $flags = $this->getMigrationFlags();
    $first_time = [];
    $lookup_table = [];

    // First pass over migration plugins: generate the "migrations" in the
    // intended order: the order in which they will appear but ignoring any
    // assigned "LIFTED-*" — these are migration plugins that the key migration
    // plugin(s) in that cluster depend on but which should not affect the
    // migration order, since they are merely dependencies.
    $migration_info = [];
    foreach ($migration_plugins as $id => $migration_plugin) {
      $cluster = $migration_plugin->getMetadata('cluster');
      if (strpos($cluster, 'LIFTED-') === 0) {
        continue;
      }

      // Create a temporary data structure for this cluster if it doesn't exist
      // already.
      if (!isset($migration_info[$cluster])) {
        $migration_id = Migration::generateIdFromLabel($cluster);
        if (!isset($flags[$migration_id])) {
          // Insert default flags.
          $this->connection->insert('acquia_migrate_migration_flags')
            ->fields([
              'migration_id' => $migration_id,
              'completed' => 0,
              'skipped' => 0,
            ])
            ->execute();
          $first_time[] = $cluster;
          $flags = $this->getMigrationFlags();
        }
        $migration_info[$cluster] = [
          'id' => $migration_id,
          'label' => $cluster,
          'dependencies' => [],
          'migration_plugins' => [],
          'data_migration_plugin_ids' => [],
          'completed' => (bool) $flags[$migration_id]->completed,
          'skipped' => (bool) $flags[$migration_id]->skipped,
          'last_import_fingerprint' => $flags[$migration_id]->last_import_fingerprint,
          'last_computed_fingerprint' => $flags[$migration_id]->last_computed_fingerprint,
          'last_import_timestamp' => $flags[$migration_id]->last_import_timestamp ? (int) $flags[$migration_id]->last_import_timestamp : NULL,
          'last_import_duration' => $flags[$migration_id]->last_import_duration ? (int) $flags[$migration_id]->last_import_duration : NULL,
        ];
      }
    }

    // Second pass over migration plugins: assign them to the slots created in
    // $migration_info in the first pass.
    foreach ($migration_plugins as $id => $migration_plugin) {
      $cluster = $migration_plugin->getMetadata('cluster');
      $is_data_migration_plugin = TRUE;
      if (strpos($cluster, 'LIFTED-') === 0) {
        $cluster = substr($cluster, 7);
        // Treat lifted content migrations as data migrations. This is
        // particularly the case for Paragraphs-based content types.
        if (!in_array('Content', $migration_plugin->getMigrationTags(), TRUE)) {
          assert(!in_array(explode(PluginBase::DERIVATIVE_SEPARATOR, $id)[0], SharedEntityData::PARAGRAPHS_MIGRATION_BASE_PLUGIN_IDS, TRUE), sprintf('A non-paragraphs-based lifted content migration has been discovered: "%s"', $id));
          $is_data_migration_plugin = FALSE;
        }
      }

      $lookup_table[$id] = $migration_info[$cluster]['id'];

      // This Drupal migration is one of the "migration plugins" to be executed.
      $migration_info[$cluster]['migration_plugins'][$id] = $migration_plugin;
      // And it might be one of the "data" migration plugins.
      if ($is_data_migration_plugin) {
        $migration_info[$cluster]['data_migration_plugin_ids'][] = $id;
      }
    }

    // Resolve inter-cluster dependencies.
    $cluster_id_to_cluster_label = array_combine(array_column($migration_info, 'id'), array_column($migration_info, 'label'));
    $offset = 1;
    foreach ($migration_plugins as $id => $migration_plugin) {
      $offset++;
      $cluster_id = $lookup_table[$id];
      $cluster = $cluster_id_to_cluster_label[$cluster_id];
      // By default, required migration plugin dependencies are what determines
      // the inter-cluster dependencies.
      $dependencies = $migration_plugin->getMigrationDependencies()['required'];
      // If there aren't any, fall back to the optional dependencies (for data
      // migrations, since supporting config migrations will already have been
      // migrated by the initial migration)
      if (empty($dependencies) && in_array($id, $migration_info[$cluster]['data_migration_plugin_ids'], TRUE)) {
        // When treating optional dependencies as a (UI) reason for an
        // inter-cluster dependency, it is crucial that it is sorted to run
        // earlier. This ensures the core migration system's dependency
        // resolution logic to continue to be respected.
        $dependencies = array_intersect(
          $migration_plugin->getMigrationDependencies()['optional'],
          array_slice(array_keys($migration_plugins), 0, $offset)
        );
        // It is possible that the optional dependency itself has an optional
        // dependency on this: avoid generating an inter-cluster dependency for
        // this. For example: d7_file would depend on d7_user.
        $dependencies = array_filter($dependencies, function (string $dep) use ($id, $migration_plugins) {
          return !in_array($id, NestedArray::mergeDeepArray($migration_plugins[$dep]->getMigrationDependencies()));
        });
      }
      foreach ($dependencies as $dependency) {
        // Some required migration dependencies may not exist because they were
        // explicitly omitted by the migration plugin manager due to their
        // requirements not having been met.
        // For example, d7_user depends on user_profile_field_instance, but not
        // every D7 site has user profile fields enabled.
        if (!isset($lookup_table[$dependency])) {
          continue;
        }
        // Dependencies on migration plugins that are part of this cluster
        // cannot cause a dependency on another cluster.
        if (array_key_exists($dependency, $migration_info[$cluster]['migration_plugins'])) {
          continue;
        }
        $cluster_id = $lookup_table[$dependency];
        $migration_info[$cluster]['dependencies'][$cluster_id][$dependency] = $migration_plugins[$dependency];
      }
    }

    // This automatically skips all content migrations or migrations without any
    // rows.
    foreach ($first_time as $migration_label) {
      $has_rows = array_reduce($migration_info[$migration_label]['data_migration_plugin_ids'], function (bool $has_rows, string $id) use ($migration_plugins) {
        return $has_rows ?: $migration_plugins[$id]->getSourcePlugin()->count() > 0;
      }, FALSE);
      $before = $migration_info[$migration_label]['skipped'];
      $after = $migration_info[$migration_label]['skipped'] = (int) !$has_rows;
      if ($before !== $after) {
        $this->connection->update('acquia_migrate_migration_flags')
          ->fields(['skipped' => (int) $migration_info[$migration_label]['skipped']])
          ->condition('migration_id', $migration_info[$migration_label]['id'])
          ->execute();
      }
    }

    // Now that $migration_info is complete, generate a repository of nicely
    // structured Migration value objects.
    $migrations = [];
    foreach ($migration_info as $info) {
      $migrations[$info['id']] = new Migration(
        $info['id'],
        $info['label'],
        $info['dependencies'],
        $info['migration_plugins'],
        $info['data_migration_plugin_ids'],
        $info['completed'],
        $info['skipped'],
        $info['last_import_fingerprint'],
        $info['last_computed_fingerprint'],
        $info['last_import_timestamp'],
        $info['last_import_duration']
      );
    }

    return $migrations;
  }

}
