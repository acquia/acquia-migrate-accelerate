<?php

declare(strict_types = 1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster for moderation flow migrations.
 */
class ModerationFlow implements IndependentHeuristicInterface, HeuristicWithComputedClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id(): string {
    return 'moderation_flow';
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin): string {
    return (string) $migration_plugin->label();
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin): bool {
    return $migration_plugin->getBaseId() === 'workbench_moderation_flow';
  }

}
