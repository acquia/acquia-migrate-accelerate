<?php

namespace Drupal\acquia_migrate;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\destination\Entity;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Plugin\MigratePluginManagerInterface;
use Drupal\migrate\Plugin\MigrateSourceInterface;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationDeriverTrait;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Applies Acquia Migrate's migration changes.
 *
 * This class aims to provide common migration alterations that are used in
 * hook_migration_plugins_alter() hooks.
 *
 * @internal
 */
final class MigrationAlterer {

  use StringTranslationTrait;
  use MigrationDeriverTrait;

  /**
   * Destination plugin map.
   *
   * Structure:
   * @code
   * [
   *   'original_destination_plugin' => 'new_rollbackable_destination_plugin'
   * ]
   * @endcode
   *
   * @var string[]
   */
  const DESTINATION_PLUGIN_MAP = [
    'color' => 'rollbackable_color',
    'config' => 'rollbackable_config',
    'd7_theme_settings' => 'rollbackable_d7_theme_settings',
    'shortcut_set_users' => 'rollbackable_shortcut_set_users',
  ];

  /**
   * Migration source plugin ID map for taxonomy term migration dependencies.
   *
   * Map of the source entity type IDs and the source bundle keys per migration
   * source plugin IDs. This constant specifies which content entity migrations
   * should get the additional term migration dependencies based on the
   * configuration of their existing field configuration fields.
   *
   * Please, do not add mapping for taxonomy term migrations! It might lead to
   * circular or self dependencies in taxonomy term migrations.
   *
   * Structure:
   * @code
   * [
   *   <migration src plugin ID> => [
   *    'entity_type_id' => <entity type ID in the source DB>
   *    'src_bundle_key' => <entity bundle ID in the source DB> (optional)
   *   ],
   * ]
   * @endcode
   *
   * @var string[][]
   */
  const TAXONOMY_TERM_DEPENDENCY_MIGRATE_SOURCE_ENTITY_TYPE_MAP = [
    'd7_node_complete' => [
      'entity_type_id' => 'node',
      'src_bundle_key' => 'node_type',
    ],
    'd7_user' => [
      'entity_type_id' => 'user',
    ],
  ];

  /**
   * Map for plugin availability checking.
   *
   * @var array[][]
   */
  const PLUGIN_AVAILABILITY_CHECK_MAP = [
    'd7_field' => [
      'type' => [
        'plugin_manager_id' => 'plugin.manager.field.field_type',
        'source_override' => 'type',
        'message_template' => "Can't migrate the '<field_name>' field storage for '<entity_type_id>' entities, because the field's type '<field_type>' is not available on the destination site.",
        'message_args' => [
          'field_name' => 'field_name',
          'entity_type_id' => '@entity_type',
          'field_type' => 'type',
        ],
      ],
    ],
    'd7_field_instance' => [
      'type' => [
        'plugin_manager_id' => 'plugin.manager.field.field_type',
        'source_override' => 'type',
        'message_template' => "Can't migrate the '<field_label>' (<field_name>) field instance for '<entity_type_id>' entities of type '<entity_bundle>', because the field's type '<field_type>' is not available on the destination site.",
        'message_args' => [
          'field_label' => 'label',
          'field_name' => 'field_name',
          'entity_type_id' => '@entity_type',
          'entity_bundle' => '@bundle',
          'field_type' => 'type',
        ],
      ],
    ],
  ];

  const FIELD_MIGRATION_PLUGIN_IDS = [
    'd7_view_modes',
    'd7_field',
    'd7_field_instance',
    'd7_field_instance_widget_settings',
    'd7_field_formatter_settings',
    'd7_field_instance_per_form_display',
    'd7_field_instance_per_view_mode',
  ];

  const ENTITY_TYPE_KNOWN_REMAP = [
    // @see \Drupal\paragraphs\MigrationPluginsAlterer::PARAGRAPHS_ENTITY_TYPE_ID_MAPs
    'field_collection_item' => 'paragraph',
    'paragraphs_item' => 'paragraph',
  ];

  /**
   * Migration tag.
   *
   * @var string
   *
   * @todo Get this from config when we expand Acquia Migrate to also
   * migrate sources other than Drupal 7.
   */
  protected $migrationTag = 'Drupal 7';

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
   * The migration source plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $sourcePluginManager;

