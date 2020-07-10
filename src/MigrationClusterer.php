<?php

namespace Drupal\acquia_migrate;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\MigrateBuildDependencyInterface;
use Drupal\migrate\Plugin\Migration;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate\Plugin\RequirementsInterface;

/**
 * Clusters Drupal's "migration (plugin)s" into this module's "migrations".
 */
class MigrationClusterer {

  use StringTranslationTrait;

  const CLUSTER_NO_DATA = 'No data';
  const CLUSTER_SITE_CONFIGURATION = 'Site configuration';
  const CLUSTER_SITE_CONFIGURATION_NEEDS_HUMAN = 'Site configuration — NEEDS HUMAN';
  const CLUSTER_DATA_MODEL = 'Data model';
  const CLUSTER_NONE = 'None';

  /**
   * Clusters of the simple type can be run independently.
   */
  const CLUSTER_TYPE_SIMPLE = 0;

  /**
   * A migration that is lifted to enable a goal of a cluster.
   *
   * Cluster of compound type needs to have its sibling lifted cluster run
   * first, followed by the one that is the actual goal. The label of the goal
   * cluster is presented to the end user, and they should be presented as a
   * compound cluster (f.e. as an accordion).
   *
   * For example: d7_user_role.
   */
  const CLUSTER_TYPE_COMPOUND_LIFTED = 1;

  /**
   * A migration that is the goal of a cluster.
   *
   * For example: d7_user.
   */
  const CLUSTER_TYPE_COMPOUND_GOAL = 2;

