<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Adds migration plugins depending on a content entity cluster to that cluster.
 *
 * For example: d7_menu_links:node:article.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundles
 */
final class ContentEntityIndependentButRelatedContent extends ContentEntityIndependentButRelated {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'entity_related_content';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    return !in_array('Configuration', $migration_plugin->getMigrationTags(), TRUE) && parent::matches($migration_plugin, $dependent_heuristic_matches);
  }

}