  /**
   * The migration destination plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $destinationPluginManager;

  /**
   * The logger to use.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a MigrationAlterer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\migrate\Plugin\MigratePluginManagerInterface $source_plugin_manager
   *   The migrate source plugin manager.
   * @param \Drupal\migrate\Plugin\MigratePluginManagerInterface $destination_plugin_manager
   *   The migrate destination plugin manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger to use.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, MigratePluginManagerInterface $source_plugin_manager, MigratePluginManagerInterface $destination_plugin_manager, LoggerChannelInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->sourcePluginManager = $source_plugin_manager;
    $this->destinationPluginManager = $destination_plugin_manager;
    $this->logger = $logger;
  }

  /**
   * Makes the label of bundle content entity migrations user-friendly.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   *
   * @see hook_migration_plugins_alter()
   */
  public function refineMigrationsLabels(array &$migrations) {
    foreach ($migrations as &$migration_data) {
      $migration_tags = $migration_data['migration_tags'] ?? [];
      if (!in_array($this->migrationTag, $migration_tags, TRUE)) {
        continue;
      }

      $this->refineMigrationLabel($migration_data);
    }
  }

  /**
   * Makes the label of a bundle content entity migration user-friendly.
   *
   * @param array $migration
   *   A migration array, as obtained with decoding the migration YAML file and
   *   enriched with some meta information added during discovery phase.
   */
  public function refineMigrationLabel(array &$migration) {
    if (!isset($migration['destination']['plugin'])  || strpos($migration['destination']['plugin'], ':') === FALSE) {
      // Invalid migration without a destination plugin, or
      // no ':' delimiter in the destination plugin – nothing to do.
      return;
    }

    $entity_destination_base_plugin_ids = [
      'entity',
      'entity_complete',
    ];

    [
      $base_plugin_id,
      $target_entity_type_id,
    ] = explode(':', $migration['destination']['plugin']);
    if (empty($target_entity_type_id) || !in_array($base_plugin_id, $entity_destination_base_plugin_ids, TRUE)) {
      return;
    }

    // Construct a custom label for webform and webform submission migrations.
    // @todo Consider creating derivatives of this migration based on data in https://backlog.acquia.com/browse/OCTO-3676
    if ($target_entity_type_id === 'webform_submission') {
      $migration['label'] = $this->t('Webform submissions (including webforms)');
      return;
    }

    if (!isset($migration['deriver'])) {
      // Not a derived migration.
      return;
    }

    $entity_type = $this->entityTypeManager->getDefinition($target_entity_type_id);

    if (!$entity_type instanceof ContentEntityTypeInterface || !$entity_type->getBundleEntityType()) {
      return;
    }

    // This is a migration of a bundleable content entity type.
    if (!is_string($migration['label']) && !$migration['label'] instanceof TranslatableMarkup) {
      return;
    }

    $label_is_translatable_markup = $migration['label'] instanceof TranslatableMarkup;
    $current_label_str = $label_is_translatable_markup ?
      $migration['label']->getUntranslatedString() :
      $migration['label'];
    $label_args = $label_is_translatable_markup ?
      $migration['label']->getArguments() :
      [];
    $label_options = $label_is_translatable_markup ?
      $migration['label']->getOptions() :
      [];
    $new_label_str = preg_replace('/^.+\((.*)\)$/', '${1}', $current_label_str);

    foreach (array_keys($label_args) as $arg_key) {
      if (mb_strpos($new_label_str, $arg_key) === FALSE) {
        unset($label_args[$arg_key]);
      }
    }

    if (!empty($new_label_str) && $new_label_str !== $current_label_str) {
      $label_options['context'] = 'general entity migration category';
      $new_label_options = [
        'context' => 'derived entity migration label',
      ];
      $new_label_str = trim($new_label_str);
      $label_arg_keys = array_keys($label_args);
      // If we have only one argument, and the new label string contains only
      // and exactly this argument, we will re-use this argument directly.
      if (count($label_args) === 1 && reset($label_arg_keys) === $new_label_str) {
        $general_category = reset($label_args);
      }
      // In every other case, we will create a TranslatableMarkup for the
      // '@category' argument.
      else {
        // We are not able to to pass string literal to t() in this case.
        // @codingStandardsIgnoreStart
        $general_category = $this->t($new_label_str, $label_args, $label_options);
        // @codingStandardsIgnoreEnd
      }

      switch ($target_entity_type_id) {
        case 'node':
          // No entity type suffix for nodes.
          $migration['label'] = $this->t('@category', [
            '@category' => $general_category,
          ], $new_label_options);
          break;

        case 'media':
          $source_scheme = $migration['source']['scheme'] ?? NULL;
          $args = [
            '@category' => $general_category,
            '@entity-type-plural' => $entity_type->getPluralLabel(),
          ];
          switch ($source_scheme) {
            case 'public':
            case NULL:
              $migration['label'] = $this->t('@category @entity-type-plural', $args, $new_label_options);
              break;

            default:
              $migration['label'] = $this->t('@category @entity-type-plural (@scheme)', $args + ['@scheme' => $source_scheme], $new_label_options);
          }
          break;

        default:
          $migration['label'] = $this->t('@category @entity-type-plural', [
            '@category' => $general_category,
            '@entity-type-plural' => $entity_type->getPluralLabel(),
          ], $new_label_options);
      }
    }
  }