  /**
   * IDs of migration source plugins that should land in Media shared structure.
   *
   * @see \Drupal\acquia_migrate\MigrationClusterer::getAvailableMigrationsSortedByCluster()
   * @see \Drupal\acquia_migrate\MigrationClusterer::isSharedEntityStructureMigration()
   *
   * @var array
   */
  const MEDIA_MIGRATION_SPECIAL_SHARED_SRC_PLUGIN_IDS = [
    // @see \Drupal\media_migration\Plugin\migrate\source\d7\MediaSourceFieldInstance
    'd7_media_source_field_instance',
    // @see \Drupal\media_migration\Plugin\migrate\source\d7\MediaViewMode
    'd7_media_view_mode',
  ];

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new MigrationClusterer.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_manager
   *   A migration plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(MigrationPluginManagerInterface $migration_manager, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    $this->migrationManager = $migration_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * The migration plugin IDs to ignore.
   *
   * @return string[]
   *   A list of migration plugin IDs.
   */
  protected static function getIgnoredMigrations() {
    return [];
  }

  /**
   * Gets the type of cluster.
   *
   * @param string $cluster
   *   An assigned cluster.
   *
   * @return int
   *   A cluster type. One of:
   *   - static::CLUSTER_TYPE_SIMPLE
   *   - static::CLUSTER_TYPE_COMPOUND_LIFTED
   *   - static::CLUSTER_TYPE_COMPOUND_GOAL
   *   - static::CLUSTER_SITE_CONFIGURATION_NEEDS_HUMAN
   */
  public static function getClusterType(string $cluster) {
    switch ($cluster) {
      case static::CLUSTER_NO_DATA:
      case static::CLUSTER_DATA_MODEL:
      case static::CLUSTER_SITE_CONFIGURATION:
      case static::CLUSTER_NONE:
        return static::CLUSTER_TYPE_SIMPLE;

      default:
        if (strpos($cluster, static::CLUSTER_SITE_CONFIGURATION_NEEDS_HUMAN) === 0) {
          return static::CLUSTER_SITE_CONFIGURATION_NEEDS_HUMAN;
        }
        if (strpos($cluster, 'LIFTED-') === 0) {
          return static::CLUSTER_TYPE_COMPOUND_LIFTED;
        }
        return static::CLUSTER_TYPE_COMPOUND_GOAL;
    }
  }

  /**
   * Gets the label for a cluster.
   *
   * @param string $cluster
   *   An assigned cluster.
   *
   * @return string
   *   A cluster label.
   */
  public static function getClusterLabel(string $cluster) {
    switch ($cluster) {
      case static::CLUSTER_NO_DATA:
        return static::CLUSTER_NO_DATA;

      case static::CLUSTER_DATA_MODEL:
        return static::CLUSTER_DATA_MODEL;

      case static::CLUSTER_SITE_CONFIGURATION:
        return static::CLUSTER_SITE_CONFIGURATION;

      case static::CLUSTER_NONE:
        return static::CLUSTER_NONE;

      default:
        if (strpos($cluster, static::CLUSTER_SITE_CONFIGURATION_NEEDS_HUMAN) === 0) {
          return substr($cluster, strlen(static::CLUSTER_SITE_CONFIGURATION_NEEDS_HUMAN));
        }
        if (strpos($cluster, 'LIFTED-') === 0) {
          return substr($cluster, 7);
        }
        return $cluster;
    }
  }

  /**
   * Gets the migration plugin instances that have their requirements met.
   *
   * @return array|\Drupal\migrate\Plugin\MigrationInterface[]
   *   The available migration plugin instances, keyed by ID, sorted in the
   *   optimal order.
   */
  public function getAvailableMigrations() {
    $migrations = $this->migrationManager->createInstancesByTag('Drupal 7');

    foreach ($migrations as $id => $migration) {
      // Ignore any and all overridden in-code migration plugins. We can
      // retrieve them when we need them.
      if (strpos($id, 'original___') === 0) {
        unset($migrations[$id]);
      }
      try {
        if ($migration->getSourcePlugin() instanceof RequirementsInterface) {
          $migration->getSourcePlugin()->checkRequirements();
        }
        if ($migration->getDestinationPlugin() instanceof RequirementsInterface) {
          $migration->getDestinationPlugin()->checkRequirements();
        }
      }
      catch (RequirementsException $e) {
        unset($migrations[$id]);
      }
    }

    // Sort again after requirements have been met, so that the dependency-based
    // migration sorting logic can call `::allRowsProcessed()` without
    // triggering fatal errors.
    assert($this->migrationManager instanceof MigrateBuildDependencyInterface);
    $migrations = $this->migrationManager->buildDependencyMigration($migrations, [], TRUE);

    $migrations = array_diff_key($migrations, array_combine(static::getIgnoredMigrations(), static::getIgnoredMigrations()));

    return $migrations;
  }

  /**
   * Gets available (executable) migrations, sorted by cluster.
   *
   * Assigns a "cluster" property to every Migration, and changes the order of
   * migrations to maximize the size of each cluster.
   *
   * The following clusters are assigned, and migrations are ordered in this
   * sequence as well:
   *   1. "No data": for migrations that have no data to migrate; we prioritize
   *      these because it shows the user that we've done the work to show that
   *      these need *no* work.
   *   2. "Site configuration": for non-entity migrations that don't have any
   *      dependencies. These can be executed at any time, and usually they're
   *      for trivial configuration. We prioritize these next because it gives
   *      the user an easy win.
   *   3. "<site configuration that needs human>": the subset of 2 that does
   *      usually need human intervention.
   *   4. "<entity type>": for each migration that has a entity type as its
   *      destination, we generate a cluster. Exclude view mode, field storage
   *      config, field config and base field override entities since those are
   *      natural dependencies of other entity types. The user expects them to
   *      be migrated together, so we we combine them in a cluster.
   *   5. "None": any other migrations.
   *
   * @param bool $omit_no_data_cluster
   *   Unused, defaults to TRUE.
   *
   * @return \Drupal\migrate\Plugin\Migration[]
   *   A list of executable migrations in the order they should be executed,
   *   optimized to run per cluster. Each migration has a `cluster` property
   *   assigned.
   */
  public function getAvailableMigrationsSortedByCluster($omit_no_data_cluster = TRUE) {
    $migrations = $this->getAvailableMigrations();

    // @codingStandardsIgnoreStart
    // Assert shape of input data:
    // - they must be Migration instances
    assert(Inspector::assertAllObjects($migrations, Migration::class));
    // - with each migration containing the complete dependency metadata
    assert(Inspector::assertAll(function (Migration $migration) { return $migration->getMetadata('after') !== NULL && $migration->getMetadata('before') !== NULL; }, $migrations));
    // - with each migration having a category assigned
    assert(Inspector::assertAll(function (Migration $migration) { return $migration->getMetadata('category') !== NULL; }, $migrations));
    // @codingStandardsIgnoreEnd

    // Compute clusters 1 and 2, which are simple: "No data" and
    // "Site configuration".
    $no_data_migrations = array_map(
      call_user_func_array([get_class($this), 'setClusterCallback'], [self::CLUSTER_NO_DATA]),
      array_filter($migrations, [get_class($this), 'isNoDataMigration'])
    );
    if ($omit_no_data_cluster) {
      $migrations = array_diff_key($migrations, $no_data_migrations);
      $no_data_migrations = [];
    }
    $any_time_migrations = array_map(
      call_user_func_array([get_class($this), 'setClusterCallback'], [self::CLUSTER_SITE_CONFIGURATION]),
      array_filter($migrations, [get_class($this), 'isAnyTimeMigration'])
    );
    $config_needing_human_migrations = array_map(
      function (Migration $migration) {
        $migration->setMetadata('cluster', $migration->label());
        return $migration;
      },
      array_filter($migrations, [get_class($this), 'isConfigNeedingHumanMigration'])
    );

    // Compute the resulting clustered migrations so far.
    $clustered_migrations = $no_data_migrations + $any_time_migrations + $config_needing_human_migrations;

    // One "shared structure" cluster per bundleable entity type.
    $shared_structure_migrations = array_filter($migrations, [$this, 'isSharedEntityStructureMigration']);
    // Set the human-readable label (cluster) for the shared structure
    // migrations.
    $shared_structure_migrations = array_map(
      function (Migration $migration) {
        $source_config = $migration->getSourceConfiguration();
        $field_entity_type_id = $source_config['entity_type'] ?? NULL;
        $dest_entity_type_id = $this->getDestinationEntityTypeId($migration);
        // Paragraph or field collection field config migrations have
        // 'paragraphs_item', 'field_collection_item' source entity type.
        if (in_array($field_entity_type_id, ['paragraphs_item', 'field_collection_item'], TRUE) || $dest_entity_type_id === 'paragraphs_type') {
          // The only issue with paragraphs shared structure cluster is that
          // it seems that type (bundle) and field storage imports aren't run
          // before the field instance config imports that results in skipped
          // rows for the field widget settings of the nested field collection.
          $entity_type = $this->entityTypeManager->getDefinition('paragraph', FALSE);
          $label = $entity_type ? $entity_type->getPluralLabel() : 'paragraphs';
        }
        // Media entities in Drupal 7 are (fieldable) file entities, so the
        // view mode and the field storage migration's source entity type ID
        // is "file".
        elseif (in_array($source_config['plugin'], self::MEDIA_MIGRATION_SPECIAL_SHARED_SRC_PLUGIN_IDS, TRUE) || $field_entity_type_id === 'file') {
          $entity_type = $this->entityTypeManager->getDefinition('media', FALSE);
          $label = $entity_type ? $entity_type->getPluralLabel() : 'media';
        }
        elseif ($field_entity_type_id) {
          $entity_type = $this->entityTypeManager->getDefinition($field_entity_type_id, FALSE);
          $label = $entity_type ? $entity_type->getPluralLabel() : $field_entity_type_id;
        }
        elseif ($dest_entity_type_id) {
          $entity_type = $this->entityTypeManager->getDefinition($dest_entity_type_id, FALSE);
          $label = $entity_type ? $entity_type->getPluralLabel() : $dest_entity_type_id;
        }

        $migration->setMetadata('cluster', (string) $this->t('Shared structure for @entity-type-plural', [
          '@entity-type-plural' => $label,
        ]));
        return $migration;
      },
      $shared_structure_migrations
    );

    // Append the entity shared structure migration clusters to the result.
    $clustered_migrations = $clustered_migrations + $shared_structure_migrations;

    // Compute cluster 3: one cluster per entity type + bundle.
    $entity_migrations = array_filter($migrations, [get_class($this), 'isContentEntityDestination']);
    // Sort entity migrations by inspecting dependencies. Do this on a clone of
    // each Migration object, to avoid overwriting the complete dependency
    // metadata.
    $entity_migrations = array_map(function ($object) {
      return clone $object;
    }, $entity_migrations);
    $all_entity_migrations_sorted = array_keys($this->migrationManager->buildDependencyMigration($entity_migrations, []));
    reset($all_entity_migrations_sorted);
    // $entity_migrations contains only content entity migrations, not their
    // dependencies. Without their dependencies, they will fail. Use the
    // dependency metadata on the entity migrations to determine which other
    // migrations to lift up out of the complete list of migrations, and assign
    // those dependencies to a "data model" cluster for this content entity type
    // just before this content entity type cluster.
    $entity_migrations_plus_dependencies = [];
    foreach ($all_entity_migrations_sorted as $migration_id) {
      $migration = $migrations[$migration_id];
      $entity_cluster = (string) $migration->label();
      if (!empty($migration->getMetadata('after'))) {
        $to_be_lifted_migration_ids = array_diff_key(static::getRecursivelyRequiredMigrationDependencies($migration_id, $migrations), $entity_migrations_plus_dependencies, $clustered_migrations);
        // "d7_menu_link:<entity_type_id>" migrations should be lifted into the
        // corresponding entity cluster, and not into "d7_shortcut".
        if ($migration_id === 'd7_shortcut') {
          $to_be_lifted_migration_ids = array_filter($to_be_lifted_migration_ids, function (string $id) {
            $base_plugin_id = explode(':', $id)[0];
            return $base_plugin_id !== 'd7_menu_links';
          });
        }
        $lifted_migrations = array_map(
          call_user_func_array([get_class($this), 'setClusterCallback'], ["LIFTED-$entity_cluster"]),
          array_intersect_key($migrations, $to_be_lifted_migration_ids)
        );
        $entity_migrations_plus_dependencies += $lifted_migrations;
      }
      $migration->setMetadata('cluster', $entity_cluster);
      $entity_migrations_plus_dependencies[$migration_id] = $migration;
    }
    // @todo rename "lifting" above to "pulling". Then we get a nice symmetry in terminology: we respect execution order computed by migration dependencies, but then perform some pulling and some pushing in order to end up with clusters that make sense to the site builder.
    // Try to push up the remaining migrations into a pre-existing <entity type>
    // migration cluster, if:
    // 1. unclustered (no 'cluster' metadata assigned yet)
    $pushable_migrations = array_filter($migrations, [
      get_class($this),
      'isNoClusterMigration',
    ]);
    // 2. there are >=2 clusters, pushing lots of stuff into the "User accounts"
    //    cluster is pointless.
    if (count($entity_migrations) < 2) {
      $pushable_migrations = [];
    }
    foreach ($pushable_migrations as $pushable_migration_id => $migration) {
      $base_plugin_id = explode(':', $pushable_migration_id)[0];
      // 3. cluster depender (depends on a migration that is part of a cluster)
      $clustered_dependees = array_intersect(array_keys($entity_migrations_plus_dependencies), $migration->getMigrationDependencies()['required']);
      $cluster_needs_human_dependees = array_intersect(array_keys($config_needing_human_migrations), $migration->getMigrationDependencies()['required']);
      $is_cluster_depender = !empty($clustered_dependees);
      // 4. non-dependee (no migrations depend on it: empty 'before' metadata)
      $is_non_dependee = empty($migration->getMetadata('before'));
      if ($is_cluster_depender && $is_non_dependee) {
        // Push up towards the clustered dependee that runs last, to ensure all
        // dependencies continue to have been met by the time it will run.
        $migration_to_push_towards = end($clustered_dependees);
        // Look up the cluster of the dependee it's getting pushed to run after
        // and assign this same cluster.
        $cluster_to_push_towards = $migrations[$migration_to_push_towards]->getMetadata('cluster');
        $migration->setMetadata('cluster', $cluster_to_push_towards);
        // Push it upwards. This is what changes the execution order.
        $insertion_point = array_search($migration_to_push_towards, array_keys($entity_migrations_plus_dependencies)) + 1;
        $entity_migrations_plus_dependencies = array_slice($entity_migrations_plus_dependencies, 0, $insertion_point) + [$pushable_migration_id => $migration] + array_slice($entity_migrations_plus_dependencies, $insertion_point);
        // Now it is no longer one of the remaining unclustered migrations: it
        // was clustered by pushing instead of lifting.
        unset($pushable_migrations[$pushable_migration_id]);
      }
      elseif (!empty($cluster_needs_human_dependees) && $is_non_dependee) {
        $migration_to_push_towards = end($cluster_needs_human_dependees);
        $cluster_to_push_towards = $migrations[$migration_to_push_towards]->getMetadata('cluster');
        $migration->setMetadata('cluster', $cluster_to_push_towards);
      }
      elseif ($base_plugin_id === 'd7_menu_links' && count($clustered_dependees) === 1) {
        $migration_to_push_towards = reset($clustered_dependees);
        $cluster_to_push_towards = $migrations[$migration_to_push_towards]->getMetadata('cluster');
        $migration->setMetadata('cluster', $cluster_to_push_towards);
      }
    }

    // Append the entity migration clusters to the result.
    $clustered_migrations += $entity_migrations_plus_dependencies;

    // We're entering the land of relatively far-fetched heuristics. These
    // probably need to be refined over time, as we pass more contrib migrations
    // through this logic. Hence the copious use of assertions to verify our
    // assumptions.
    $pushable_migrations = array_filter($migrations, [
      get_class($this),
      'isNoClusterMigration',
    ]);
    $pre_count = count($any_time_migrations);
    foreach ($pushable_migrations as $pushable_migration_id => $migration) {
      // If this migration could run at any time, then $is_any_time_migration
      // above should've picked it up.
      assert(!self::isAnyTimeMigration($migration));

      $dependencyless = empty($migration->getMetadata('after'));
      $clustered_dependees = array_intersect(array_keys($any_time_migrations), $migration->getMigrationDependencies()['required']);
      $is_cluster_depender = !empty($clustered_dependees);

      // If this migration does not have any dependencies, then it's safe to
      // push up into the SITE_CONFIGURATION cluster. It initially was not put
      // there because it is probably a config entity migration.
      if ($dependencyless) {
        assert(self::isConfigEntityDestination($migration));
        $any_time_migrations[$pushable_migration_id] = $migration;
      }
      // It's also safe to push this up if all dependencies in fact are already
      // present in the SITE_CONFIGURATION cluster.
      elseif ($is_cluster_depender) {
        $any_time_migrations[$pushable_migration_id] = $migration;
      }
      else {
        continue;
      }

      $migration->setMetadata('cluster', static::CLUSTER_SITE_CONFIGURATION);
    }
    $clustered_migrations = $any_time_migrations + array_slice($clustered_migrations, $pre_count);

    // Of the remaining unclustered migrations, create clusters for those that
    // have a config entity as a destination. If they are still unclustered at
    // this time, they must have dependencies AND not be associated with any
    // content entity type.
    $dependent_config_entity_migrations = array_filter($migrations, function (Migration $migration) {
      return self::isNoClusterMigration($migration) && self::isConfigEntityDestination($migration);
    });
    foreach ($dependent_config_entity_migrations as $migration_id => $migration) {
      // If this config entity migration did not have any dependencies (could
      // run at any time, then $is_any_time_migration or the pushing into entity
      // clusters above should've picked it up.
      assert(!empty($migration->getMetadata('after')));

      $migration->setMetadata('cluster', (string) $migration->label());

      // Maybe in the future it will make to expose per-theme migrations, but
      // right now we're choosing to not yet do that. So, special case this one.
      // @todo consider moving d7_theme_settings:<theme> migration plugins to per-theme clusters.
      if ($migration->getBaseId() === 'd7_block') {
        $migration->setMetadata('cluster', 'Block placements');
      }
    }
    $clustered_migrations += $dependent_config_entity_migrations;

    // Finally, append all other migrations. Together they form cluster 8.
    $clustered_migrations += array_map(
      call_user_func_array([get_class($this), 'setClusterCallback'], [self::CLUSTER_NONE]),
      $migrations
    );

    // Assert shape of output data:
    // - they must be Migration instances:
    assert(Inspector::assertAllObjects($clustered_migrations, Migration::class));
    // - with each migration having a cluster assigned:
    // @codingStandardsIgnoreStart
    assert(Inspector::assertAll(function (Migration $migration) {
      return !self::isNoClusterMigration($migration);
    }, $clustered_migrations));
    // - with the order being:
    //   1. "No data"
    //   2. "Site configuration",
    //   3. "<site configuration that needs human>"
    //   4. "Data model" (optional)
    //   5. "<(content) entity type shared structure if not bundleable>"
    //   6. "<(content) entity type + bundle>"
    //   7. "<config entity types that have dependencies and are not associated with content entities>"
    //   8. "None".
    // @codingStandardsIgnoreEnd
    assert(array_keys($clustered_migrations) == array_keys($no_data_migrations + $any_time_migrations + $config_needing_human_migrations + $shared_structure_migrations + $entity_migrations_plus_dependencies + $dependent_config_entity_migrations + $migrations));

    return $clustered_migrations;
  }

  /**
   * Gets the recursive required dependencies for a migration plugin.
   *
   * @param string $migration_plugin_id
   *   The ID of the migration plugin for which to get the recursive required
   *   dependencies.
   * @param \Drupal\migrate\Plugin\MigrationInterface[] $all_migration_plugins
   *   An array of all available migration plugin instances, keyed by migration
   *   ID.
   *
   * @return array
   *   An array of migration plugin IDs.
   */
  protected static function getRecursivelyRequiredMigrationDependencies($migration_plugin_id, array $all_migration_plugins) : array {
    static $recursive_calls;
    if (!isset($recursive_calls)) {
      $recursive_calls = [];
    }

    // Required dependencies can only be missing if and only if they don't exist
    // in the source. For example, the `user_profile_field_instance` dependency
    // of `d7_user`.
    if (!array_key_exists($migration_plugin_id, $all_migration_plugins)) {
      unset($recursive_calls[$migration_plugin_id]);
      return [];
    }

    $required = $all_migration_plugins[$migration_plugin_id]->getMigrationDependencies()['required'];

    // Base plugin IDs with potential self- or circular dependency.
    $known_faulty_base_plugin_ids = [
      'd7_paragraphs',
      'd7_field_collection',
      'd7_paragraphs_revisions',
      'd7_field_collection_revisions',
    ];
    $current_is_known_faulty_migration = in_array(explode(':', $migration_plugin_id)[0], $known_faulty_base_plugin_ids);

    // Temporary fix for nested paragraph and nested field collection entity
    // migrations. A derived nested paragraph entity (revision) migration
    // depends on itself. To prevent infinitely searching for nested
    // dependencies here, we remove the current migration plugin ID from the
    // list of the first-level required migration plugins "$required".
    // @todo Remove when https://backlog.acquia.com/browse/OCTO-3384 is solved.
    // @see https://drupal.org/node/3145755#comment-13684540
    if ($current_is_known_faulty_migration && isset($recursive_calls[$migration_plugin_id]) && in_array($migration_plugin_id, $required)) {
      $key = array_search($migration_plugin_id, $required);
      if ($key !== FALSE) {
        unset($required[$key]);
      }
    }

    if (!array_key_exists($migration_plugin_id, $recursive_calls)) {
      $recursive_calls[$migration_plugin_id] = 1;
    }
    elseif ($recursive_calls[$migration_plugin_id] < 2) {
      $recursive_calls[$migration_plugin_id]++;
    }
    else {
      throw new \LogicException("Recursive limit reached in MigrationClusterer::getRecursivelyRequiredMigrationDependencies() for the migration with '$migration_plugin_id' plugin ID.");
    }

    $deps = array_combine($required, $required);

    foreach ($deps as $dep) {
      $deps = array_merge($deps, static::getRecursivelyRequiredMigrationDependencies($dep, $all_migration_plugins));
    }
    unset($recursive_calls[$migration_plugin_id]);
    return array_unique($deps);
  }

  /**
   * Checks whether a migration has a cluster assigned.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   True if the migration does not have 'cluster' metadata (yet), FALSE if it
   *   is clustered.
   */
  public static function isNoClusterMigration(Migration $migration) : bool {
    return empty($migration->getMetadata('cluster'));
  }

  /**
   * Checks whether this migration represents a "shared structure" migration.
   *
   * "One "shared structure" cluster per bundleable entity type.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   This is more likely checks 'field' related configuration migrations,
   *   right?
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function isSharedEntityStructureMigration(Migration $migration) {
    $dest_entity_type_id = $this->getDestinationEntityTypeId($migration);
    if ($dest_entity_type_id === 'paragraphs_type') {
      return TRUE;
    }

    // We need a standalone cluster for the menu (config entity) migration
    // that's shared across content entity migrations and "Other menu links"
    // migration.
    if ($dest_entity_type_id === 'menu') {
      return TRUE;
    }

    $src_config = $migration->getSourceConfiguration();
    $field_src_entity_type_id = $src_config['entity_type'] ?? NULL;
    $para_legacy_entity_type_ids = ['paragraphs_item', 'field_collection_item'];
    $migrations_which_have_entity_type_src_config = [
      'd7_field',
      'd7_field_instance',
      'd7_field_formatter_settings',
      'd7_field_instance_widget_settings',
      'd7_view_modes',
    ];

    if (in_array($field_src_entity_type_id, $para_legacy_entity_type_ids, TRUE) && in_array($migration->getBaseId(), $migrations_which_have_entity_type_src_config, TRUE)) {
      return TRUE;
    }

    if (!self::isConfigEntityDestination($migration)) {
      return FALSE;
    }

    // Shared structure for media.
    if (in_array($src_config['plugin'], self::MEDIA_MIGRATION_SPECIAL_SHARED_SRC_PLUGIN_IDS, TRUE)) {
      return TRUE;
    }

    // "shared structure" means it is shared across all bundles for a given
    // entity type.
    // Heuristic — some migration plugins might name this differently!
    // @todo Check if there are other such "shared structure" things besides
    //   d7_view_modes and d7_field.
    // @todo Document why only source 'entity_type' and 'bundle' are checked.
    //   Why isn't 'node_type' checked here? And also, why not checking the
    //   destination's 'default_bundle' or destination entity type id
    //   configuration?
    $migration_is_bundle_agnostic = $field_src_entity_type_id && !isset($src_config['bundle']);
    if (!$migration_is_bundle_agnostic) {
      return FALSE;
    }

    // @todo Remove this line when derivatives for non-existing entity types
    //   cease to be generated.
    // @todo Document why the source 'entity_type' is checked. I'm almost sure
    //   that we have to check the destination entity type here.
    if (
      !$this->entityTypeManager->hasDefinition($src_config['entity_type']) &&
      // D7 media's field storage config also belongs to shared structure when
      // media_migration is installed.
      ($src_config['entity_type'] === 'file' && !$this->moduleHandler->moduleExists('media_migration'))
    ) {
      return FALSE;
    }

    // We only care about "shared structure" if there is something to share,
    // that is, if there are multiple bundles.
    // @todo If we checked the destination entity type here, we might avoid
    //   the 'or if media is present' condition here.
    $definition_from_source = $this->entityTypeManager->getDefinition($src_config['entity_type'], FALSE);
    $entity_type_has_bundles = ($definition_from_source && $definition_from_source->getBundleEntityType() !== NULL)
      // If media is available, we assume that we migrate D7 file entities to
      // media entities, and media has bundles.
      || ($src_config['entity_type'] === 'file' && $this->moduleHandler->moduleExists('media_migration'));

    $dependencyless = empty($migration->getMetadata('after'));

    if ($migration_is_bundle_agnostic && $entity_type_has_bundles && !$dependencyless) {
      throw new \LogicException(sprintf('The currently known shared structure migrations do not have any dependencies. This assumption does not hold for %s.', $migration->id()));
    }

    return $migration_is_bundle_agnostic && $entity_type_has_bundles;
  }

  /**
   * Sets the cluster label on a migration.
   *
   * @param string $label
   *   The cluster label to set.
   * @param bool $overwrite_existing
   *   (optional) Whether existing cluster metadata should be overwritten.
   *   Defaults to FALSE.
   *
   * @return \Closure
   *   A function that sets the cluster on the passed migration plugin instance.
   */
  protected static function setClusterCallback(string $label, bool $overwrite_existing = FALSE) : \Closure {
    return function (Migration $migration) use ($label, $overwrite_existing) {
      $existing_cluster = $migration->getMetadata('cluster');
      // Don't overwrite the assigned cluster.
      if (empty($existing_cluster) || $overwrite_existing) {
        $migration->setMetadata('cluster', $label);
      }
      return $migration;
    };
  }

  /**
   * Checks whether this is a migration that can be imported at any time.
   *
   * Any time migrations don't have dependencies, no other migration depends on
   * them, don't require 'human' and are not content entity migrations.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   Whether this is an 'any time' migration or not.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  protected static function isAnyTimeMigration(Migration $migration) : bool {
    return !self::isConfigNeedingHumanMigration($migration) && empty($migration->getMetadata('before')) && empty($migration->getMetadata('after')) && !self::isNoDataMigration($migration) && !self::isContentEntityDestination($migration);
  }

  /**
   * Checks whether this is a config migration needing human oversight.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration to check.
   *
   * @return bool
   *   TRUE if the migration is a configuration migration that needs human
   *   oversight.
   *   (Currently only the 'd7_filter_format' migration plugin.)
   */
  protected static function isConfigNeedingHumanMigration(Migration $migration) : bool {
    return $migration->id() === 'd7_filter_format';
  }

  /**
   * Checks whether this is a migration with no data to migrate.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if the migration has 'No data' cluster and if it isn't a content
   *   entity migration.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  protected static function isNoDataMigration(Migration $migration) : bool {
    return $migration->getMetadata('category') === self::CLUSTER_NO_DATA && !self::isContentEntityDestination($migration);
  }

  /**
   * Check whether a migration is content entity migration.
   *
   * Tag 'Content' is a requirement, but every migration can be excluded here
   * that are not considered part of the content model from the site builder's
   * perspective, e.g. path aliases.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if the migration is tagged with 'Content' and if its destination
   *   is considered part of the content model.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  protected static function isContentEntityDestination(Migration $migration) : bool {
    $destination_plugin_id = $migration->getDestinationPlugin()->getPluginId();
    // URL aliases are entities too as of Drupal 8.8.0, but they are not
    // considered part of the content model from the site builder's
    // perspective, so: explicitly exclude them. Except for the derivative
    // that contains the path aliases that do not target entities.
    // @see https://www.drupal.org/node/3013865.
    if ($destination_plugin_id === 'entity:path_alias' && array_key_exists('entity_type_id', $migration->getSourceConfiguration())) {
      return FALSE;
    }

    // Exclude menu link content entities as well, except for the derivative
    // that contains the 'every other' menu links.
    // @see https://www.drupal.org/node/3013865.
    if ($destination_plugin_id === 'entity:menu_link_content' && array_key_exists('entity_type_id', $migration->getSourceConfiguration())) {
      return FALSE;
    }
    return in_array('Content', $migration->getMigrationTags(), TRUE) && (strpos($destination_plugin_id, 'entity_complete:') === 0 || strpos($destination_plugin_id, 'entity:') === 0 || strpos($destination_plugin_id, 'entity_revision:') === 0);
  }

  /**
   * Checks whether the given migration's destination is a config entity.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if this is a config entity migration, false otherwise.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  protected static function isConfigEntityDestination(Migration $migration) : bool {
    $destination_plugin_id = $migration->getDestinationPlugin()->getPluginId();
    return in_array('Configuration', $migration->getMigrationTags(), TRUE) && (strpos($destination_plugin_id, 'entity:') === 0 || in_array($destination_plugin_id, ['component_entity_display', 'component_entity_form_display'], TRUE));
  }

  /**
   * Returns the destination entity type ID.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration to parse.
   *
   * @return string|null
   *   The destination entity type ID, if the migration's destination is an
   *   entity, NULL otherwise.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  protected function getDestinationEntityTypeId(Migration $migration) {
    $destination_plugin = $migration->getDestinationPlugin();
    $destination_plugin_id = $destination_plugin->getPluginId();
    $destination_plugin_id_parts = explode($migration::DERIVATIVE_SEPARATOR, $destination_plugin_id);
    $entity_destination_base_plugin_ids = [
      'entity',
      'entity_revision',
      'entity_complete',
      'entity_reference_revisions',
    ];
    $entity_destination = in_array($destination_plugin_id_parts[0], $entity_destination_base_plugin_ids, TRUE);
    return $entity_destination
      ? $destination_plugin_id_parts[1]
      : NULL;
  }

}
