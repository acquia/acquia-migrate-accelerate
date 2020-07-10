<?php

namespace Drupal\acquia_migrate;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate\Row;

/**
 * Serves the Acquia Migrate mapping viewer.
 *
 * @internal
 */
final class MigrationMappingViewer {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The migration mapping manipulator.
   *
   * @var \Drupal\acquia_migrate\MigrationMappingManipulator
   */
  protected $migrationMappingManipulator;

  /**
   * MigrationMappingViewer constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\acquia_migrate\MigrationMappingManipulator $migration_mapping_manipulator
   *   The migration mapping manipulator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, MigrationMappingManipulator $migration_mapping_manipulator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->migrationMappingManipulator = $migration_mapping_manipulator;
  }

  /**
   * Gets the entity type and field definitions for a data migration plugin.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   A data migration plugin instance.
   *
   * @return array
   *   An array with the following key-value pairs:
   *   - entity_type: a ContentEntityType instance
   *   - field_definitions: the corresponding FieldDefinitionInterface objects,
   *     keyed by field name.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getEntityTypeAndFieldDefinitions(MigrationPlugin $data_migration_plugin) {
    list(, $entity_type_id) = explode(':', $data_migration_plugin->getDestinationConfiguration()['plugin']);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $destination_bundle = $entity_type_id;
    if ($entity_type->hasKey('bundle')) {
      if ($entity_type_id === 'comment') {
        $destination_bundle = $data_migration_plugin->getSourceConfiguration()['node_type'];
      }
      else {
        $destination_bundle = $data_migration_plugin->getDestinationConfiguration()['default_bundle'];
      }
    }
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $destination_bundle);

    assert($entity_type instanceof ContentEntityType);
    assert(Inspector::assertAllObjects($field_definitions, FieldDefinitionInterface::class));

    return [
      'entity_type' => $entity_type,
      'field_definitions' => $field_definitions,
    ];
  }

  /**
   * Computes mapped fields: fields in destination with data mapped from source.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   A data migration plugin instance.
   * @param array &$mapped_source_columns
   *   An array to be populated with which source columns (fields) have been
   *   mapped.
   *
   * @return array
   *   The mapped fields, keyed by destination field name and with the
   *   following values:
   *   - sourceFieldName: the source field name.
   *   - destinationFieldName: the destination field name
   *   - destinationFieldType: a field type plugin ID
   *   - destinationFieldLabel: a human-readable label
   *   - destinationFieldIsRequired: a boolean
   *   - destinationFieldSampleValue: a sample value for this field type.
   *   - migrationProcessPlugins_THIS_WILL_CHANGE: A PREVIEW OF WHAT PROCESS
   *     PLUGINS MIGHT LOOK LIKE WHEN SERIALIZED ⚠️ THIS WILL CHANGE ⚠️
   *
   * @throws \Drupal\migrate\MigrateException
   */
  public function getMappedFields(MigrationPlugin $data_migration_plugin, array &$mapped_source_columns) : array {
    list('field_definitions' => $field_definitions) = $this->getEntityTypeAndFieldDefinitions($data_migration_plugin);
    assert(Inspector::assertAllObjects($field_definitions, FieldDefinitionInterface::class));

    [$overridden] = $this->migrationMappingManipulator->getProcessPipelineOverrides($data_migration_plugin);

    $mapped_fields = [];
    foreach ($data_migration_plugin->getProcessPlugins() as $destination_field_name => $process_plugin_descriptions) {
      $process_plugin_descriptions = array_reduce($data_migration_plugin->getProcess()[$destination_field_name], function (array $carry, array $configuration) {
        $result = static::describeProcessPluginConfiguration($configuration);
        if ($result === FALSE) {
          return $carry;
        }
        else {
          $carry[] = $result;
        }
        return $carry;
      }, []);

      $source_field_name = MigrationPreviewer::getSourceFieldName($destination_field_name, $data_migration_plugin);

      // If the field definition is already available (because it is code
      // defined or already migrated), use it. Otherwise, replace unknowable
      // information with NULL values.
      if (isset($field_definitions[$destination_field_name])) {
        $field_definition = $field_definitions[$destination_field_name];
        $mapped_fields[$destination_field_name] = [
          'sourceFieldName' => $source_field_name,
          'destinationFieldName' => $destination_field_name,
          'destinationFieldType' => $field_definition->getType(),
          'destinationFieldLabel' => (string) $field_definition->getLabel(),
          'destinationFieldIsRequired' => $field_definition->isRequired(),
          'destinationFieldSampleValue' => static::generateSampleValue($field_definition),
          'migrationProcessPlugins_THIS_WILL_CHANGE' => $process_plugin_descriptions,
        ];
      }
      else {
        $mapped_fields[$destination_field_name] = [
          'sourceFieldName' => $source_field_name,
          'destinationFieldName' => $destination_field_name,
          'destinationFieldType' => NULL,
          'destinationFieldLabel' => NULL,
          'destinationFieldIsRequired' => NULL,
          'destinationFieldSampleValue' => NULL,
          'migrationProcessPlugins_THIS_WILL_CHANGE' => $process_plugin_descriptions,
        ];
      }

      // Track which source fields are used, so we can determine unmapped source
      // fields.
      $mapped_source_columns[$source_field_name] = TRUE;

      if (in_array($source_field_name, $overridden, TRUE)) {
        $mapped_fields[$source_field_name]['overridden'] = TRUE;
      }
    }

    return $mapped_fields;
  }

