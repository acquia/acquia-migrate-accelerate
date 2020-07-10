<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\migrate\Plugin\migrate\destination\Config;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides rollbackable configuration destination plugin.
 *
 * @see \Drupal\migrate\Plugin\migrate\destination\Config
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_config"
 * )
 */
final class RollbackableConfig extends Config implements RollbackableInterface {

  use RollbackableConfigTrait;

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
   * Constructs a RollbackableConfig destination object.
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

    // We need to know that the config we're migrating into was new before
    // migration. If this configuration is a pre-existing config, we only have
    // to restore the original values if the migration is rolled back. However,
    // if the config was created by a migration, then we have to delete it if we
    // cannot find any other rollbackable data that was stored by other
    // migrations.
    foreach ($row->getRawDestination() as $key => $value) {
      if (isset($value) || !empty($this->configuration['store null'])) {
        $config_key_first_level = explode(Row::PROPERTY_SEPARATOR, $key)[0];

        if (!array_key_exists($config_key_first_level, $previous_values)) {
          $previous_values[$config_key_first_level] = $this->config->getOriginal($config_key_first_level, FALSE);
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