  /**
   * Replaces config-like destination plugins with rollbackable plugins.
   *
   * Also includes user shortcut set.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function convertToRollbackableConfig(array &$migrations) {
    foreach ($migrations as &$migration_data) {
      $migration_tags = $migration_data['migration_tags'] ?? [];
      if (!in_array($this->migrationTag, $migration_tags, TRUE) || !isset($migration_data['destination']['plugin']) || !in_array($migration_data['destination']['plugin'], array_keys(static::DESTINATION_PLUGIN_MAP), TRUE)) {
        // Invalid migration without destination plugin, non-config destination,
        // or not Drupal 7 migration – nothing to do.
        continue;
      }

      $migration_data['destination']['plugin'] = static::DESTINATION_PLUGIN_MAP[$migration_data['destination']['plugin']];
    }
  }

  /**
   * Make entity_display migrations rollbackable.
   *
   * Replaces entity_display destination plugins with a rollbackable one, and
   * prevents field storages from being deleted when field configs are rolled
   * back (by setting persist_with_no_fields to true).
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function convertToRollbackableEntityDisplay(array &$migrations) {
    $map = [
      'component_entity_display' => 'rollbackable_component_entity_display',
      'component_entity_form_display' => 'rollbackable_component_entity_form_display',
    ];
    foreach ($migrations as &$migration_data) {
      $migration_tags = $migration_data['migration_tags'] ?? [];
      if (!in_array($this->migrationTag, $migration_tags, TRUE) || !isset($migration_data['destination']['plugin'])) {
        // Invalid migration without destination plugin, non-entity display
        // destination, or not Drupal 7 migration – nothing to do.
        continue;
      }

      if (in_array($migration_data['destination']['plugin'], array_keys($map), TRUE)) {
        $migration_data['destination']['plugin'] = $map[$migration_data['destination']['plugin']];
      }

      // We have to make sure that field_storage_config configurations aren't
      // deleted when no related field_config (field instance) configuration
      // left on the destination site. Otherwise if only a single bundle for an
      // entity type with a "shared structure" migration was imported and then
      // rolled back, it'd also roll back part of the "shared structure"
      // migration and hence cause future migrations to fail.
      // @see \Drupal\field\Entity\FieldStorageConfig::$persist_with_no_fields
      if ($migration_data['destination']['plugin'] === 'entity:field_storage_config') {
        $process_plugins = $migration_data['process'] ?? [];
        assert(!array_key_exists('persist_with_no_fields', $process_plugins));
        $migration_data['source']['constants']['persist_with_no_fields'] = TRUE;
        $migration_data['process']['persist_with_no_fields'] = 'constants/persist_with_no_fields';
      }
    }
  }

  /**
   * Update media entity migration definition of media_migration module.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function refineMediaEntityMigrations(array &$migrations) {
    foreach ($migrations as $migration_id => $migration_data) {
      $migration_tags = $migration_data['migration_tags'] ?? [];
      if (!in_array($this->migrationTag, $migration_tags, TRUE)) {
        continue;
      }

      $destination = isset($migration_data['destination']['plugin']) ? $migration_data['destination']['plugin'] : NULL;
      $source_type = $migration_data['source']['type'] ?? $migration_data['source']['source_field_type'] ?? NULL;
      $media_source_plugin_ids = ['d7_file_entity_item', 'd7_file_plain'];
      $source_plugin_is_media_source = in_array($migration_data['source']['plugin'], $media_source_plugin_ids, TRUE);

      if (!$source_plugin_is_media_source || $destination !== 'entity:media' || !$source_type) {
        continue;
      }

      // Media entities cannot be saved without an existing owner. Although this
      // is the fault of the source data, it might block the migration  to media
      // entities, which would lead to data loss since the original file
      // references cannot been transformed to media references without the
      // corresponding media entity. So for every media entity migration we add
      // uid "0" as default owner when the original user ID does not exist.
      $uid_process = self::convertProcessToArrayOfProcesses($migration_data['process']['uid']);
      $uid_process[] = [
        'plugin' => 'entity_exists',
        'entity_type' => 'user',
      ];
      $uid_process[] = [
        'plugin' => 'default_value',
        'default_value' => 0,
      ];
      $migration_data['process']['uid'] = $uid_process;

      if ($migration_data['source']['plugin'] === 'd7_file_entity_item') {
        $migration_data['migration_dependencies'] += [
          'required' => [],
        ];
        unset($migration_data['migration_dependencies']['optional']);

        // With the core patches that Acquia Migrate Assistant requires, the
        // following migrations are derived by entity type, and some of them
        // also by source bundle. We have to remove them and add the ID of the
        // corresponding migration derivative instead.
        $migration_dependencies_to_remove = [
          'd7_view_modes',
          'd7_field',
          'd7_field_instance',
          'd7_field_instance_widget_settings',
          'd7_field_formatter_settings',
        ];
        foreach ($migration_dependencies_to_remove as $migration_dependency_to_remove) {
          $dependency_key = array_search($migration_dependency_to_remove, $migration_data['migration_dependencies']['required']);
          if ($dependency_key !== FALSE) {
            unset($migration_data['migration_dependencies']['required'][$dependency_key]);
          }
        }

        $migration_data['migration_dependencies']['required'] = array_unique(array_merge(array_values($migration_data['migration_dependencies']['required']), [
          'd7_user',
          'd7_media_view_modes',
          'd7_view_modes:file',
          'd7_field:file',
          "d7_field_instance_widget_settings:file:$source_type",
          "d7_field_formatter_settings:file:$source_type",
          "d7_field_instance:file:$source_type",
        ]));
      }

      $migrations[$migration_id] = $migration_data;
    }
  }

  /**
   * Adds taxonomy migration dependencies based on term reference fields.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function addDiscoveredTaxonomyDependencies(array &$migrations) {
    $instances_per_entity = self::getD7TaxonomyTermReferenceFieldInfo();
    // If there are no taxonomy_term_reference fields, or when the Taxonomy
    // module isn't installed, we don't have to do anything.
    if (empty($instances_per_entity)) {
      return;
    }

    foreach ($migrations as $migration_id => $migration_data) {
      // Skip unsupported migrations based on migration tags. Now this means
      // migrations that aren't tagged with "Drupal 7".
      $migration_tags = $migration_data['migration_tags'] ?? [];
      if (!in_array($this->migrationTag, $migration_tags, TRUE)) {
        continue;
      }

      // Only check entity destinations.
      $dest_plugin_id_parts = explode(PluginBase::DERIVATIVE_SEPARATOR, $migration_data['destination']['plugin']);
      $entity_destinations = ['entity', 'entity_complete'];
      if (count($dest_plugin_id_parts) !== 2 || !in_array($dest_plugin_id_parts[0], $entity_destinations, TRUE)) {
        continue;
      }

      // Filter on existing entity types.
      $dest_entity_definition = $this->entityTypeManager->getDefinition($dest_plugin_id_parts[1], FALSE);
      if (!$dest_entity_definition || !($dest_entity_definition instanceof ContentEntityTypeInterface)) {
        continue;
      }

      // Exclude unsupported supported migrations. Now, this means that we only
      // enhance "d7_node_complete" and "d7_user" migrations.
      if (!array_key_exists($migration_data['source']['plugin'], self::TAXONOMY_TERM_DEPENDENCY_MIGRATE_SOURCE_ENTITY_TYPE_MAP)) {
        continue;
      }

      $src_entity_type_id = self::TAXONOMY_TERM_DEPENDENCY_MIGRATE_SOURCE_ENTITY_TYPE_MAP[$migration_data['source']['plugin']]['entity_type_id'];
      $src_bundle_key = self::TAXONOMY_TERM_DEPENDENCY_MIGRATE_SOURCE_ENTITY_TYPE_MAP[$migration_data['source']['plugin']]['src_bundle_key'] ?? NULL;
      $src_bundle = $src_bundle_key && !empty($migration_data['source'][$src_bundle_key])
       ? $migration_data['source'][$src_bundle_key]
       : NULL;

      // Skip migrations which don't have taxonomy term reference fields.
      if (empty($instances_per_entity[$src_entity_type_id])) {
        continue;
      }
      // If we have source bundle, but the specified bundle does not have
      // term reference field, we can skip this migration as well.
      if ($src_bundle && empty($instances_per_entity[$src_entity_type_id][$src_bundle])) {
        continue;
      }

      $dependencies_required = isset($migration_data['migration_dependencies']['required'])
        ? $migration_data['migration_dependencies']['required']
        : [];
      // If the migration source is bundle-specific then we might be more
      // precise.
      $all_allowed_term_dependencies = $src_bundle
        ? [$instances_per_entity[$src_entity_type_id][$src_bundle]]
        : $instances_per_entity[$src_entity_type_id];

      foreach ($all_allowed_term_dependencies as $term_dependencies_per_bundle) {
        foreach ($term_dependencies_per_bundle as $vocabulary_machine_name) {
          $term_migration_id = "d7_taxonomy_term:$vocabulary_machine_name";
          if (isset($migrations[$term_migration_id])) {
            $dependencies_required[] = $term_migration_id;
          }
        }
      }

      $migrations[$migration_id]['migration_dependencies']['required'] = array_unique(array_values($dependencies_required));
    }
  }

  /**
   * Returns the machine names of the taxonomy vocabularies.
   *
   * @return array
   *   The machine names of the taxonomy terms from the source database, keyed
   *   by their vocabulary ID.
   */
  protected static function getD7TaxonomyVocabularyIdMachineNameMap(): array {
    // The Taxonomy module might be uninstalled.
    try {
      $vocabulary_source = static::getSourcePlugin('d7_taxonomy_vocabulary');
    }
    catch (PluginNotFoundException $e) {
      return [];
    }
    assert($vocabulary_source instanceof DrupalSqlBase);

    try {
      $vocabulary_source->checkRequirements();
    }
    catch (RequirementsException $e) {
      // The taxonomy module can be unused on the source – we don't have to do
      // anything.
      return [];
    }

    $vocabulary_info = [];
    foreach ($vocabulary_source as $vocabulary_row) {
      assert($vocabulary_row instanceof Row);
      $source = $vocabulary_row->getSource();
      $vocabulary_info[$source['vid']] = $source['machine_name'];
    }

    return $vocabulary_info;
  }

