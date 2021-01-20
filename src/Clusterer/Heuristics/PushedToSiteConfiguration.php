<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Pushed to the "Site configuration" cluster.
 *
 * For example: system_site, d7_system_site_translation.
 */
final class PushedToSiteConfiguration implements DependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'site_config_pushed';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return SiteConfiguration::cluster();
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [SiteConfiguration::id()];
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    $dependencyless = empty($migration_plugin->getMetadata('after'));
    $clustered_dependees = array_intersect(
      array_merge(
        $dependent_heuristic_matches[SiteConfiguration::id()],
        $dependent_heuristic_matches[self::id()]
      ),
      $migration_plugin->getMigrationDependencies()['required']
    );
    $is_cluster_depender = !empty($clustered_dependees);

    // If this migration does not have any dependencies, then it's safe to
    // push up into the "Site configuration" cluster. It initially was not put
    // there because it is probably a config entity migration or simple
    // configuration with a translation.
    if ($dependencyless) {
      return TRUE;
    }
    // It's also safe to push this up if all dependencies in fact are already
    // present in the "Site configuration" cluster.
    elseif ($is_cluster_depender) {
      return TRUE;
    }

    return FALSE;
  }

}
