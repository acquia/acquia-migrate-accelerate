<?php

namespace Drupal\acquia_migrate;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Interprets migration plugins (or definitions) for Acquia Migrate.
 *
 * @internal
 */
final class MigrationPluginInterpreter {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MigrationPluginInterpreter.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Gets all migrations for derived config entity bundle types.
   *
   * @param array[] $migration_plugin_definitions
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityBundleBase
   */
  public function getDerivedConfigEntityBundleMigrationPluginDefinitions(array $migration_plugin_definitions) {
    $derived_config_entity_bundle_migrations = [];
    foreach ($migration_plugin_definitions as $id => $definition) {
      $destination_plugin_id = $definition['destination']['plugin'] ?? NULL;
      if (
        !$destination_plugin_id ||
        strpos($destination_plugin_id, PluginBase::DERIVATIVE_SEPARATOR) === FALSE
      ) {
        continue;
      }
      if (count(explode(PluginBase::DERIVATIVE_SEPARATOR, $id)) !== 2) {
        continue;
      }
      $destination_entity_type = explode(PluginBase::DERIVATIVE_SEPARATOR, $destination_plugin_id)[0] === 'entity'
        ? explode(PluginBase::DERIVATIVE_SEPARATOR, $destination_plugin_id)[1]
        : NULL;
      if (!$destination_entity_type) {
        continue;
      }
      // Only consider migrations that have a config entity type as their
      // destination.
      $destination_entity_type_definition = $this->entityTypeManager->getDefinition($destination_entity_type, FALSE);
      if (!($destination_entity_type_definition instanceof ConfigEntityTypeInterface)) {
        continue;
      }

      $bundle_of = $destination_entity_type_definition->getBundleOf();
      if (!empty($bundle_of)) {
        $derived_config_entity_bundle_migrations[$id] = $definition;
      }
    }

    return $derived_config_entity_bundle_migrations;
  }

}
