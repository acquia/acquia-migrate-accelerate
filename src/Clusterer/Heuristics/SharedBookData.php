<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster for book outlines (shared data across node types used in books).
 *
 * @see book_schema()
 */
final class SharedBookData implements IndependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'book';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Book outlines';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    return $migration_plugin->id() === 'd7_book';
  }

}