  /**
   * Discover taxonomy term reference fields.
   *
   * @return array
   *   The machine names of the taxonomy vocabularies that are used in entity
   *   bundles, keyed by the source entity type ID and the source entity bundle.
   */
  protected static function getD7TaxonomyTermReferenceFieldInfo(): array {
    if (empty($vocabulary_info = self::getD7TaxonomyVocabularyIdMachineNameMap())) {
      return [];
    }
    // The Field module might be uninstalled.
    try {
      $field_config_source = static::getSourcePlugin('d7_field_instance');
    }
    catch (PluginNotFoundException $e) {
      return [];
    }
    assert($field_config_source instanceof DrupalSqlBase);

    $instances_per_entity = [];
    foreach ($field_config_source as $instance_row) {
      assert($instance_row instanceof Row);
      $source = $instance_row->getSource();
      // Only process taxonomy_term_reference fields.
      if (empty($source['type']) || $source['type'] !== 'taxonomy_term_reference') {
        continue;
      }
      // The "allowed_vid" key is computed from the field storage configuration
      // by Drupal\field\Plugin\migrate\source\d7\FieldInstance::prepareRow().
      // Without an explicitly set allowed vocabulary, it is impossible to
      // add/edit the values of a taxonomy_term_reference field with only core,
      // and anyway: if it is empty, it is impossible to determine which term
      // migration we have to depend on without fetching the actual values.
      if (empty($source['allowed_vid'])) {
        continue;
      }

      $allowed_vocabulary_machine_names = array_reduce($source['allowed_vid'], function (array $carry, array $item) use ($vocabulary_info) {
        if (!empty($vocabulary_info[$item['vid']])) {
          $carry[] = $vocabulary_info[$item['vid']];
        }
        return $carry;
      }, []);

      $preexisting_bundles = isset($instances_per_entity[$source['entity_type']][$source['bundle']])
        ? $instances_per_entity[$source['entity_type']][$source['bundle']]
        : [];

      $instances_per_entity[$source['entity_type']][$source['bundle']] = array_unique(array_merge($preexisting_bundles, $allowed_vocabulary_machine_names));
    }

    return $instances_per_entity;
  }

