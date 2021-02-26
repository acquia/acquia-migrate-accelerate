<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Adds migration plugins depending on a content entity cluster to that cluster.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundles
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundlesDependencies
 */
abstract class ContentEntityDepending implements DependentHeuristicWithComputedDependentClusterInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * {@inheritdoc}
   */
  abstract public static function id() : string;

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [ContentEntityBundles::id(), ContentEntityBundlesDependencies::id()];
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    $entity_migrations_plus_dependencies = array_merge(
      $dependent_heuristic_matches[ContentEntityBundles::id()],
      $dependent_heuristic_matches[ContentEntityBundlesDependencies::id()],
      // F.e.: d7_comment_entity_display depends on d7_comment_field_instance.
      $dependent_heuristic_matches[static::id()]
    );
    $required_dependencies = $migration_plugin->getMigrationDependencies()['required'];
    $clustered_dependees = array_intersect($entity_migrations_plus_dependencies, $required_dependencies);
    $is_cluster_depender = !empty($clustered_dependees);
    $is_non_dependee = empty($migration_plugin->getMetadata('before'));
    $is_pure_cluster_depender = $is_cluster_depender && count($clustered_dependees) === count($required_dependencies);

    return $is_cluster_depender && ($is_pure_cluster_depender || $is_non_dependee);
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches, array $all_migration_plugins) : string {
    $entity_migrations_plus_dependencies = array_merge(
      $dependent_heuristic_matches[ContentEntityBundles::id()],
      $dependent_heuristic_matches[ContentEntityBundlesDependencies::id()],
      $dependent_heuristic_matches[static::id()]
    );
    $clustered_dependees = array_intersect($entity_migrations_plus_dependencies, $migration_plugin->getMigrationDependencies()['required']);

    // When there are multiple clustered dependees, try picking one that is
    // more stable (less dependent on the outcome of core's migration plugin
    // graph-based sorting), by excluding the entity clusters.
    $stable_clustered_dependees = array_diff($clustered_dependees, array_keys($entity_migrations_plus_dependencies));
    if (!empty($stable_clustered_dependees) && count($stable_clustered_dependees) < count($clustered_dependees)) {
      if (count($stable_clustered_dependees) >= 1) {
        // @codingStandardsIgnoreStart
        \Drupal::logger('acquia_migrate')->debug('The @migration-id migration has multiple clustered dependees (@dependees), but even the stable subset (@stable-dependees) contains multiple cluster choices to push towards. Consider adding more heuristics for clustering, to avoid unstable cluster assignment. The stability of the current heuristic can be evaluated by analyzing these debug messages and observing whether the parentheticals ever have different orders.', [
          '@migration-id' => $migration_plugin->id(),
          '@dependees' => implode(', ', $clustered_dependees),
          '@stable-dependees' => implode(', ', $stable_clustered_dependees),
        ]);
        // @codingStandardsIgnoreEnd
      }
      $clustered_dependees = $stable_clustered_dependees;
    }
    // Push up towards the clustered dependee that runs last, to ensure all
    // dependencies continue to have been met by the time it will run.
    $migration_to_push_towards = end($clustered_dependees);
    // Look up the cluster of the dependee it's getting pushed to run after
    // and assign this same cluster.
    $cluster_to_push_towards = $all_migration_plugins[$migration_to_push_towards]->getMetadata('cluster');
    return (string) $cluster_to_push_towards;
  }

}
