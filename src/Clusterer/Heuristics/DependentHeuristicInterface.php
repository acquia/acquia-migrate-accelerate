<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * A clusterer heuristic matching strategy: based on prior heuristics' matches.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 */
interface DependentHeuristicInterface extends HeuristicInterface {

  /**
   * The heuristics that this heuristic depends on.
   *
   * @return string[]
   *   A list of heuristics IDs.
   */
  public function getDependencies() : array;

  /**
   * Assesses whether the given migration plugin matches this heuristic.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   A migration plugin.
   * @param string[] $dependent_heuristic_matches
   *   An array with heuristic IDs as keys and migration plugin IDs as values.
   *
   * @return bool
   *   Whether this heuristic matches.
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool;

}
