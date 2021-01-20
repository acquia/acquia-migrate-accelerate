<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster of "Block Placement" migrations.
 *
 * If AM:A does not explicitly create a "Block placements" group, "d7_block"
 * might be pushed into the "User accounts" cluster when "config_translation"
 * migrations are executable, but "content_translation" aren't.
 * Maybe in the future it will make to expose per-theme migrations, but
 * right now we're choosing to not yet do that. So, special case this one.
 *
 * @todo consider moving d7_theme_settings:<theme> migration plugins to
 * per-theme clusters.
 */
final class BlockPlacements implements IndependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'blocks';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Block placements';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    return $migration_plugin->getBaseId() === 'd7_block' ||
      $migration_plugin->getBaseId() === 'd7_block_translation';
  }

}
