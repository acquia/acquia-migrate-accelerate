<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\Component\Utility\NestedArray;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Pulls up dependencies into per-content entity type + bundle cluster.
 *
 * For example: d7_node_type:article, d7_taxonomy_vocabulary:tags.
 */
final class ContentEntityBundlesDependencies implements DependentHeuristicWithComputedDependentClusterInterface, LiftingHeuristicInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * All available migration plugins, to allow inspecting the dependency tree.
   *
   * @var \Drupal\migrate\Plugin\Migration[]
   */
  protected $allMigrationPlugins;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'entity_bundles_dependencies';
  }

  /**
   * {@inheritdoc}
   */
  private static function computeLookupTable(array $cluster_assignments, array $all_migration_plugin_instances) : array {
    static $lifted_migration_plugins;
    if (isset($lifted_migration_plugins)) {
      return $lifted_migration_plugins;
    }

    $already_clustered = array_flip(NestedArray::mergeDeepArray($cluster_assignments));

    $lifted_migration_plugins = [];
    foreach ($cluster_assignments[ContentEntityBundles::id()] as $migration_id) {
      $migration = $all_migration_plugin_instances[$migration_id];
      $entity_cluster = (string) $migration->label();
      if (!empty($migration->getMetadata('after'))) {
        $to_be_lifted_migration_plugin_ids = static::getRecursivelyRequiredMigrationDependenciesExcept($migration_id, $all_migration_plugin_instances, array_intersect_key($all_migration_plugin_instances, $lifted_migration_plugins), $already_clustered);
        $lifted_migration_plugins += array_combine(
          $to_be_lifted_migration_plugin_ids,
          array_fill(0, count($to_be_lifted_migration_plugin_ids), $entity_cluster)
        );
      }
    }
    return $lifted_migration_plugins;
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [
      ContentEntityBundles::id(),
      SharedLanguageConfig::id(),
      ConfigNeedingHuman::id(),
      SharedEntityStructure::id(),
      SharedEntityData::id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function provideAllMigrationPlugins(array $all_migration_plugins) : void {
    $this->allMigrationPlugins = $all_migration_plugins;
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    $lookup_table = static::computeLookupTable($dependent_heuristic_matches, $this->allMigrationPlugins);
    return isset($lookup_table[$migration_plugin->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration, array $dependent_heuristic_matches, array $all_migration_plugins) : string {
    $lookup_table = static::computeLookupTable($dependent_heuristic_matches, $all_migration_plugins);
    $target_cluster = $lookup_table[$migration->id()];
    return "LIFTED-$target_cluster";
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
  protected static function getRecursivelyRequiredMigrationDependencies(string $migration_plugin_id, array $all_migration_plugins) : array {
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

    if (!array_key_exists($migration_plugin_id, $recursive_calls)) {
      $recursive_calls[$migration_plugin_id] = $migration_plugin_id;
    }
    else {
      $base = explode(':', $migration_plugin_id)[0];
      $paragraphs_current_rev_migrations = [
        'd7_field_collection',
        'd7_paragraphs',
        'd7_pm_field_collection',
        'd7_pm_paragraphs',
      ];
      if (in_array($base, $paragraphs_current_rev_migrations, TRUE)) {
        $host_source_entity = explode(':', $migration_plugin_id)[1];
        // We can ignore recursive dependencies of paragraphs and field
        // collections migrations: the second entity reference level is pushed
        // into a common "Shared data for paragraphs and field collection items"
        // group, and all the related migration lookups are performed with
        // "migmag_lookup", which is able to identify the right migration
        // derivative to create and store the stub entity.
        if (in_array($host_source_entity, SharedEntityData::PARAGRAPHS_LEGACY_ENTITY_TYPE_IDS)) {
          return [];
        }
      }
      throw new \LogicException("Recursive limit reached in ::getRecursivelyRequiredMigrationDependencies() for the migration with '$migration_plugin_id' plugin ID.");
    }

    $deps = array_combine($required, $required);

    foreach ($deps as $dep) {
      $deps = array_merge($deps, static::getRecursivelyRequiredMigrationDependencies($dep, $all_migration_plugins));
    }
    unset($recursive_calls[$migration_plugin_id]);
    return array_unique($deps);
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
   * @param array $except
   *   Arrays of migration plugin IDs to exclude.
   *
   * @return array
   *   An array of migration plugin IDs.
   */
  protected static function getRecursivelyRequiredMigrationDependenciesExcept(string $migration_plugin_id, array $all_migration_plugins, array ...$except) : array {
    return array_diff_key(static::getRecursivelyRequiredMigrationDependencies($migration_plugin_id, $all_migration_plugins), ...$except);
  }

}
