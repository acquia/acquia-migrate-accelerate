<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\Core\Config\Config;

/**
 * Trait for rollbackable config-like destination plugins.
 *
 * @internal
 */
trait RollbackableConfigTrait {

  /**
   * Marks a config as initially new.
   *
   * If the config was created by a migration, we want to delete it only when
   * all of its related migrations were rolled back. We store this info in a
   * 'new' flag table.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @throws \Exception
   */
  protected function flagConfigAsNew(Config $config, string $langcode = '') {
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
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @return bool
   *   TRUE if the config was created by a rollbackable config migration, FALSE
   *   if not.
   */
  protected function configHasNewFlag(Config $config, string $langcode = '') : bool {
    $count_query = $this->connection->select(static::ROLLBACK_STATE_TABLE)
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition(static::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->countQuery();
    return (int) $count_query->execute()->fetchField() !== 0;
  }

  /**
   * Removes the new flag from a config.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @throws \Exception
   */
  protected function removeNewConfigFlag(Config $config, string $langcode = '') {
    $this->connection->delete(static::ROLLBACK_STATE_TABLE)
      ->condition(static::ROLLBACK_CONFIG_ID_COL, $config->getName())
      ->condition(static::ROLLBACK_CONFIG_LANGCODE_COL, $langcode)
      ->execute();
  }

  /**
   * Saves rollback data.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param null|array $data
   *   The data to save.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @throws \Exception
   */
  protected function saveRollbackData(Config $config, $data, string $langcode = '') {
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
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @return bool
   *   TRUE if some rollback data exists, FALSE if not.
   */
  protected function rollbackDataExists(Config $config, string $langcode = '') : bool {
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
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   *
   * @return array
   *   The rollback data stored by the current migration plugin (this may be an
   *   empty array).
   */
  protected function getRollbackData(Config $config, string $langcode = '') : array {
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
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function deleteRollbackData(Config $config, string $langcode = '') {
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
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function cleanUpLeftovers(Config $config, string $langcode = '') {
    if ($this->rollbackDataExists($config, $langcode)) {
      $this->deleteRollbackData($config, $langcode);
    }

    $this->removeNewConfigFlag($config);
  }

  /**
   * Performs the data rollback for a config.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function performConfigRollback(Config $config, string $langcode = '') {
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
   * @param \Drupal\Core\Config\Config $config
   *   The configuration.
   * @param string $langcode
   *   The language code. Optional, defaults to ''.
   */
  protected function performPostRollbackCleanup(Config $config, string $langcode = '') {
    if (!$this->rollbackDataExists($config, $langcode) && $this->configHasNewFlag($config, $langcode)) {
      $config->delete();

      $this->removeNewConfigFlag($config, $langcode);
    }
  }

}
