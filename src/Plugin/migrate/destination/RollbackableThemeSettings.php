<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\migrate\Row;
use Drupal\system\Plugin\migrate\destination\d7\ThemeSettings;

/**
 * Persist rollbackable theme settings to the config system.
 *
 * @see \Drupal\system\Plugin\migrate\destination\d7\ThemeSettings
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_d7_theme_settings"
 * )
 */
final class RollbackableThemeSettings extends ThemeSettings implements RollbackableInterface {

  use RollbackableSimpleConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected $supportsRollback = TRUE;

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $config_name = "{$this->configuration['theme']}.settings";
    $theme_config = $this->configFactory->getEditable($config_name);
    // We need to know whether this config was new BEFORE we called the original
    // import method of the parent class. If something goes wrong during the
    // actual import (e.g. we run into an exception), we shouldn't flag the
    // config as new.
    $config_was_new = $theme_config->isNew();
    $previous_values = $this->getRollbackData($theme_config);

    if (!$config_was_new) {
      // Collecting the changed theme settings values.
      foreach (array_keys($row->getDestination()) as $key) {
        $config_key = self::getThemeSettingsKey($key);

        if (empty($config_key)) {
          continue;
        }

        // We only save the value of the top-level keys, so instead of storing
        // "logo.use_default" and "logo.path" independently, we only save the
        // "logo" key's value.
        $config_key_top_level = explode('.', $config_key)[0];

        if (!array_key_exists($config_key_top_level, $previous_values)) {
          $previous_values[$config_key_top_level] = $theme_config->getOriginal($config_key_top_level, FALSE);
        }
      }
    }

    // Call the original import.
    parent::import($row, $old_destination_id_values);

    if ($config_was_new) {
      $this->flagConfigAsNew($theme_config);
    }

    // Save rollback data if needed.
    if (!$this->configHasNewFlag($theme_config)) {
      $this->saveRollbackData($theme_config, $previous_values);
    }

    return [$config_name];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $theme_config = $this->configFactory->getEditable($destination_identifier['name']);

    // If the config does not exist now, but was created by this migration,
    // there is nothing to roll back. We have to clean up all the related data
    // we stored before.
    if ($theme_config->isNew()) {
      $this->cleanUpLeftovers($theme_config);
      return;
    }

    // Act on the current migration plugin's rollback data.
    // This does the PARTIAL rollback: key-value pairs within the config.
    // Partial means that we might have keys that weren't touched during the
    // migration – we neither modify those on rollback.
    $this->performConfigRollback($theme_config);
    $this->performPostRollbackCleanup($theme_config);
  }

  /**
   * Return theme settings key from migration destination key.
   *
   * @param string $destination_key
   *   The destination key from migration.
   *
   * @return string
   *   The referring theme settings key in the theme configuration.
   *
   * @see theme_settings_convert_to_config()
   */
  protected static function getThemeSettingsKey(string $destination_key) : string {
    switch ($destination_key) {
      case 'default_logo':
        return 'logo.use_default';

      case 'logo_path':
        return 'logo.path';

      case 'default_favicon':
        return 'favicon.use_default';

      case 'favicon_path':
        return 'favicon.path';

      case 'favicon_mimetype':
        return 'favicon.mimetype';

      default:
        if (substr($destination_key, 0, 7) == 'toggle_') {
          return 'features.' . mb_substr($destination_key, 7);
        }
        else {
          return !in_array($destination_key, ['theme', 'logo_upload'], TRUE) ?
            $destination_key : '';
        }
    }
  }

}
