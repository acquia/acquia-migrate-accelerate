<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Adds depending content migrations to entity clusters.
 *
 * For example: d7_url_alias:node:article.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundles
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundlesDependencies
 */
final class ContentEntityDependingContent extends ContentEntityDepending {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'entity_depending_content';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    return !in_array('Configuration', $migration_plugin->getMigrationTags(), TRUE) && parent::matches($migration_plugin, $dependent_heuristic_matches);
  }

}