  /**
   * Computes source-only fields: fields in source not mapped to destination.
   *
   * @param array $mapped_source_columns
   *   An array populated by ::getMappedFields() indicating which source columns
   *   (fields) have been mapped.
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   A data migration plugin instance.
   *
   * @return array
   *   The source-only fields, keyed by source field name and with the following
   *   values:
   *   - sourceFieldName: a field name
   */
  public function getSourceOnlyFields(array $mapped_source_columns, MigrationPlugin $data_migration_plugin) : array {
    list('entity_type' => $entity_type) = $this->getEntityTypeAndFieldDefinitions($data_migration_plugin);
    assert($entity_type instanceof ContentEntityType);

    [, $overridden] = $this->migrationMappingManipulator->getProcessPipelineOverrides($data_migration_plugin);

    // Compute source-only fields.
    $source_plugin = $data_migration_plugin->getSourcePlugin();
    $first_source_row = $source_plugin
      ->query()
      ->range(0, 1)
      ->execute()
      ->fetchAll();
    $first_source_row = reset($first_source_row);

    // Prepare the first source row; this causes configurable fields to be added
    // to it.
    // @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::next()
    // @see \Drupal\node\Plugin\migrate\source\d7\Node::prepareRow()
    $row = new Row($first_source_row, $source_plugin->getIds());
    $source_plugin->prepareRow($row);
    $prepared_first_source_row = $row->getSource();

    $source_only_fields = [];
    $unmapped_source_columns = array_diff_key($prepared_first_source_row, $mapped_source_columns, [$entity_type->getKey('bundle') => TRUE], $data_migration_plugin->getSourceConfiguration());
    foreach (array_keys($unmapped_source_columns) as $unmapped_source_column) {
      // Ignore Migrate-internal virtual source columns.
      if (strpos($unmapped_source_column, 'migrate_map_') === 0) {
        continue;
      }
      $source_only_fields[$unmapped_source_column] = [
        'sourceFieldName' => $unmapped_source_column,
      ];

      // This is a source field that used to be migrated, and now the source
      // data is being dropped.
      if (in_array($unmapped_source_column, $overridden, TRUE)) {
        $source_only_fields[$unmapped_source_column]['overridden'] = TRUE;
      }
    }

    return $source_only_fields;
  }

