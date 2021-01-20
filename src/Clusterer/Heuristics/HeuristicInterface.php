<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

/**
 * A clusterer heuristic.
 *
 * Additional interfaces must be implemented to achieve a fully functional
 * heuristic that can be applied by MigrationClusterer::applyHeuristic().
 *
 * First, to assess a migration plugin against a clustering heuristic, it must
 * implement:
 * 1. IndependentHeuristicInterface (when whether it matches the heuristic
 *    depends only on information in the migration plugin that is assessed)
 * 2. DependentHeuristicInterface (when it is necessary to determine a match by
 *    inspecting prior heuristics' matches)
 * 2B. Special case of DependentHeuristicInterface: LiftingHeuristicInterface,
 *     which is granted all-encompassing access to all migration plugins. This
 *     is necessary for e.g. lifting migration plugins into a cluster by
 *     inspecting dependency trees.
 *
 * Second, to assign a cluster to the migration plugins that were assessed to be
 * a match, it must implement:
 * 1. HeuristicWithSingleClusterInterface (when all matching migration plugins
 *    get the same single cluster assigned — so no computing necessary)
 * 2. HeuristicWithComputedClusterInterface (when the cluster to assign must be
 *    computed from the migration plugin)
 * 3. special case: DependentHeuristicWithComputedDependentClusterInterface,
 *    which is available only for DependentHeuristicInterface implementations,
 *    which gets access to all available migration plugins to compute the
 *    cluster
 *
 * Generally, one is free to mix and match the heuristic matching interface and
 * cluster computing interface. Prefer the earlier listed ones over the later
 * listed ones, because those are moore strict.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\IndependentHeuristicInterface
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\DependentHeuristicInterface
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\LiftingHeuristicInterface
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicWithSingleClusterInterface
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicWithComputedClusterInterface
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\DependentHeuristicWithComputedDependentClusterInterface
 */
interface HeuristicInterface {

  /**
   * The clusterer heuristic's unique ID.
   *
   * @return string
   *   The heuristic ID.
   */
  public static function id() : string;

}
