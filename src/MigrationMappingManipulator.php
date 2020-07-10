<?php

namespace Drupal\acquia_migrate;

use Drupal\acquia_migrate\Plugin\MigrationPluginManager;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate_plus\Entity\Migration as MigrationConfigEntity;

/**
 * Serves the Acquia Migrate mapping manipulator.
 *
 * @internal
 */
final class MigrationMappingManipulator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger to use.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\acquia_migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * MigrationMappingManipulator constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger to use.
   * @param \Drupal\acquia_migrate\Plugin\MigrationPluginManager $migration_plugin_manager
   *   The migration plugin manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelInterface $logger, MigrationPluginManager $migration_plugin_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->migrationPluginManager = $migration_plugin_manager;
  }

  /**
   * Helper method that allows checking our assumptions about upstream.
   *
   * Specifically, this checks assumptions that code in this module has about
   * \Drupal\migrate_plus\Entity\Migration — which is maintained in a contrib
   * module. That module could change at any time. This allows us being notified
   * as soon as possible about upstream changes that would break this module.
   *
   * For example, https://www.drupal.org/project/migrate_plus/issues/2944627
   * will change this (if it ever lands).
   *
   * @return bool
   *   Whether the upstream assumptions still hold or not.
   */
  private function checkUpstreamAssumptions() : bool {
    $supported_upstream_keys = $this->entityTypeManager->getDefinition('migration')
      ->get('config_export');
    $expected_upstream_keys = [
      'id',
      'class',
      'field_plugin_method',
      'cck_plugin_method',
      'migration_tags',
      'migration_group',
      'status',
      'label',
      'source',
      'process',
      'destination',
      'migration_dependencies',
    ];
    return $supported_upstream_keys === $expected_upstream_keys;
  }

  /**
   * Checks whether a migration plugin instance has an overriding config entity.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   A migration plugin instance to check.
   *
   * @return bool
   *   Whether the migration plugin instance has an override or not.
   */
  public function isOverridden(MigrationPlugin $migration_plugin) : bool {
    return $this->getOverride($migration_plugin) !== NULL;
  }

  /**
   * Gets the overriding config entity for the given migration plugin instance.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   A migration plugin instance to check.
   *
   * @return \Drupal\migrate_plus\Entity\Migration
   *   The overriding config entity.
   */
  public function getOverride(MigrationPlugin $migration_plugin) : ?MigrationConfigEntity {
    return MigrationConfigEntity::load($this->migrationPluginManager->mapMigrationPluginIdToMigrationConfigEntityId($migration_plugin->id()));
  }

  /**
   * Converts a migration plugin instance to an overriding config entity.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin instance for which to create an overriding config
   *   entity.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The overriding config entity. Powered by the contributed migrate_plus
   *   module. (This is an implementation detail that might change.)
   *
   * @todo Assess whether we truly want to depend on the migrate_plus module for this. Its migration config entity doesn't provide significant infrastructure, so it'd be easy to provide our own. Especially considering we also need to undo much of what migrate_plus_migration_plugins_alter() does.
   * @see \Drupal\acquia_migrate\Plugin\MigrationPluginManager::alterDefinitions()
   * @see migrate_plus_migration_plugins_alter()
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function convertMigrationPluginInstanceToConfigEntity(MigrationPlugin $migration_plugin) : ConfigEntityInterface {
    $id = $migration_plugin->id();
    if (!$this->checkUpstreamAssumptions()) {
      throw new \LogicException('\Drupal\migrate_plus\Entity\Migration has changed in an incompatible way!');
    }
    if ($this->getOverride($migration_plugin)) {
      throw new \LogicException(sprintf("The %s migration has already been converted to a config entity."));
    }
    $this->logger->info('Migration plugin "@migration-plugin-id" converted to migration config entity "@migration-config-entity-id".', [
      '@migration-plugin-id' => $id,
      '@migration-config-entity-id' => $id,
    ]);
    $migration_config_entity = MigrationConfigEntity::createEntityFromPlugin($id, MigrationPluginManager::mapMigrationPluginIdToMigrationConfigEntityId($id));
    $migration_config_entity->save();
    return $migration_config_entity;
  }

  /**
   * Deletes a migration plugin instance-overriding config entity.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin instance for which to delete the overriding config
   *   entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function deleteConfigEntityForMigrationPluginInstance(MigrationPlugin $migration_plugin) {
    $migration_config = $this->getOverride($migration_plugin);
    $migration_config->delete();
    // @todo the saving of Migration config entities should invalidate this automatically … 😨 File upstream bug report + patch against `migrate_plus`.
    Cache::invalidateTags(['migration_plugins']);
    $this->migrationPluginManager->clearCachedDefinitions();
  }

  /**
   * Detects overrides in the process pipeline.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   A data migration plugin instance.
   *
   * @return array
   *   An array containing 3 arrays:
   *   1. an array of destination field names of those fields whose processing
   *      has been modified
   *   2. an array of destination field names of those fields whose processing
   *      has been removed
   *   3. an array of destination field names of those fields whose processing
   *      has been added
   */
  public function getProcessPipelineOverrides(MigrationPlugin $data_migration_plugin) : array {
    $modified_processing = [];
    $removed_processing = [];
    $added_processing = [];

    if ($this->migrationPluginManager->hasConfigOverride($data_migration_plugin->id())) {
      $original_process_pipeline = $this->migrationPluginManager->getOriginal($data_migration_plugin->id())->getProcess();
      $current_process_pipeline = $data_migration_plugin->getProcess();
      foreach ($current_process_pipeline as $destination_field_name => $pipeline) {
        if ($pipeline !== $original_process_pipeline[$destination_field_name]) {
          $modified_processing[] = $destination_field_name;
        }
      }
      $removed_processing = array_keys(array_diff_key($original_process_pipeline, $current_process_pipeline));
      $added_processing = array_keys(array_diff_key($current_process_pipeline, $original_process_pipeline));
    }

    return [
      $modified_processing,
      $removed_processing,
      $added_processing,
    ];
  }

  /**
   * Checks whether the given destination field name has overridden processing.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   A data migration plugin.
   * @param string $destination_field_name
   *   A field name.
   *
   * @return bool
   *   Whether the given destination field has overridden processing or not.
   */
  public function hasOverriddenProcessPipeline(MigrationPlugin $data_migration_plugin, string $destination_field_name) : bool {
    assert($this->isOverridden($data_migration_plugin));

    [$modified, $removed, $added] = $this->getProcessPipelineOverrides($data_migration_plugin);
    $all_overrides = array_merge($modified, $removed, $added);

    return in_array($destination_field_name, $all_overrides, TRUE);
  }

  /**
   * Overrides a process pipeline by dropping a source field.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   The data migration plugin whose process pipeline to override.
   * @param string $destination_field_name
   *   The destination field name for which to drop the migration from the
   *   source.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function dropSourceField(MigrationPlugin $data_migration_plugin, string $destination_field_name) {
    $migration_config = $this->getOverride($data_migration_plugin);
    $original = $this->migrationPluginManager->getOriginal($data_migration_plugin->id());

    $overridden_process = $migration_config->get('process');
    $original_process = $original->getProcess();

    if (!array_key_exists($destination_field_name, $original_process)) {
      throw new \InvalidArgumentException(sprintf('The `%s` destination field name is absent in the original migration plugin definition.', $destination_field_name), 400);
    }

    if (!array_key_exists($destination_field_name, $overridden_process)) {
      throw new \InvalidArgumentException(sprintf('The `%s` destination field name has already been dropped.', $destination_field_name), 400);
    }

    unset($overridden_process[$destination_field_name]);
    $migration_config->set('process', $overridden_process);
    $migration_config->trustData();
    $migration_config->save();
  }

  /**
   * Reverts an override from the overridden process pipeline.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   The data migration plugin whose process pipeline to override.
   * @param string $destination_field_name
   *   The destination field name for which to revert the override.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function revertProcessPipelineOverride(MigrationPlugin $data_migration_plugin, string $destination_field_name) {
    $migration_config = $this->getOverride($data_migration_plugin);
    $original = $this->migrationPluginManager->getOriginal($data_migration_plugin->id());

    $overridden_process = $migration_config->get('process');
    $original_process = $original->getProcess();

    if (!array_key_exists($destination_field_name, $original_process)) {
      throw new \InvalidArgumentException(sprintf('The `%s` destination field name is absent in the original migration plugin definition.', $destination_field_name), 400);
    }

    $overridden_process[$destination_field_name] = $original_process[$destination_field_name];
    $migration_config->set('process', $overridden_process);
    $migration_config->trustData();
    $migration_config->save();
  }

}
