<?php

namespace Drupal\acquia_migrate;

use Drupal\acquia_migrate\Clusterer\Heuristics\SharedEntityData;
use Drupal\acquia_migrate\Clusterer\Heuristics\SharedLanguageConfig;
use Drupal\acquia_migrate\Clusterer\MigrationClusterer;
use Drupal\acquia_migrate\Exception\MissingSourceDatabaseException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

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
    // Static caching added only for the to-JSON:API-normalization logic,
    // because it can potentially call this many times.
    // @see \Drupal\acquia_migrate\Migration::toResourceObject()
    static $migrations;
    if (!$reset && isset($migrations)) {
      return $migrations;
    }

    $cached = $this->cache->get(static::CID);
    if ($reset || !$cached) {
      $migrations = $this->doGetMigrations();
      $this->cache->set(static::CID, $migrations, Cache::PERMANENT, ['migration_plugins']);
    }
    else {
      $migrations = $cached->data;
    }

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

    return $todo;
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
    foreach ($migration_plugins as $id => $migration_plugin) {
      $cluster_id = $lookup_table[$id];
      $cluster = $cluster_id_to_cluster_label[$cluster_id];
      foreach ($migration_plugin->getMigrationDependencies()['required'] as $dependency) {
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

    return static::sort($migrations);
  }

  /**
   * Gets migration dependency counts.
   *
   * @param \Drupal\acquia_migrate\Migration[] $migrations
   *   A list of migrations, keyed by migration ID.
   * @param bool $omit_dependencyless
   *   Whether to omit dependencyless migrations.
   *
   * @return int[]
   *   The dependency counts, keyed by migration ID.
   */
  private static function getDependencyCount(array $migrations, bool $omit_dependencyless) : array {
    $weights = [];

    if (!$omit_dependencyless) {
      foreach (array_keys($migrations) as $migration_id) {
        $weights[$migration_id] = 0;
      }
    }

    foreach ($migrations as $migration) {
      foreach ($migration->getDependencies() as $dependency) {
        if (!isset($weights[$dependency])) {
          $weights[$dependency] = 0;
        }
        $weights[$dependency]++;
      }
    }

    return $weights;
  }

  /**
   * Sorts migrations.
   *
   * @param \Drupal\acquia_migrate\Migration[] $migrations
   *   The migrations to sort, keyed by migration ID.
   *
   * @return \Drupal\acquia_migrate\Migration[]
   *   The sorted migrations, keyed by migration ID.
   */
  private static function sort(array $migrations) : array {
    // Initialize the first sorting order array for array_multisort(): one based
    // on labels (for alphabetical sorting).
    $labels = [];
    foreach ($migrations as $migration) {
      $labels[$migration->id()] = $migration->label();
    }

    // If fewer than 10 migrations, just sort alphabetically.
    // (This will never happen in the real world, but does happen in some of our
    // low-level tests.)
    // @see \Drupal\Tests\acquia_migrate\Functional\HttpApiTest::testMigrationsCollection()
    if (count($migrations) < 10) {
      array_multisort(
        $labels, SORT_ASC, SORT_NATURAL,
        $migrations
      );
      return $migrations;
    }

    // Initialize the other sorting ordering array for array_multisort(): a
    // weight-based array that we prime with the most heavily depended upon
    // migrations getting the heaviest weight.
    $weights = static::getDependencyCount($migrations, FALSE);

    // Some migrations now have non-zero weights, many still have weight zero.
    // The latter are the leaves in the tree: they only depend on others,
    // nothing depends on them.
    // @codingStandardsIgnoreStart
    assert(!empty(array_filter($weights, function (int $w) { return $w === 0; })));
    assert(!empty(array_filter($weights, function (int $w) { return $w !== 0; })));
    // @codingStandardsIgnoreEnd

    // We assume that non-leaf nodes in the tree (weight >0) are the ones the
    // user cares most about, because are at the heart of the site's content
    // model.
    // $weights currently is based on dependency count, but that is too
    // simplistic: we want to ensure that sites with many migrations for one
    // entity type (e.g. lots of vocabularies) does not cause that to rise to
    // the top, before more impactful migrations (e.g. nodes).
    $non_leaf_migrations = array_filter($migrations, function (Migration $migration) use ($weights) {
      return $weights[$migration->id()] > 0;
    });
    $very_high_impact_weights = static::getDependencyCount($non_leaf_migrations, TRUE);
    arsort($very_high_impact_weights, SORT_NUMERIC);
    // Very high-impact migrations.
    foreach ($very_high_impact_weights as $migration_id => $weight) {
      $weights[$migration_id] = ($weights[$migration_id] + $weight) * 100000;
    }
    // High-impact migrations.
    foreach (array_keys(array_diff_key($non_leaf_migrations, $very_high_impact_weights)) as $migration_id) {
      $weights[$migration_id] *= 10000;
    }

    // The high-impact migrations now have significantly higher weights. It is
    // now possible to lift the migrations that depend on those high-impact
    // migrations. The order of dependencies of a given migration matters: the
    // first dependency on a high-impact migration determines where the
    // migration is lifted.
    // Amongst others, this ensures that migrations with the same base data
    // migration plugin ID are contiguous (e.g. all bundle-specific migrations
    // of the same entity type).
    // The only high-impact migration we exempt from lifting towards is the text
    // format migration: many migrations depend on it, but it does not make
    // sense to lift a majority of migration towards it.
    // For the same reason, we exempt the Language Settings cluster from getting
    // lifted towards, because when Multilingual migrations are enabled, almost
    // everything will depend on it. The clusterer does ensure it runs very
    // early automatically because of these many dependencies.
    $lifted = [];
    $lifting_exemption_list = [
      Migration::generateIdFromLabel(SharedLanguageConfig::cluster()),
    ];
    foreach ($migrations as $migration) {
      if ($migration->getDataMigrationPluginIds() === ['d7_filter_format']) {
        $lifting_exemption_list[] = $migration->id();
      }
    }
    foreach (array_keys($migrations) as $migration_id) {
      foreach ($migrations[$migration_id]->getDependencies() as $dep) {
        if (in_array($dep, $lifting_exemption_list, TRUE)) {
          continue;
        }

        // Avoid indirect dependencies from getting lifted just below their
        // highest-weight dependency, and instead keeps them anchored to one of
        // the high-impact migrations, if any.
        if (isset($lifted[$dep])) {
          continue;
        }

        // We are about to lift a migration. Never allow a lower high-impact
        // migration to pull a higher high-impact migration down.
        assert($weights[$migration_id] < $weights[$dep]);

        // Lift up to just below the high-impact migration, if this migration
        // indeed depends on one of the high-impact migrations. Otherwise, lift
        // to just below the first non-exempted, non-lifted dependency.
        if (isset($very_high_impact_weights[$dep])) {
          $weights[$migration_id] = $weights[$dep] - 1;
          $lifted[$migration_id] = TRUE;
        }
        else {
          $weights[$migration_id] = $weights[$dep] - 1;
        }
        break;
      }
    }

    // Last but not least: a correcting factor. This started out with the most
    // heavily depended upon migrations, then calculated high-impact migrations
    // based on that. This yields a generally sensible order that makes sense to
    // humans. But, it's not guaranteed, because there may be migrations that
    // fail to correctly declare all their dependencies. A common example is
    // that almost every entity depends on the `User` entity type, but many
    // migrations do not declare the correct dependency. This then results in
    // the user migration (which is certainly high-impact) from getting weighted
    // lower, which means it may be listed far below migrations that actually
    // depend on it. Blocking migrations should be listed before the migrations
    // that they block.
    // To correct this, we inspect at all lifted migrations and and ensure all
    // high-impact migrations that they depend upon are in fact listed before
    // them: pull them up.
    foreach (array_keys($lifted) as $migration_id) {
      $weight = $weights[$migration_id];
      $depended_unblocking_migrations = array_intersect(array_keys($very_high_impact_weights), $migrations[$migration_id]->getDependencies());
      foreach ($depended_unblocking_migrations as $dep) {
        $blocker_weight = $weights[$dep];
        if ($weight > $blocker_weight) {
          $other_unblocking_migrations = array_diff($depended_unblocking_migrations, [$dep]);
          $other_unblocking_migrations_weights = [];
          foreach ($other_unblocking_migrations as $unblocking_migration_id) {
            $other_unblocking_migrations_weights[$unblocking_migration_id] = $weights[$unblocking_migration_id];
          }
          assert($weight > $weights[$dep]);
          // Pull up the blocking high-impact migration to just after the lowest
          // other blocking high-impact migration. It's possible that that
          // lowest one is insufficiently weighted yet: in that case fall back
          // to running just before the migration.
          $pulled_weight = min($other_unblocking_migrations_weights) > $weight
            ? min($other_unblocking_migrations_weights) + 100
            : $weight + 100;
          $weights[$dep] = $pulled_weight;
          assert($weight < $weights[$dep]);
        }
      }
    }

    array_multisort(
      // Use the numerical weight as the primary sort.
      $weights, SORT_DESC, SORT_NUMERIC,
      // When migrations have the same weight, sort alphabetically by label.
      $labels, SORT_ASC, SORT_NATURAL,
      $migrations
    );

    return $migrations;
  }

}
