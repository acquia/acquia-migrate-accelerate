<?php

namespace Drupal\acquia_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\migrate\process\DefaultValue;
use Drupal\migrate\Row;

/**
 * An improved default value plugin.
 *
 * If the "default_value_source" is set, this plugin gets the configured values
 * from the actual row source instead of using the raw config value. This plugin
 * is required by AcquiaMigrateEntityReference and by
 * AcquiaMigrateNodeReference and it is used in the referred plugin's
 * ::defineValueProcessPipeline() method.
 *
 * Backward-compatible with DefaultValue.
 *
 * @MigrateProcessPlugin(
 *   id = "acquia_migrate_default_value",
 *   handle_multiples = TRUE
 * )
 *
 * @see \Drupal\acquia_migrate\Plugin\migrate\AcquiaMigrateEntityReference
 * @see \Drupal\acquia_migrate\Plugin\migrate\AcquiaMigrateNodeReference
 */
class AcquiaMigrateDefaultValue extends DefaultValue {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!array_key_exists('default_value_source', $this->configuration)) {
      return parent::transform($value, $migrate_executable, $row, $destination_property);
    }

    $default_value_source_ids = $this->configuration['default_value_source'];

    if (is_string($default_value_source_ids)) {
      $default_value = $row->get($default_value_source_ids);
    }
    elseif (is_array($default_value_source_ids)) {
      $default_value = [];
      foreach ($default_value_source_ids as $default_value_key => $default_value_source_id) {
        $default_value[$default_value_key] = $row->get($default_value_source_id);
      }
    }

    $default_value = $default_value ?? NULL;

    if (!empty($this->configuration['strict'])) {
      return isset($value) ? $value : $default_value;
    }
    return $value ?: $default_value;
  }

}