  /**
   * Computes destination-only fields: fields in destination not getting data.
   *
   * @param \Drupal\migrate\Plugin\Migration $data_migration_plugin
   *   A data migration plugin instance.
   *
   * @return array
   *   The destination-only fields, keyed by destination field name and with the
   *   following values:
   *   - destinationFieldName: a field name
   *   - destinationFieldType: a field type plugin ID
   *   - destinationFieldLabel: a human-readable label
   *   - destinationFieldIsRequired: a boolean
   *   - destinationFieldSampleValue: a sample value for this field type.
   */
  public function getDestinationOnlyFields(MigrationPlugin $data_migration_plugin) : array {
    list('entity_type' => $entity_type, 'field_definitions' => $field_definitions) = $this->getEntityTypeAndFieldDefinitions($data_migration_plugin);
    assert(Inspector::assertAllObjects($field_definitions, FieldDefinitionInterface::class));

    try {
      $unmapped_destination_columns = array_diff_key($field_definitions, $data_migration_plugin->getProcessPlugins(), ['uuid' => TRUE, $entity_type->getKey('bundle') => TRUE]);
    }
    catch (MigrateException $e) {
      throw new \InvalidArgumentException($e->getMessage(), 500);
    }

    $destination_only_fields = [];
    foreach (array_keys($unmapped_destination_columns) as $unmapped_destination_column) {
      $field_definition = $field_definitions[$unmapped_destination_column];
      assert($field_definition instanceof FieldDefinitionInterface);
      if ($field_definition->isReadOnly()) {
        continue;
      }
      // @todo remove this when https://www.drupal.org/node/2443991 lands
      elseif ($unmapped_destination_column === 'default_langcode') {
        continue;
      }
      $destination_only_fields[$unmapped_destination_column] = [
        'destinationFieldName' => $unmapped_destination_column,
        'destinationFieldType' => $field_definition->getType(),
        'destinationFieldLabel' => (string) $field_definition->getLabel(),
        'destinationFieldIsRequired' => $field_definition->isRequired(),
        'destinationFieldSampleValue' => static::generateSampleValue($field_definition),
      ];
    }

    return $destination_only_fields;
  }

  /**
   * Generates a description for a configured process plugin.
   *
   * @param array $process_plugin_configuration
   *   A process plugin configuration.
   *
   * @return bool|string
   *   A process plugin configuration description.
   *
   * @todo Refactor completely once we make all of this tweakable in the UI and once we add transformation plugins.
   * @todo Add a new interface that extends \Drupal\migrate\Plugin\MigrateProcessInterface and adds label, form, etc methods.
   * @todo Rewrite this entire thing! 💩
   */
  private static function describeProcessPluginConfiguration(array $process_plugin_configuration) {
    $plugin_id = $process_plugin_configuration['plugin'];
    switch ($plugin_id) {
      case 'get':
        return FALSE;

      case 'user_langcode':
        if (isset($process_plugin_configuration['fallback_to_site_default'])) {
          $nuance = ' (fall back to site default)';
        }
        else {
          $nuance = ' (fall back to English)';
        }
        break;

      case 'migration_lookup':
        $migration_plugin_manager = \Drupal::service('plugin.manager.migration');
        assert($migration_plugin_manager instanceof MigrationPluginManagerInterface);
        try {
          $migration_label = $migration_plugin_manager->getDefinition($process_plugin_configuration['migration'])['label'];
        }
        catch (PluginNotFoundException $e) {
          $nuance = ' (invalid migration plugin)';
          break;
        }
        if (isset($process_plugin_configuration['source'])) {
          $nuance = ' (map ' . $process_plugin_configuration['source'] . ' to migrated ID from the ' . $migration_label . ' migration)';
        }
        else {
          $nuance = ' (map to migrated ID from the ' . $migration_label . ' migration)';
        }
        break;

      case 'default_value':
        $nuance = ' (use ' . $process_plugin_configuration['source'] . ', if empty: ' . (string) print_r($process_plugin_configuration['default_value'], TRUE) . ')';
        break;

      default:
        $nuance = ' (NO NUANCE SUPPORTED YET)';
    }

    return $plugin_id . $nuance;
  }

  /**
   * Generates a sample field value for the given field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   A field definition.
   *
   * @return array|mixed
   *   A field value array, or the value for the main property if there is only
   *   a main property.
   */
  private static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $sample_field_value = call_user_func_array([$field_definition->getItemDefinition()->getClass(), 'generateSampleValue'], [$field_definition]);
    $main_property_name = call_user_func_array([$field_definition->getItemDefinition()->getClass(), 'mainPropertyName'], []);
    if (is_array($sample_field_value) && array_keys($sample_field_value) === [$main_property_name]) {
      $sample_field_value = $sample_field_value[$main_property_name];
    }
    elseif ($field_definition->getType() === 'entity_reference' && is_array($sample_field_value) && isset($sample_field_value['entity'])) {
      $sample_field_value = ['target_id' => ''];
    }

    return $sample_field_value;
  }

}
