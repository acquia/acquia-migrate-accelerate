<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Adds translation migration plugins to entity type + bundle clusters.
 *
 * For example: d7_taxonomy_term_translation:tags.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\ContentEntityBundles
 */
final class ContentEntityTranslations implements DependentHeuristicWithComputedDependentClusterInterface {

  use EntityRelatedHeuristicTrait;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'entity_translations';
  }

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
    if (!static::isContentEntityTranslationDestination($migration_plugin)) {
      return FALSE;
    }

    $all_dependencies = array_merge(
      $migration_plugin->getMigrationDependencies()['required'] ?? [],
      $migration_plugin->getMigrationDependencies()['optional'] ?? []
    );
    $entity_bundle_migrations = $dependent_heuristic_matches[ContentEntityBundles::id()];
    return !empty(array_intersect($all_dependencies, $entity_bundle_migrations));
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin, array $dependent_heuristic_matches, array $all_migration_plugins) : string {
    $entity_bundle_migrations = $dependent_heuristic_matches[ContentEntityBundles::id()];

    $entity_migration_deps_required = array_intersect_key(
      $all_migration_plugins,
      array_flip($entity_bundle_migrations),
      array_flip($migration_plugin->getMigrationDependencies()['required'] ?? [])
    );
    $entity_migration_deps_optional = array_intersect_key(
      $all_migration_plugins,
      array_flip($entity_bundle_migrations),
      array_flip($migration_plugin->getMigrationDependencies()['optional'] ?? [])
    );

    if (count($entity_migration_deps_required) !== 0) {
      $default_migration = static::getDefaultMigration($migration_plugin, $entity_migration_deps_required);

    }
    elseif (count($entity_migration_deps_required) === 0 && count($entity_migration_deps_optional) !== 0) {
      $default_migration = static::getDefaultMigration($migration_plugin, $entity_migration_deps_optional);
    }
    else {
      throw new \LogicException();
    }

    return $default_migration->getMetadata('cluster');
  }

  /**
   * Returns default migration while trying to match the destination plugin.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   A migration plugin that was selected by this heuristic.
   * @param \Drupal\migrate\Plugin\Migration[] $entity_migration_deps
   *   Migration dependencies (optional or required).
   *
   * @return \Drupal\migrate\Plugin\Migration
   *   Appropriate migration plugin that is selected to provide cluster.
   */
  protected static function getDefaultMigration(MigrationPlugin $migration_plugin, array $entity_migration_deps): MigrationPlugin {
    $destination_plugin = $migration_plugin->getPluginDefinition()['destination']['plugin'];
    foreach ($entity_migration_deps as $entity_migration_dep) {
      $migration_plugin = $entity_migration_dep;
      if ($migration_plugin->getPluginDefinition()['destination']['plugin'] === $destination_plugin) {
        return $entity_migration_dep;
      }
    }
    return reset($entity_migration_deps);
  }

}
