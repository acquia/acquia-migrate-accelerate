<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster of bean block placement migrations.
 */
final class BeanBlockPlacements implements IndependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'bean_blocks';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Bean block placements';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    return in_array($migration_plugin->getBaseId(), [
      'bean_block',
      'bean_block_translation_et',
      'bean_block_translation_i18n',
    ], TRUE);
  }

}
