<?php

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster of Vote type migrations.
 *
 * Fivestar field storage migration must depend on d7_vote_type and on
 * fivestar_vote_type migrations, because unlike any other entity reference
 * fields, fivestar fields are storing the vote type restriction on field
 * storage level, and not on field instance level.
 *
 * This cluster must therefore be executed before the SharedEntityStructure
 * cluster (which contains the d7_field migration plugin).
 *
 * @see https://git.drupalcode.org/project/fivestar/-/blob/8.x-1.0-alpha2/config/schema/fivestar.schema.yml#L41-47
 * @see \Drupal\fivestar\Plugin\migrate\field\FivestarField::alterFieldMigration()
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\SharedEntityStructure
 */
final class SharedVoteTypeConfig implements IndependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id(): string {
    return 'vote_type';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin): bool {
    return in_array(
      $migration_plugin->getBaseId(),
      ['d7_vote_type', 'fivestar_vote_type'],
      TRUE
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Shared structure for Vote types';
  }

}
