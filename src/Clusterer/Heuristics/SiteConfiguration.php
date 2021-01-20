<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * The "Site configuration" cluster.
 */
final class SiteConfiguration implements DependentHeuristicInterface, HeuristicWithSingleClusterInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'site_config';
  }

  /**
   * {@inheritdoc}
   */
  public static function cluster() : string {
    return 'Site configuration';
  }

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [
      ConfigNeedingHuman::id(),
      SharedLanguageConfig::id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    $id = $migration_plugin->id();
    return !in_array($id, $dependent_heuristic_matches[ConfigNeedingHuman::id()], TRUE) &&
      empty($migration_plugin->getMetadata('before')) &&
      empty($migration_plugin->getMetadata('after')) &&
      self::getDestinationEntityTypeId($migration_plugin) === NULL &&
      !in_array($id, $dependent_heuristic_matches[SharedLanguageConfig::id()], TRUE);
  }

}
