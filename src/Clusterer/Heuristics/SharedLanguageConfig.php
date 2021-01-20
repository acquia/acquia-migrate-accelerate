<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Cluster of multilingual-related settings and configuration migrations.
 */
final class SharedLanguageConfig implements IndependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'lang';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Language settings';
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    $language_config_migrations = [
      'language',
      'default_language',
      'd7_language_types',
      'd7_language_negotiation_settings',
      'language_prefixes_and_domains',
    ];
    return in_array($migration_plugin->id(), $language_config_migrations, TRUE);
  }

}
