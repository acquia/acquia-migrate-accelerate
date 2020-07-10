<?php

namespace Drupal\acquia_migrate;

use Drupal\acquia_migrate\Exception\MissingSourceDatabaseException;
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
   * @param \Drupal\acquia_migrate\MigrationClusterer $clusterer
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
  public function getMigrations() : array {
    // Static caching added only for the to-JSON:API-normalization logic,
    // because it can potentially call this many times.
    // @see \Drupal\acquia_migrate\Migration::toResourceObject()
    static $migrations;
    if (isset($migrations)) {
      return $migrations;
    }

    $cached = $this->cache->get(static::CID);
    if (!$cached) {
      $migrations = $this->doGetMigrations();
      $this->cache->set(static::CID, $migrations, Cache::PERMANENT, ['migration_plugins']);
    }
    else {
      $migrations = $cached->data;
    }

    return $migrations;
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

    $migration_info = [];
    $drupal_migrations_with_cluster_metadata = $this->clusterer->getAvailableMigrationsSortedByCluster();

    $flags = $this->getMigrationFlags();
    $first_time = [];

    $lookup_table = [];

    foreach ($drupal_migrations_with_cluster_metadata as $id => $drupal_migration) {
      // Determine what cluster this Drupal migration belongs in.
      // @todo Cover translated cluster labels.
      $cluster = (string) $drupal_migration->getMetadata('cluster');
      $is_data_migration_plugin = TRUE;
      if (strpos($cluster, 'LIFTED-') === 0) {
        $cluster = substr($cluster, 7);
        $is_data_migration_plugin = FALSE;
      }

      // Create a temporary data structure for this cluster if it doesn't exist
      // already.
      if (!isset($migration_info[$cluster])) {
        // Generate an opaque identifier with a legible suffix at the end, to
        // balance:
        // - not baking in assumptions as we get this project off the ground
        // - easier debugging while we get this project off the ground
        // The intent is to change the opaque identifiers in the future, and
        // if they truly are treated as opaque, that will be easy to do.
        // This opaque identifier must be at most 225 ASCII characters long due
        // to MySQL 5.6 restrictions (767-255-255-32). An MD5-hash is 32
        // characters, the dash is one and that leaves 192 characters of the
        // cluster label.
        // @see acquia_migrate_schema()
        // @see \Drupal\acquia_migrate\Plugin\migrate\destination\RollbackableInterface::ROLLBACK_DATA_TABLE
        $migration_id = md5($cluster) . '-' . substr($cluster, 0, 192);
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
          'last_import_timestamp' => $flags[$migration_id]->last_import_timestamp ? (int) $flags[$migration_id]->last_import_timestamp : NULL,
          'last_import_duration' => $flags[$migration_id]->last_import_duration ? (int) $flags[$migration_id]->last_import_duration : NULL,
        ];
      }

      $lookup_table[$id] = $migration_info[$cluster]['id'];

      // This Drupal migration is one of the "migration plugins" to be executed.
      $migration_info[$cluster]['migration_plugins'][$id] = $drupal_migration;
      // And it might be one of the "data" migration plugins.
      if ($is_data_migration_plugin) {
        $migration_info[$cluster]['data_migration_plugin_ids'][] = $id;
      }

      foreach ($drupal_migration->getMigrationDependencies()['required'] as $dependency) {
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
        $migration_info[$cluster]['dependencies'][$cluster_id][$dependency] = $drupal_migrations_with_cluster_metadata[$dependency];
      }
    }

    // This automatically skips all content migrations or migrations without any
    // rows.
    foreach ($first_time as $migration_label) {
      $has_rows = array_reduce($migration_info[$migration_label]['data_migration_plugin_ids'], function (bool $has_rows, string $id) use ($drupal_migrations_with_cluster_metadata) {
        return $has_rows ?: $drupal_migrations_with_cluster_metadata[$id]->getSourcePlugin()->count() > 0;
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
    $lifted = [];
    $lifting_exemption_list = [];
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
