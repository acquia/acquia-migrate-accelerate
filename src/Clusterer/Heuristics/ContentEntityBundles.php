<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster per content entity type + bundle (default translation only).
 *
 * For example: d7_node_complete:article, d7_taxonomy_term:tags.
 */
final class ContentEntityBundles implements IndependentHeuristicInterface, HeuristicWithComputedClusterInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'entity_bundles';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    return static::isContentEntityDefaultTranslationDestination($migration_plugin);
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin) : string {
    return (string) $migration_plugin->label();
  }

}
