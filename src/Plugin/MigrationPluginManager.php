<?php

namespace Drupal\acquia_migrate\Plugin;

use Drupal\Component\Graph\Graph;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\migrate\Plugin\migrate\destination\Entity;
use Drupal\migrate\Plugin\MigrateSourcePluginManager;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_drupal\MigrationPluginManager as BaseMigrationPluginManager;

/**
 * Plugin manager for migration plugins.
 *
 * Analyzes dependencies, orders migrations to maximize the number of low-risk
 * (zero dependencies) migrations first.
 */
class MigrationPluginManager extends BaseMigrationPluginManager {

  /**
   * MigrationPluginManager constructor.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $decorated
   *   The migration plugin manager we decorate.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\migrate\Plugin\MigrateSourcePluginManager $source_manager
   *   The Migrate source plugin manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(MigrationPluginManagerInterface $decorated, ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend, LanguageManagerInterface $language_manager, MigrateSourcePluginManager $source_manager, ConfigFactoryInterface $config_factory) {
    parent::__construct($module_handler, $cache_backend, $language_manager, $source_manager, $config_factory);
  }

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions() {
    $definitions = parent::findDefinitions();
    // Ensure deterministic results by sorting all found definitions by ID. This
    // ensures deterministic results even after multiple rounds of processing,
    // for example after sorting by dependencies.
    ksort($definitions);
    // Ensure the "classic" entity migrations are omitted in favor of their
    // "complete" successors.
    $definitions = $this->filterNonCompleteWhenCompleteExistsDefinitions($definitions);
    return $definitions;
  }

  /**
   * Omits entity(_revision):* migrations when entity_complete:* exists.
   *
   * @param array $definitions
   *   List of migration plugin definitions.
   *
   * @return array
   *   List of filtered migration plugin definitions.
   */
  protected function filterNonCompleteWhenCompleteExistsDefinitions(array $definitions) {
    return array_filter($definitions, function ($definition, $id) use ($definitions) {
      $destination_plugin_id = $definition['destination']['plugin'];
      $has_entity_destination = strpos($destination_plugin_id, 'entity:') === 0 || strpos($destination_plugin_id, 'entity_revision:') === 0;
      if ($has_entity_destination) {
        $base_plugin_id = $definition['id'];
        // Check if the "entity_complete" sibling migration exists.
        $complete_entity_migration_id = str_replace($base_plugin_id, $base_plugin_id . '_complete', $id);
        $complete_entity_migration_id = str_replace([
          'entity_translation_',
          'translation_',
          'revision_',
        ], '', $complete_entity_migration_id);
        if (isset($definitions[$complete_entity_migration_id])) {
          assert(substr($definitions[$complete_entity_migration_id]['destination']['plugin'], 0, 16) === 'entity_complete:');
          return FALSE;
        }
      }
      return TRUE;
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Builds a dependency tree for the migrations and set their order.
   *
   * 90% copy of parent implementation, with tweaks to generate a smarter
   * migration ordering.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface[] $migrations
   *   Array of loaded migrations with their declared dependencies.
   * @param array $dynamic_ids
   *   Keys are dynamic ids (for example node:*) values are a list of loaded
   *   migration ids (for example node:page, node:article).
   * @param bool $only_migration_with_requirements_met
   *   Set to TRUE when the passed $migrations argument contains only migrations
   *   that have their source and destination requirements met.
   *
   * @return array
   *   An array of migrations.
   */
  public function buildDependencyMigration(array $migrations, array $dynamic_ids, $only_migration_with_requirements_met = FALSE) {
    // Migration dependencies can be optional or required. If an optional
    // dependency does not run, the current migration is still OK to go. Both
    // optional and required dependencies (if run at all) must run before the
    // current migration.
    $dependency_graph = [];
    $required_dependency_graph = [];
    $have_optional = FALSE;
    foreach ($migrations as $migration) {
      /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
      $id = $migration->id();
      $dependency_graph[$id]['edges'] = [];
      $migration_dependencies = $migration->getMigrationDependencies();

      if (isset($migration_dependencies['required'])) {
        foreach ($migration_dependencies['required'] as $dependency) {
          if (!isset($dynamic_ids[$dependency])) {
            $this->addDependency($required_dependency_graph, $id, $dependency, $dynamic_ids);
          }
          $this->addDependency($dependency_graph, $id, $dependency, $dynamic_ids);
        }
      }
      if (!empty($migration_dependencies['optional'])) {
        foreach ($migration_dependencies['optional'] as $dependency) {
          $this->addDependency($dependency_graph, $id, $dependency, $dynamic_ids);
        }
        $have_optional = TRUE;
      }
    }
    $dependency_graph = (new Graph($dependency_graph))->searchAndSort();
    if ($have_optional) {
      $required_dependency_graph = (new Graph($required_dependency_graph))->searchAndSort();
    }
    else {
      $required_dependency_graph = $dependency_graph;
    }
    $weights = [];
    foreach ($migrations as $migration_id => $migration) {
      // Populate a weights array to use with array_multisort() later.
      $weight = $dependency_graph[$migration_id]['weight'];
      if (!empty($required_dependency_graph[$migration_id]['paths'])) {
        $migration->set('requirements', $required_dependency_graph[$migration_id]['paths']);
      }

      // Enrich with complete dependency metadata. Key-value pairs where both
      // key and value are always the same (a migration plugin ID).
      // @see \Drupal\acquia_migrate\Controller\Overview::getAvailableMigrationsSortedByCluster
      $migration->setMetadata('after', $dependency_graph[$migration_id]['paths']);
      if (!empty($dependency_graph[$migration_id]['reverse_paths'])) {
        $migration_ids = array_keys($dependency_graph[$migration_id]['reverse_paths']);
        $migration->setMetadata('before', array_combine($migration_ids, $migration_ids));
      }
      else {
        $migration->setMetadata('before', []);
      }
      assert(array_keys($migration->getMetadata('after')) == array_values($migration->getMetadata('after')));
      assert(array_keys($migration->getMetadata('before')) == array_values($migration->getMetadata('before')));

      // Category.
      $category = NULL;
      // @todo The 'Configuration' tag may not be perfectly reliable, we should consider inspecting the entity destination plugin's entity type ID: check whether it is ConfigEntityType.
      if (in_array('Configuration', $migration->getMigrationTags())) {
        // Migrations for configuration entities can have migration dependencies
        // whereas migrations of simple configuration cannot.
        $category = ($migration->getDestinationPlugin() instanceof Entity || in_array($migration->getDestinationPlugin()->getPluginId(), ['component_entity_display', 'component_entity_form_display'], TRUE))
          ? 'Configuration entity'
          : 'Simple configuration';
      }
      elseif (in_array('Content', $migration->getMigrationTags())) {
        $category = 'Content';
      }
      else {
        $category = 'Other';
      }
      $migration->setMetadata('category', $category);

      // Prioritize migrations on which nothing else depends.
      if ($weight === 0 && empty($dependency_graph[$migration_id]['reverse_paths'])) {
        $weight = 1000;
        // And especially those that are just simple configuration.
        if ($migration->getMetadata('category') === 'Simple configuration') {
          $weight = 2000;
        }
      }

      if ($only_migration_with_requirements_met) {
        if ($migration->allRowsProcessed() === TRUE && $migration->getSourcePlugin()->count() === 0) {
          $weight += 9999;
          $migration->setMetadata('category', 'No data');
        }
      }

      $weights[] = $weight;
    }
    // Sort weights, labels, and keys in the same order as each other.
    array_multisort(
    // Use the numerical weight as the primary sort.
      $weights, SORT_DESC, SORT_NUMERIC,
      // When migrations have the same weight, sort them alphabetically by ID.
      array_keys($migrations), SORT_ASC, SORT_NATURAL,
      $migrations
    );

    return $migrations;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterDefinitions(&$definitions) {
    $pre_alter_definitions = $definitions;
    parent::alterDefinitions($definitions);

    $removed_definitions = array_diff_key($pre_alter_definitions, $definitions);
    foreach (array_keys($removed_definitions) as $migration_plugin_id) {
      if (strpos($migration_plugin_id, 'migration_config_deriver:') === 0) {
        $overriding_migration_plugin_id = substr($migration_plugin_id, strlen('migration_config_deriver:'));
        $original_migration_plugin_id = static::mapConfigEntityIdToDerivedPluginId($overriding_migration_plugin_id);
        // Override the overriding of migrate_plus_migration_plugins_alter().
        // @see \Drupal\acquia_migrate\MigrationMappingManipulator::convertMigrationPluginInstanceToConfigEntity()
        $definitions[$original_migration_plugin_id] = $definitions[$overriding_migration_plugin_id];
        $definitions['original___' . $original_migration_plugin_id] = $pre_alter_definitions[$original_migration_plugin_id];
      }
    }
  }

  /**
   * Checks whether the given migration plugin ID has an override.
   *
   * @param string $migration_plugin_id
   *   A migration plugin ID.
   *
   * @return bool
   *   Whether the migration plugin ID has an override or not.
   */
  public function hasConfigOverride(string $migration_plugin_id) : bool {
    return $this->hasDefinition('original___' . $migration_plugin_id);
  }

  /**
   * Returns the original instance for an overridden migration plugin ID.
   *
   * @param string $migration_plugin_id
   *   A migration plugin ID.
   *
   * @return \Drupal\migrate\Plugin\Migration
   *   A migration plugin instance.
   */
  public function getOriginal(string $migration_plugin_id) : MigrationPlugin {
    if (!$this->hasConfigOverride($migration_plugin_id)) {
      throw new \InvalidArgumentException(sprintf("The migration plugin %s does not have a config override.", $migration_plugin_id));
    }

    return $this->getFactory()->createInstance('original___' . $migration_plugin_id);
  }

  /**
   * Maps a (derived) migration plugin ID to a migration config entity ID.
   *
   * @param string $migration_plugin_id
   *   A migration plugin ID.
   *
   * @return string
   *   The corresponding migration config entity ID.
   */
  public static function mapMigrationPluginIdToMigrationConfigEntityId(string $migration_plugin_id) : string {
    return str_replace(':', '__', $migration_plugin_id);
  }

  /**
   * Maps a migration config entity ID to a (derived) migration plugin ID.
   *
   * @param string $migration_config_entity_id
   *   A migration config entity ID.
   *
   * @return string
   *   The corresponding migration entity ID.
   */
  public static function mapConfigEntityIdToDerivedPluginId(string $migration_config_entity_id) : string {
    return str_replace('__', ':', $migration_config_entity_id);
  }

}
