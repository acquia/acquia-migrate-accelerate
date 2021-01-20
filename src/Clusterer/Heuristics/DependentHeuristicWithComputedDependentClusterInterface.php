<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * A clusterer heuristic computing strategy: based on prior heuristics' matches.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 */
interface DependentHeuristicWithComputedDependentClusterInterface extends DependentHeuristicInterface {

  /**
   * Computes the cluster for a migration plugin selected by this heuristic.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   A migration plugin that was selected by this heuristic.
   * @param string[] $dependent_heuristic_matches
   *   An array with heuristic IDs as keys and migration plugin IDs as values.
   *   may or may not be used in the computation.
   * @param \Drupal\migrate\Plugin\Migration[] $all_migration_plugins
   *   All migration plugins. This may or may not be used in the computation.
   *
   * @return string
   *   A migration cluster.
   */
  public function computeCluster(MigrationPlugin $migration, array $dependent_heuristic_matches, array $all_migration_plugins) : string;

}
