<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Adds depending configuration migrations to entity clusters.
 *
 * For example: d7_rdf_mapping:node:article,
 * d7_language_content_settings:article.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundles
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundlesDependencies}
 */
final class ContentEntityDependingConfig extends ContentEntityDepending {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'entity_depending_config';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    return in_array('Configuration', $migration_plugin->getMigrationTags(), TRUE) && parent::matches($migration_plugin, $dependent_heuristic_matches);
  }

}
