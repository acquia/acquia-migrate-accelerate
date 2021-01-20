<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

/**
 * A clusterer heuristic computing strategy: no computing (single cluster).
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 */
interface HeuristicWithSingleClusterInterface extends HeuristicInterface {

  /**
   * The cluster that this heuristic targets.
   *
   * @return string[]
   *   The single migration cluster that this heuristic targets.
   */
  public static function cluster() : string;

}
