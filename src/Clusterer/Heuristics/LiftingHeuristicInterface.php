<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

/**
 * A specialization of DependentHeuristicInterface, to inspect dependencies.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 */
interface LiftingHeuristicInterface extends DependentHeuristicInterface {

  /**
   * Injects all migration plugins, to allow inspecting the dependency tree.
   *
   * @param \Drupal\migrate\Plugin\Migration[] $all_migration_plugins
   *   All migration plugin.
   */
  public function provideAllMigrationPlugins(array $all_migration_plugins) : void;

}
