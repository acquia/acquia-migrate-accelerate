<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * A clusterer heuristic computing strategy: based on migration plugin only.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 */
interface HeuristicWithComputedClusterInterface extends HeuristicInterface {

  /**
   * Computes the cluster for a migration plugin selected by this heuristic.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   A migration plugin that was matched by this heuristic.
   *
   * @return string
   *   A migration cluster.
   */
  public function computeCluster(MigrationPlugin $migration_plugin) : string;

}
