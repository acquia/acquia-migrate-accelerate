<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Last resort: a catch-all "Other" cluster.
 */
final class Other implements DependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'other';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Other';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    return TRUE;
  }

}
