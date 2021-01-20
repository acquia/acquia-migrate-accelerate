<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster per independent (unassociated with content) config entity migration.
 */
final class ConfigEntity implements DependentHeuristicInterface, HeuristicWithComputedClusterInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'config_entity';
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [
      SiteConfiguration::id(),
      PushedToSiteConfiguration::id(),
      ContentEntityBundlesDependencies::id(),
      ContentEntityDependingConfig::id(),
      ContentEntityIndependentButRelatedConfig::id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    assert(!empty($migration_plugin->getMetadata('after')));
    return static::isConfigEntityDestination($migration_plugin);
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin) : string {
    return (string) $migration_plugin->label();
  }

}
