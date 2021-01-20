<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster for configuration migrations that likely needs human attention.
 */
final class ConfigNeedingHuman implements IndependentHeuristicInterface, HeuristicWithComputedClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'config_needs_human';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    return $migration_plugin->id() === 'd7_filter_format';
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin) : string {
    return $migration_plugin->label();
  }

}
