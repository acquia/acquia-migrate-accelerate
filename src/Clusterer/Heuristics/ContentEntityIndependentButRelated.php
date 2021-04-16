<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\Component\Plugin\PluginBase;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Adds migration plugins related to a content entity cluster to that cluster.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundles}
 */
abstract class ContentEntityIndependentButRelated implements DependentHeuristicWithComputedDependentClusterInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * {@inheritdoc}
   */
  abstract public static function id() : string;

  /**
   * {@inheritdoc}
   */
  public function getDependencies() : array {
    return [ContentEntityBundles::id()];
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches) : bool {
    [
      'entity_type' => $entity_type_param,
      'bundle' => $bundle_param,
    ] = self::getMigrationSourceEntityParameters($migration_plugin);

    // If a migration has bundle param, but no entity_type param, then we try
    // to determine the entity_type based on the destination.
    if (!$entity_type_param && self::isContentEntityDestination($migration_plugin)) {
      $entity_type_param = self::getDestinationEntityTypeId($migration_plugin);
    }

    if (!$entity_type_param) {
      return FALSE;
    }

    $candidate_bundle_param = !empty($bundle_param) ? (string) $bundle_param : NULL;
    $candidates = static::getCandidateTargetMigrationPluginIds($entity_type_param, $candidate_bundle_param);
    $matches = array_intersect($candidates, $dependent_heuristic_matches[ContentEntityBundles::id()]);
    return !empty($matches);
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches, array $all_migration_plugins) : string {
    [
      'entity_type' => $entity_type_param,
      'bundle' => $bundle_param,
    ] = self::getMigrationSourceEntityParameters($migration_plugin);

    // If a migration has bundle param, but no entity_type param, then we try
    // to determine the entity_type based on the destination.
    if (!$entity_type_param && self::isContentEntityDestination($migration_plugin)) {
      $entity_type_param = self::getDestinationEntityTypeId($migration_plugin);
    }

    $candidate_bundle_param = !empty($bundle_param) ? (string) $bundle_param : NULL;
    $candidates = static::getCandidateTargetMigrationPluginIds($entity_type_param, $candidate_bundle_param);
    $matches = array_intersect($candidates, $dependent_heuristic_matches[ContentEntityBundles::id()]);

    // Lift into the first matching migration plugin's cluster.
    $target_migration_plugin_id = reset($matches);
    $target_migration_plugin = $all_migration_plugins[$target_migration_plugin_id];
    $target_cluster = $target_migration_plugin->getMetadata('cluster');
    return "LIFTED-$target_cluster";
  }

  /**
   * Gets the candidate target migration plugin IDs for an entity type + bundle.
   *
   * @param string $entity_type_id
   *   An entity type ID.
   * @param string|null $bundle
   *   (optional) A bundle.
   *
   * @return string[]
   *   A list of potential migration plugins based on the given entity type ID
   *   and bundle.
   */
  private static function getCandidateTargetMigrationPluginIds(string $entity_type_id, ?string $bundle) : array {
    $candidates = [];
    if ($bundle) {
      // Per-bundle derived "regular" and "complete" migrations.
      // Example: "d7_node_complete:article".
      $candidates[] = implode(PluginBase::DERIVATIVE_SEPARATOR, [
        "d7_{$entity_type_id}_complete",
        $bundle,
      ]);
      // Example: "d7_taxonomy_term:tags".
      $candidates[] = implode(PluginBase::DERIVATIVE_SEPARATOR, [
        "d7_$entity_type_id",
        $bundle,
      ]);
      // Example: "bean:image".
      $candidates[] = implode(PluginBase::DERIVATIVE_SEPARATOR, [
        $entity_type_id,
        $bundle,
      ]);
    }
    // "All bundle" regular and complete migrations.
    $candidates[] = "d7_{$entity_type_id}_complete";
    // Example: "d7_user".
    $candidates[] = "d7_$entity_type_id";
    // Example: "bean".
    $candidates[] = $entity_type_id;
    return $candidates;
  }

}
