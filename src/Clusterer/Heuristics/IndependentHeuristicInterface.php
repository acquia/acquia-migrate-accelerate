<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * A clusterer heuristic matching strategy: based on migration plugin only.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 */
interface IndependentHeuristicInterface extends HeuristicInterface {

  /**
   * Assesses whether the given migration plugin matches this heuristic.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   A migration plugin.
   *
   * @return bool
   *   Whether this heuristic matches.
   */
  public function matches(MigrationPlugin $migration_plugin) : bool;

}
