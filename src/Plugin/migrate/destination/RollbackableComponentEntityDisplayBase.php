<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Display\EntityDisplayInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\migrate\Plugin\migrate\destination\ComponentEntityDisplayBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for rollbackable per-component entity display destination plugins.
 *
 * Provides a rollbackable base class for entity display destination plugins per
 * component (field).
 *
 * @see \Drupal\migrate\Plugin\migrate\destination\ComponentEntityDisplay
 *
 * @internal
 */
abstract class RollbackableComponentEntityDisplayBase extends ComponentEntityDisplayBase implements RollbackableInterface {

  /**
   * {@inheritdoc}
   */
  protected $supportsRollback = TRUE;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs RollbackableComponentEntityDisplayBase.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository service.
   * @param \Drupal\Core\Database\Connection $connection
   *   A Database connection to use for reading migration messages.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, EntityDisplayRepositoryInterface $entity_display_repository = NULL, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $entity_display_repository);

    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_display.repository'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $destination_identifier = [];
    // array_intersect_key() won't work because the order is important because
    // this is also the return value.
    foreach (array_keys($this->getIds()) as $id) {
      $destination_identifier[$id] = $row->getDestinationProperty($id);
    }

    $entity_display = $this->getEntity($destination_identifier['entity_type'], $destination_identifier['bundle'], $destination_identifier[static::MODE_NAME]);
    assert($entity_display instanceof EntityDisplayInterface);
    $entity_display_was_new_initially = $entity_display->isNew();
    $component_state_prev = $entity_display->getComponent($destination_identifier['field_name']);

    // Run the parent plugin's import.
    $parent_id_values = parent::import($row, $old_destination_id_values);

    if ($entity_display_was_new_initially) {
      $this->flagDisplayAsNew($entity_display);
    }

    // We will insert a row anyway, even if the new that is exactly the same as
    // the old, because we want to restore the original state if every related
    // migration was rolled back.
    $this->saveRollbackData($entity_display, $destination_identifier, $component_state_prev);

    return $parent_id_values;
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $entity_display = $this->getEntity($destination_identifier['entity_type'], $destination_identifier['bundle'], $destination_identifier[static::MODE_NAME]);

    // If the Entity(View|Form)Display does not exist now, and was therefore
    // generated on the fly, there is nothing to roll back. We have to clean up
    // all the related data we stored before.
    if ($entity_display->isNew()) {
      $this->cleanUpLeftovers($entity_display, $destination_identifier);
      return;
    }

    // Act on the current migration plugin's rollback data.
    // This does the PARTIAL rollback: key-value pairs within the config entity
    // aka for a single field.
    $this->performComponentRollback($entity_display, $destination_identifier);
    $this->performPostRollbackCleanup($entity_display, $destination_identifier);
  }

  /**
   * Marks an entity view/form display as initially new.
   *
   * If the entity display was created by a migration, we want to delete it if
   * all of its related migrations were rolled back. We store this info in a
   * 'new' flag table.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   *
   * @throws \Exception
   */
  protected function flagDisplayAsNew(EntityDisplayInterface $entity_display) {
    // Assert that we don't have the 'new' flag for this ID.
    assert($this->displayHasNewFlag($entity_display) === FALSE);
    $this->connection->insert(static::ROLLBACK_STATE_TABLE)
      ->fields([
        static::ROLLBACK_CONFIG_ID_COL => $entity_display->getConfigDependencyName(),
        static::ROLLBACK_CONFIG_LANGCODE_COL => '',
      ])
      ->execute();
  }

  /**
   * Determines whether the display was marked as new during any migration.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   *
   * @return bool
   *   TRUE if the config was created by a rollbackable config migration, FALSE
   *   if not.
   */
  protected function displayHasNewFlag(EntityDisplayInterface $entity_display) : bool {
    $count_query = $this->connection->select(static::ROLLBACK_STATE_TABLE)
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $entity_display->getConfigDependencyName())
      ->condition(static::ROLLBACK_CONFIG_LANGCODE_COL, '')
      ->countQuery();
    return (int) $count_query->execute()->fetchField() !== 0;
  }

  /**
   * Removes the new flag from an entity view/form display.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   *
   * @throws \Exception
   */
  protected function removeNewDisplayFlag(EntityDisplayInterface $entity_display) {
    $this->connection->delete(static::ROLLBACK_STATE_TABLE)
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $entity_display->getConfigDependencyName())
      ->condition(static::ROLLBACK_CONFIG_LANGCODE_COL, '')
      ->execute();
  }

  /**
   * Saves rollback data.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   * @param string[] $destination_identifier
   *   The identifier of the destination component.
   * @param null|array $data
   *   The data to save.
   *
   * @throws \Exception
   */
  protected function saveRollbackData(EntityDisplayInterface $entity_display, array $destination_identifier, $data) {
    if (!$this->rollbackDataExists($entity_display, $destination_identifier['field_name'])) {
      $this->connection->insert(static::ROLLBACK_DATA_TABLE)
        ->fields([
          static::ROLLBACK_MIGRATION_PLUGIN_ID_COL => $this->migration->getPluginId(),
          static::ROLLBACK_CONFIG_ID_COL => $entity_display->getConfigDependencyName(),
          static::ROLLBACK_DISPLAY_FIELD_NAME_COL => $destination_identifier['field_name'],
          static::ROLLBACK_DATA_COL => serialize($data),
        ])
        ->execute();
    }
  }

  /**
   * Determines whether the display has rollback data for a given field.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   * @param string $field_name
   *   The name of the field.
   *
   * @return bool
   *   TRUE if some rollback data exists, FALSE if not.
   */
  protected function rollbackDataExists(EntityDisplayInterface $entity_display, string $field_name) : bool {
    $count_query = $this->connection->select(static::ROLLBACK_DATA_TABLE)
      ->condition(static::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $entity_display->getConfigDependencyName())
      ->condition(static::ROLLBACK_DISPLAY_FIELD_NAME_COL, $field_name)
      ->countQuery();

    return (int) $count_query->execute()->fetchField() !== 0;
  }

  /**
   * Returns the rollback data for a given display and field component.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   * @param string[] $destination_identifier
   *   The destination ID.
   *
   * @return array[]|null
   *   The rollback data stored by the current migration plugin.
   */
  protected function getRollbackData(EntityDisplayInterface $entity_display, array $destination_identifier) {
    $statement = $this->connection->select(static::ROLLBACK_DATA_TABLE, 'drd')
      ->fields('drd', [
        static::ROLLBACK_MIGRATION_PLUGIN_ID_COL,
        static::ROLLBACK_DATA_COL,
      ])
      ->condition('drd.' . static::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
      ->condition('drd.' . static::ROLLBACK_CONFIG_ID_COL, $entity_display->getConfigDependencyName())
      ->condition('drd.' . static::ROLLBACK_CONFIG_LANGCODE_COL, '')
      ->condition('drd.' . static::ROLLBACK_DISPLAY_FIELD_NAME_COL, $destination_identifier['field_name'])
      ->execute();

    $display_rollback_data = $statement->fetchAllAssoc(static::ROLLBACK_MIGRATION_PLUGIN_ID_COL);
    array_walk($display_rollback_data, function (&$row) {
      $row = unserialize($row->{static::ROLLBACK_DATA_COL});
    });

    return $display_rollback_data[$this->migration->getPluginId()];
  }

  /**
   * Performs post-rollback cleanup.
   *
   * Deletes the given entity display if it was created by a rollbackable
   * migration and removes the related 'new' flag.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   * @param string[] $destination_identifier
   *   The destination ID.
   */
  protected function performPostRollbackCleanup(EntityDisplayInterface $entity_display, array $destination_identifier) {
    if (!$this->rollbackDataExists($entity_display, $destination_identifier['field_name']) && $this->displayHasNewFlag($entity_display)) {
      $entity_display->delete();

      $this->removeNewDisplayFlag($entity_display);
    }
  }

  /**
   * Removes all rollback data as well as the 'new' flag.
   *
   * Useful if the display entity was deleted before rolling back any of the
   * related migrations.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   * @param string[] $destination_identifier
   *   The destination ID.
   */
  protected function cleanUpLeftovers(EntityDisplayInterface $entity_display, array $destination_identifier) {
    if ($this->rollbackDataExists($entity_display, $destination_identifier['field_name'])) {
      $this->connection->delete(static::ROLLBACK_DATA_TABLE)
        ->condition(static::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
        ->condition(static::ROLLBACK_CONFIG_ID_COL, $entity_display->getConfigDependencyName())
        ->execute();
    }

    $this->removeNewDisplayFlag($entity_display);
  }

  /**
   * Performs rollback of a single entity display component.
   *
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $entity_display
   *   The entity display.
   * @param string[] $destination_identifier
   *   The destination ID.
   */
  protected function performComponentRollback(EntityDisplayInterface $entity_display, array $destination_identifier) {
    if ($this->rollbackDataExists($entity_display, $destination_identifier['field_name'])) {
      $rollback_data = $this->getRollbackData($entity_display, $destination_identifier);

      // If there are previous values (aka "rollback data"), restore those. If
      // there aren't, the component can be removed.
      if ($rollback_data) {
        $entity_display->setComponent($destination_identifier['field_name'], $rollback_data);
      }
      else {
        $entity_display->removeComponent($destination_identifier['field_name']);
      }

      $this->connection->delete(static::ROLLBACK_DATA_TABLE)
        ->condition(static::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
        ->condition(static::ROLLBACK_CONFIG_ID_COL, $entity_display->getConfigDependencyName())
        ->condition(static::ROLLBACK_DISPLAY_FIELD_NAME_COL, $destination_identifier['field_name'])
        ->execute();

      $entity_display->save();
    }
  }

}
