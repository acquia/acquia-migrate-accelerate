<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait for rollbackable config-like destination plugins.
 *
 * @internal
 */
trait RollbackableConfigTrait {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a RollbackableConfigBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration entity.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   A Database connection to use for reading migration messages.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, ConfigFactoryInterface $config_factory, LanguageManagerInterface $language_manager, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $config_factory, $language_manager);
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
      $container->get('config.factory'),
      $container->get('language_manager'),
      $container->get('database')
    );
  }

  /**
   * Marks a config as initially new.
   *
   * If the config was created by a migration, we want to delete it only when
   * all of its related migrations were rolled back. We store this info in a
   * 'new' flag table.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @throws \Exception
   */
  protected function flagConfigAsNew(StorableConfigBase $config, string $langcode = '') {
    if (!$this->configHasNewFlag($config, $langcode)) {
      $this->connection->insert(static::ROLLBACK_STATE_TABLE)
        ->fields([
          static::ROLLBACK_CONFIG_ID_COL => $config->getName(),
          static::ROLLBACK_CONFIG_LANGCODE_COL => $langcode,
        ])
        ->execute();
    }
  }

  /**
   * Determines whether the config was marked as new during any migration.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @return bool
   *   TRUE if the config was created by a rollbackable config migration, FALSE
   *   if not.
   */
  protected function configHasNewFlag(StorableConfigBase $config, string $langcode = '') : bool {
    $count_query = $this->connection->select(static::ROLLBACK_STATE_TABLE)
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition(static::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->countQuery();
    return (int) $count_query->execute()->fetchField() !== 0;
  }

  /**
   * Removes the new flag from a config.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @throws \Exception
   */
  protected function removeNewConfigFlag(StorableConfigBase $config, string $langcode = '') {
    $this->connection->delete(static::ROLLBACK_STATE_TABLE)
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition(static::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->execute();
  }

  /**
   * Saves rollback data.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param null|array $data
   *   The data to save.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @throws \Exception
   */
  protected function saveRollbackData(StorableConfigBase $config, $data, string $langcode = '') {
    if (!$this->rollbackDataExists($config, $langcode)) {
      $this->connection->insert(RollbackableInterface::ROLLBACK_DATA_TABLE)
        ->fields([
          RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL => $this->migration->getPluginId(),
          RollbackableInterface::ROLLBACK_CONFIG_ID_COL => $config->getName(),
          RollbackableInterface::ROLLBACK_CONFIG_LANGCODE_COL => $langcode,
          RollbackableInterface::ROLLBACK_DATA_COL => serialize($data),
        ])
        ->execute();
    }
    else {
      $this->connection->update(RollbackableInterface::ROLLBACK_DATA_TABLE)
        ->fields([
          RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL => $this->migration->getPluginId(),
          RollbackableInterface::ROLLBACK_CONFIG_ID_COL => $config->getName(),
          RollbackableInterface::ROLLBACK_CONFIG_LANGCODE_COL => $langcode,
          RollbackableInterface::ROLLBACK_DATA_COL => serialize($data),
        ])
        ->condition(RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
        ->condition(RollbackableInterface::ROLLBACK_CONFIG_ID_COL, $config->getName())
        ->condition(RollbackableInterface::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
        ->execute();
    }
  }

  /**
   * Determines whether the display has rollback data for a given field.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @return bool
   *   TRUE if some rollback data exists, FALSE if not.
   */
  protected function rollbackDataExists(StorableConfigBase $config, string $langcode = '') : bool {
    $count_query = $this->connection->select(RollbackableInterface::ROLLBACK_DATA_TABLE)
      ->condition(RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
      ->condition(RollbackableInterface::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition(RollbackableInterface::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->countQuery();

    return (int) $count_query->execute()->fetchField() !== 0;
  }

  /**
   * Returns the rollback data for a given config.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @return array
   *   The rollback data stored by the current migration plugin (this may be an
   *   empty array).
   */
  protected function getRollbackData(StorableConfigBase $config, string $langcode = '') : array {
    $statement = $this->connection->select(RollbackableInterface::ROLLBACK_DATA_TABLE, 'crd')
      ->fields('crd', [
        RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL,
        RollbackableInterface::ROLLBACK_DATA_COL,
      ])
      ->condition('crd.' . RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
      ->condition('crd.' . RollbackableInterface::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition('crd.' . RollbackableInterface::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->condition('crd.' . RollbackableInterface::ROLLBACK_DISPLAY_FIELD_NAME_COL, '')
      ->execute();

    $config_rollback_data = $statement->fetchAllAssoc(RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL);
    array_walk($config_rollback_data, function (&$row) {
      $row = unserialize($row->{RollbackableInterface::ROLLBACK_DATA_COL});
    });

    return $config_rollback_data[$this->migration->getPluginId()] ?? [];
  }

  /**
   * Deletes the rollback data of the given config, for the current migration.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function deleteRollbackData(StorableConfigBase $config, string $langcode = '') {
    $this->connection->delete(RollbackableInterface::ROLLBACK_DATA_TABLE)
      ->condition(RollbackableInterface::ROLLBACK_MIGRATION_PLUGIN_ID_COL, $this->migration->getPluginId())
      ->condition(RollbackableInterface::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition(RollbackableInterface::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->execute();
  }

  /**
   * Removes all rollback data as well as the 'new' flag.
   *
   * Useful if the config was deleted before rolling back any of the related
   * migrations.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function cleanUpLeftovers(StorableConfigBase $config, string $langcode = '') {
    if ($this->rollbackDataExists($config, $langcode)) {
      $this->deleteRollbackData($config, $langcode);
    }

    $this->removeNewConfigFlag($config);
  }

  /**
   * Performs the data rollback for a config.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function performConfigRollback(StorableConfigBase $config, string $langcode = '') {
    if ($this->rollbackDataExists($config, $langcode)) {
      $rollback_data = $this->getRollbackData($config, $langcode);

      // If there are previous values (aka "rollback data"), restore those. If
      // there aren't, the component may be removed.
      foreach ($rollback_data as $config_key => $original_value) {
        if ($original_value !== NULL) {
          $config->set($config_key, $original_value);
        }
        else {
          $config->clear($config_key);
        }
      }

      $this->deleteRollbackData($config, $langcode);

      $config->save();
    }
  }

  /**
   * Performs post-rollback cleanup.
   *
   * Deletes the given config if it was created by a rollbackable migration and
   * removes the related 'new' flag.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function performPostRollbackCleanup(StorableConfigBase $config, string $langcode = '') {
    if (!$this->rollbackDataExists($config, $langcode) && $this->configHasNewFlag($config, $langcode)) {
      if (!empty($langcode)) {
        assert($config instanceof LanguageConfigOverride);
      }
      $config->delete();

      $this->removeNewConfigFlag($config, $langcode);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $config_name = $this->config->getName();
    $langcode = '';

    if ($this->isTranslationDestination()) {
      $langcode = $row->getDestinationProperty('langcode');
      $this->config = $this->language_manager->getLanguageConfigOverride($langcode, $config_name);
    }

    // We need to know whether the config was new BEFORE we called the original
    // import method of the parent class. If something goes wrong during the
    // actual import (e.g. we run into an exception), we shouldn't flag the
    // config as new.
    $config_was_new = $this->config->isNew();
    $previous_values = $this->getRollbackData($this->config, $langcode);

    // If the current configuration is a pre-existing config, we only have to
    // restore the original values if the migration is rolled back. However, if
    // the config was created by a migration, then we have to delete it if we
    // cannot find any other rollbackable data that was stored by other
    // migrations.
    if (!$config_was_new && $this->config instanceof Config) {
      foreach ($row->getRawDestination() as $key => $value) {
        if (isset($value) || !empty($this->configuration['store null'])) {
          $config_key_first_level = explode(Row::PROPERTY_SEPARATOR, $key)[0];

          // The "langcode" property is used for determining the right
          // collection – it is not a configuration property, it is one of the
          // IDs of the LanguageConfigOverride configuration.
          // @see \Drupal\migrate\Plugin\migrate\destination\EntityConfigBase::getIds()
          // @see \Drupal\Core\Config\ConfigCollectionInfo
          if ($this->isTranslationDestination() && $config_key_first_level === 'langcode') {
            continue;
          }

          if (!array_key_exists($config_key_first_level, $previous_values)) {
            $previous_values[$config_key_first_level] = $this->config->getOriginal($config_key_first_level, FALSE);
          }
        }
      }
    }

    // Call the original import.
    $destination_identifiers = parent::import($row, $old_destination_id_values);

    if ($config_was_new) {
      $this->flagConfigAsNew($this->config, $langcode);
    }

    // We will insert a row anyway, even if we have no data for rolling back:
    // there can be multiple migrations that manipulate the same config, and
    // this is the only way to know when we can delete the actual config (this
    // is when no other rows left in the rollback data table for the  current
    // config).
    $this->saveRollbackData($this->config, $previous_values, $langcode);

    return $destination_identifiers;
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $config_name = $this->config->getName();
    $langcode = '';

    if ($this->isTranslationDestination()) {
      $langcode = $destination_identifier['langcode'];
      $this->config = $this->language_manager->getLanguageConfigOverride($langcode, $config_name);
    }

    // If the config does not exist now, and was therefore generated on the fly,
    // there is nothing to roll back. We have to clean up all the related data
    // we stored before.
    if ($this->config->isNew()) {
      $this->cleanUpLeftovers($this->config, $langcode);
      return;
    }

    // Act on the current migration plugin's rollback data.
    // This does the PARTIAL rollback: key-value pairs within the config.
    // Partial means that we might have keys that weren't touched during the
    // migration – we neither modify those on rollback.
    $this->performConfigRollback($this->config, $langcode);
    $this->performPostRollbackCleanup($this->config, $langcode);
  }

}
