<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\color\Plugin\migrate\destination\Color;
use Drupal\migrate\Row;

/**
 * Persist rollbackable color settings to the config system.
 *
 * @see \Drupal\color\Plugin\migrate\destination\Color
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_color"
 * )
 */
final class RollbackableColor extends Color implements RollbackableInterface {

  use RollbackableSimpleConfigTrait;

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
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $config_name = $row->getDestinationProperty('configuration_name');
    $config_key = $row->getDestinationProperty('element_name');
    $config = $this->configFactory->getEditable($config_name);
    // We need to know whether the config was new BEFORE we called the original
    // import method of the parent class. If something goes wrong during the
    // actual import (e.g. we run into an exception), we shouldn't flag the
    // config as new.
    $config_was_new = $config->isNew();
    $previous_values = $this->getRollbackData($config) + [
      $config_key => $config->getOriginal($config_key, FALSE),
    ];
    $destination_identifier = [];

    // Parent import returns TRUE when an actual save happened. But since an
    // idmap entry is saved only if the returned value is not 'TRUE', we need a
    // meaningful value.
    if (parent::import($row, $old_destination_id_values)) {
      $destination_identifier[] = $config_name;
    }

    if ($config_was_new) {
      $this->flagConfigAsNew($config);
    }

    // Save rollback data if needed.
    if (!$this->configHasNewFlag($config)) {
      $this->saveRollbackData($config, $previous_values);
    }

    return $destination_identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function rollback($destination_identifier) {
    $config = $this->configFactory->getEditable($destination_identifier['name']);

    // If the config does not exist now, but was created by this migration,
    // there is nothing to roll back. We have to clean up all the related data
    // we stored before.
    if ($config->isNew()) {
      $this->cleanUpLeftovers($config);
      return;
    }

    // Act on the current migration plugin's rollback data.
    // This does the PARTIAL rollback: key-value pairs within the config.
    // Partial means that we might have keys that weren't touched during the
    // migration – we neither modify those on rollback.
    $this->performConfigRollback($config);
    $this->performPostRollbackCleanup($config);
  }

}
