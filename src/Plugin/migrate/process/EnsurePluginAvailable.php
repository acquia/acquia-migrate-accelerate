<?php

namespace Drupal\acquia_migrate\Plugin\migrate\process;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Ensures that the source is an available plugin ID in the given manager.
 *
 * @code
 * process:
 *   type:
 *     plugin: ensure_plugin_available
 *     plugin_manager_id: plugin.manager.field.field_type
 *     source: type
 *     // If "source_override" is omitted, then the default (source) value will
 *     // be checked. When source_override is defined, then the value the
 *     // defined property will be processed when the incoming (source) value is
 *     // empty.
 *     source_override: type
 *     message_template: 'The '<field_type>' field type is missing, cannot create the '<field_name>' field for '<entity_type_id>' entities'
 *     message_args:
 *       field_name: field_name
 *       entity_type_id: '@entity_type'
 *       field_type: type
 * @endcode
 *
 * @see \Drupal\migrate\Plugin\MigrateProcessInterface
 *
 * @MigrateProcessPlugin(
 *   id = "ensure_plugin_available"
 * )
 */
class EnsurePluginAvailable extends ProcessPluginBase {

  /**
   * Constructs an EnsurePluginAvailable migration process plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $configuration += [
      'message_template' => NULL,
      'message_args' => [],
      'source_override' => '',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // The mandatory "plugin_manager_id" configuration is missing.
    if (!array_key_exists('plugin_manager_id', $this->configuration)) {
      throw new MigrateException(sprintf("The 'plugin_manager_id' configuration has to be defined for the '%s' migration process plugin.", $this->getPluginId()));
    }

    list(
      'plugin_manager_id' => $plugin_manager_id,
      'message_template' => $message_template,
      'message_args' => $args,
      'source_override' => $source_override
    ) = $this->configuration;

    // Cannot find a service based on the provided service ID.
    if (!\Drupal::hasService($plugin_manager_id)) {
      throw new MigrateException(sprintf("The 'plugin_manager_id' configuration refers to a missing plugin manager. The current value is '%s'.", $plugin_manager_id));
    }

    // The service we got is not a plugin manager.
    $plugin_manager = \Drupal::service($plugin_manager_id);
    if (!($plugin_manager instanceof PluginManagerInterface)) {
      throw new MigrateException(sprintf("The 'plugin_manager_id' configuration refers to a service that is not a plugin manager. The current value is '%s'.", $plugin_manager_id));
    }

    // Typically, the preceding processes (like "process_field" with
    // "getFieldType" method) do not bypass invalid values and simply return
    // NULL. Anyway, we don't touch the incoming value.
    $plugin_id_raw = empty($value) && !empty($source_override)
      ? $row->get($source_override)
      : $value;
    $plugin_id = !empty($plugin_id_raw) && (is_string($plugin_id_raw) || is_numeric($plugin_id_raw))
      ? (string) $plugin_id_raw
      : $plugin_id_raw;

    // The value to check is invalid.
    if (empty($plugin_id) || !is_string($plugin_id)) {
      throw new MigrateException(sprintf("The incoming value of %s migration process plugin must be a non-empty string. The current value type is %s.", $this->getPluginId(), gettype($plugin_id)));
    }

    // Perform the actual test. Since we might need the original exception's
    // message, we try to get the definition instead of checking its existence
    // with \Drupal\Component\Plugin\PluginManagerInterface::hasDefinition().
    try {
      $plugin_manager->getDefinition($plugin_id);
    }
    catch (PluginNotFoundException $exception) {
      $message = $message_template ? $message_template : $exception->getMessage();
      if ($message_template && $args) {
        $replacements = array_values($row->getMultiple(array_values($args)));
        $key_patterns = array_reduce(array_keys($args), function (array $carry, string $raw) {
          $carry[] = '/<' . $raw . '>/';
          return $carry;
        }, []);
        $message = preg_replace($key_patterns, $replacements, $message_template);
      }

      throw new MigrateSkipRowException($message);
    }

    return $value;
  }

}
