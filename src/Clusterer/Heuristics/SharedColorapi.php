<?php

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster for enabling Color API's field type.
 *
 * The field type 'colorapi_color' is provided by the Color API module
 * conditionally, as an opt-in feature. So before we even try to migrate jQuery
 * colorpicker fields, we should ensure that the corresponding field type is
 * available.
 *
 * This cluster must therefore be executed before the SharedEntityStructure
 * cluster (which contains the d7_field migration plugin).
 *
 * @see https://git.drupalcode.org/project/colorapi/-/blob/8.x-1.1/config/install/colorapi.settings.yml
 * @see https://git.drupalcode.org/project/colorapi/-/blob/8.x-1.1/colorapi.module#L55-64
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\SharedEntityStructure
 */
final class SharedColorapi implements IndependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id(): string {
    return 'enable_colorapi';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin): bool {
    return $migration_plugin->getBaseId() === 'enable_colorapi';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Shared structure for Color API fields';
  }

}
