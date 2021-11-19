<?php

declare(strict_types = 1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster for dependencies of moderation flow migrations.
 *
 * This usually pushes entity bundle (e.g. node_type) migrations.
 *
 * If there are no node moderation flow migrations then node type migrations are
 * pushed by ContentEntityBundlesDependencies (entity_bundles_dependencies)
 * into the corresponding node type migration cluster.
 */
final class PushedToModerationFlow implements DependentHeuristicWithComputedDependentClusterInterface, LiftingHeuristicInterface {

  /**
   * The available migration plugin instances.
   *
   * @var \Drupal\migrate\Plugin\Migration[]
   */
  protected $migrationPluginInstances;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'moderation_flow_pushed';
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [
      ModerationFlow::id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function provideAllMigrationPlugins(array $all_migration_plugins): void {
    $this->migrationPluginInstances = $all_migration_plugins;
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    // Return early if there are no moderation flow clusters.
    if (empty($flow_clusters = $dependent_heuristic_matches[ModerationFlow::id()])) {
      return FALSE;
    }

    $flow_migration_dependencies = array_reduce(
      $flow_clusters,
      function (array $carry, string $id): array {
        $carry = array_merge(
          $carry,
          $this->migrationPluginInstances[$id]->getMigrationDependencies()['required'] ?? []
        );
        return $carry;
      },
      []
    );

    return in_array($migration_plugin->id(), $flow_migration_dependencies, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration, array $dependent_heuristic_matches, array $unused): string {
    // Identify the corresponding flow migration.
    $corresponding_flow_migration = array_reduce(
      $dependent_heuristic_matches[ModerationFlow::id()],
      function (?string $carry, string $candidate_flow_migration_id) use ($migration): ?string {
        if (!$carry && array_search($candidate_flow_migration_id, $migration->getMetadata('before'))) {
          return $candidate_flow_migration_id;
        }
        return $carry;
      }
    );

    $corresponding_cluster = $this->migrationPluginInstances[$corresponding_flow_migration]->getMetadata('cluster');
    assert(is_string($corresponding_cluster));
    return $corresponding_cluster;
  }

}