  /**
   * Adds plugin availability checker process plugin to migrations.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function addFieldTypePluginChecker(array &$migrations) {
    foreach ($migrations as $migration_id => $migration_data) {
      // Assuming that every migration has a source plugin.
      $source_plugin_id = $migration_data['source']['plugin'];
      if (!in_array($source_plugin_id, array_keys(self::PLUGIN_AVAILABILITY_CHECK_MAP), TRUE)) {
        continue;
      }

      foreach (self::PLUGIN_AVAILABILITY_CHECK_MAP[$source_plugin_id] as $process_to_alter => $plugin_config) {
        if (empty($migration_data['process'][$process_to_alter])) {
          continue;
        }

        $property_process = self::convertProcessToArrayOfProcesses($migration_data['process'][$process_to_alter]);
        $property_process[] = ['plugin' => 'ensure_plugin_available'] + $plugin_config;
        $migration_data['process'][$process_to_alter] = $property_process;

        // When a message arg needs to use an already processed property (that
        // starts with "@"), then we have to make sure that the current process
        // runs after that property has been processed.
        $processed_properties_from_args = array_reduce($plugin_config['message_args'] ?? [], function (array $carry, string $argument) {
          if (strpos($argument, '@') === 0) {
            $carry[] = substr($argument, 1);
          }
          return $carry;
        }, []);
        // Also handle the case when there is a source_override defined, and it
        // requires an already processed property.
        if (!empty($plugin_config['source_override']) && strpos($plugin_config['source_override'], '@') === 0) {
          $processed_properties_from_args = array_unique(array_merge($processed_properties_from_args, [$plugin_config['source_override']]));
        }

        // Update the order of the processes.
        foreach ($processed_properties_from_args as $processed_property) {
          $process_to_alter_pos = array_search($process_to_alter, array_keys($migration_data['process']));
          $current_process_pos = array_search($processed_property, array_keys($migration_data['process']));

          if ($current_process_pos > $process_to_alter_pos) {
            $all_processes_excluding_altered = $migration_data['process'];
            unset($all_processes_excluding_altered[$process_to_alter]);
            $current_process_pos = array_search($processed_property, array_keys($all_processes_excluding_altered));
            $migration_data['process'] = array_merge(array_slice($all_processes_excluding_altered, 0, $current_process_pos + 1, TRUE), [
              $process_to_alter => $migration_data['process'][$process_to_alter],
            ], array_slice($all_processes_excluding_altered, $current_process_pos + 1, NULL, TRUE));
          }
        }

        $migrations[$migration_id] = $migration_data;
      }
    }
  }

  /**
   * Omits field-related migrations for missing entity types.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function omitFieldMigrationsForMissingEntityTypes(array &$migrations) {
    $omitted_migration_plugin_ids = [];

    foreach ($migrations as $migration_id => $migration_data) {
      $base_plugin_id = $migration_data['id'];
      if (!in_array($base_plugin_id, self::FIELD_MIGRATION_PLUGIN_IDS, TRUE)) {
        continue;
      }

      $source_entity_type_id = $migration_data['source']['entity_type'];
      $destination_entity_type_id = isset(self::ENTITY_TYPE_KNOWN_REMAP[$source_entity_type_id])
        ? self::ENTITY_TYPE_KNOWN_REMAP[$source_entity_type_id]
        : $source_entity_type_id;

      if (!$this->entityTypeManager->hasDefinition($destination_entity_type_id)) {
        unset($migrations[$migration_id]);
        $omitted_migration_plugin_ids[$destination_entity_type_id][] = $migration_id;
      }
    }

    foreach ($omitted_migration_plugin_ids as $entity_type_id => $ids) {
      $this->logger->debug('Omitted @count field-related migration plugin (@migration-plugin-ids) because the entity type "@entity-type-id" does not exist on the destination site.', [
        '@count' => count($ids),
        '@migration-plugin-ids' => implode(', ', $ids),
        '@entity-type-id' => $entity_type_id,
      ]);
    }
  }

  /**
   * Sets the high_water_property or track_changes on all migrations.
   *
   * This will set the `track_changes` migration source property to TRUE, unless
   * one of two things are true. Either 1) the migration already has a the
   * `track_changes` or `high_water_property` configured or 2) the migrate
   * destination is A) a content entity and B) that entity has a property to
   * record the last modified time (usually this property is named `changed`)
   * and C) that property is mapped to a migrate source property.
   *
   * In almost every circumstance, it is preferable to use the
   * `high_water_property` feature instead of the `track_changes` feature
   * because it is significantly more efficient to load and import new and
   * modified content. The `track_changes` feature requires that *every* source
   * row be loaded, hashed, and compared to a previous hash of that row which
   * can lead to many unnecessary computations for unchanged content. On the
   * other hand, the `high_water_property` feature means that *only* new and
   * modified rows will be loaded from the database. On a site with many
   * thousands of rows and infrequently changing content, the `track_changes`
   * feature may take minutes or hours to "refresh" a migration and only seconds
   * to achieve the same results using the `high_water_property` feature.
   *
   * Unfortunately, not all source rows have the necessary information to make
   * the `high_water_property` feature viable. To be viable, the source row must
   * have a value that increments every time that row changes. The canonical
   * example is a timestamp recorded when the content was modified. For Drupal
   * entities, this is is typically a "changed" field. Nearly all content
   * entities have this field as of Drupal 8, but not all of their corresponding
   * entities in Drupal 7 have this information recorded. Users and custom
   * blocks are the most salient examples and so their migrations must fall back
   * to the `track_changes` feature.
   *
   * @param array[] $migrations
   *   An associative array of migrations keyed by migration ID, the same that
   *   is passed to hook_migration_plugins_alter() hooks.
   */
  public function addChangeTracking(array &$migrations) {
    foreach ($migrations as $migration_id => $definition) {
      // Only add change tracking to Drupal 7 migrations.
      $is_drupal_7_migration = !empty($definition['migration_tags']) && is_array($definition['migration_tags']) && in_array('Drupal 7', $definition['migration_tags'], TRUE);
      if (!$is_drupal_7_migration) {
        continue;
      }
      // Only continue if the migration plugin already has a change tracking
      // option set up.
      if (isset($migrations[$migration_id]['source']['high_water_property']) || isset($migrations[$migration_id]['source']['track_changes'])) {
        continue;
      }
      // Determine if the migration's destination is a content entity. If not,
      // fall back to using the track_changes feature since the migration plugin
      // is likely importing configuration.
      $destination_plugin_id = $definition['destination']['plugin'] ?? NULL;
      if (!$destination_plugin_id) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      $destination_plugin_definition = $this->destinationPluginManager->getDefinition($destination_plugin_id, FALSE);
      $destination_plugin_class = $destination_plugin_definition['class'] ?? NULL;
      if (!$destination_plugin_class) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      if (!is_a($destination_plugin_class, EntityContentBase::class, TRUE)) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      // Determine if the destination content entity has a field to track last
      // modified time. If not, fall back to using the track_changes feature.
      $destination_entity_type_id = array_pad(explode(Entity::DERIVATIVE_SEPARATOR, $destination_plugin_id), 2, NULL)[1];
      if (!$destination_entity_type_id) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      $destination_base_fields = $this->entityFieldManager->getBaseFieldDefinitions($destination_entity_type_id);
      $changed_fields = array_filter($destination_base_fields, function (BaseFieldDefinition $base_field_definition) {
        return $base_field_definition->getType() === 'changed';
      });
      if (count($changed_fields) !== 1) {
        assert(count($changed_fields) < 2, sprintf('Entities should not have more than one changed field. The %s entity type violates that assumption.', $destination_entity_type_id));
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      // Since the destination has a changed field, determine if it is mapped to
      // a source field. If it is, then use that source field as a high water
      // property. If not, fall back to using the track_changes feature.
      $changed_field = current($changed_fields);
      assert($changed_field instanceof BaseFieldDefinition);
      $field_name = $changed_field->getName();
      $mapping = $definition['process'][$field_name] ?? NULL;
      if (!$mapping) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      $source_field = is_array($mapping) ? ($mapping['source'] ?? NULL) : $mapping;
      if (!$source_field) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      $source_plugin_id = $definition['source']['plugin'] ?? NULL;
      // A source field mapping has been identified. Now ensure that the source
      // plugin actually provides this field. Unfortunately, this is not
      // guaranteed by the core migrate system.
      try {
        $stub_migration_plugin = new StubMigrationPlugin();
        $source_plugin = $this->sourcePluginManager->createInstance($source_plugin_id, $definition['source'], $stub_migration_plugin);
      }
      catch (PluginException $e) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      if (!$source_plugin instanceof MigrateSourceInterface) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      $fields = $source_plugin->fields();
      if (!isset($fields[$source_field])) {
        $migrations[$migration_id]['source']['track_changes'] = TRUE;
        continue;
      }
      $migrations[$migration_id]['source']['high_water_property'] = [
        'name' => $source_field,
      ];

      // When the source plugin queries multiple tables and multiple tables have
      // the source plugin's high_water_property source field name as one of
      // their columns, then set the optional `alias` to ensure the correct
      // table alias is specified. Otherwise the SQL query will fail due to an
      // ambiguous query.
      if (count($source_plugin->query()->getTables()) > 1) {
        $selected_table_alias = NULL;
        $tables = $source_plugin->query()->getTables();
        $schema = $source_plugin->getDatabase()->schema();
        // Determine in which tables in the query the high_water_property column
        // exists.
        $candidate_table_aliases = [];
        foreach ($tables as $alias => $table_info) {
          if ($schema->fieldExists($table_info['table'], $source_field)) {
            $candidate_table_aliases[$table_info['table']] = $alias;
          }
        }
        // Only add an alias if the high_water_property column truly exists in
        // multiple tables.
        if (count($candidate_table_aliases) > 1) {
          $base_migration_plugin_id = $definition['id'];
          $selected_table_alias = NULL;
          $is_heuristic = FALSE;
          switch ($base_migration_plugin_id) {
            case 'd7_comment':
              // @see \Drupal\comment\Plugin\migrate\source\d7\Comment::query()
              $selected_table_alias = 'c';
              break;

            default:
              $selected_table_alias = reset(array_keys($candidate_table_aliases));
              $is_heuristic = TRUE;
              break;
          }
          $level = $is_heuristic
            ? RfcLogLevel::WARNING
            : RfcLogLevel::DEBUG;
          $this->logger->log($level, 'The high_water_property "@column-name" for the base migration plugin "@migration-plugin-id" occurred in @count tables in the query, of which @count-column contain this column. The alias "@alias" for the table "@table" was selected. (@known-or-heuristic)', [
            '@column-name' => $source_field,
            '@migration-plugin-id' => $migration_id,
            '@count' => count($tables),
            '@count-column' => count($candidate_table_aliases),
            '@alias' => $selected_table_alias,
            '@table' => $tables[$selected_table_alias]['table'],
            '@known-or-heuristic' => $is_heuristic ? 'Heuristic, should be investigated and hardcode a known disambiguation.' : 'Known ambiguity.',
          ]);
          $migrations[$migration_id]['source']['high_water_property']['alias'] = $selected_table_alias;
        }
      }
    }
  }

  /**
   * Converts a migration process definition to an array of processes.
   *
   * @param array|string $process
   *   The property migration process definition from a migration.
   *
   * @return array
   *   The process definition as array of process definitions.
   */
  protected static function convertProcessToArrayOfProcesses($process): array {
    if (is_string($process)) {
      $process = [
        [
          'plugin' => 'get',
          'source' => $process,
        ],
      ];
    }
    elseif (is_array($process) && array_key_exists('plugin', $process)) {
      $process = [$process];
    }

    return $process;
  }

}

/**
 * StubMigrationPlugin to allow inspecting @MigrateSource plugins.
 */
class StubMigrationPlugin extends MigrationPlugin {

  /**
   * StubMigrationPlugin constructor.
   */
  public function __construct() {
    // Intentionally ignore parent constructor.
  }

  /**
   * {@inheritdoc}
   */
  public function getIdMap() {
    // Avoid creating an ID map plugin.
    return NULL;
  }

}
