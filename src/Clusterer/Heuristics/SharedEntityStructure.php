<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\acquia_migrate\MigrationAlterer;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use League\Container\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One "shared structure" cluster per bundleable entity type.
 */
final class SharedEntityStructure implements IndependentHeuristicInterface, HeuristicWithComputedClusterInterface, ContainerInjectionInterface {

  use EntityRelatedHeuristicTrait;
  use StringTranslationTrait;
  use ContainerAwareTrait;

  /**
   * IDs of migration source plugins that should land in Media shared structure.
   *
   * @var string[]
   */
  const MEDIA_MIGRATION_SPECIAL_SHARED_SRC_PLUGIN_IDS = [
    // @see \Drupal\media_migration\Plugin\migrate\source\d7\MediaViewMode
    'd7_media_view_mode',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'shared_structure';
  }

  /**
   * Constructs a new SharedEntityStructure.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    $dest_entity_type_id = self::getDestinationEntityTypeId($migration_plugin);
    if ($dest_entity_type_id === 'paragraphs_type') {
      return TRUE;
    }

    // We need a standalone cluster for the menu (config entity) migration
    // that's shared across content entity migrations and "Other menu links"
    // migration.
    if ($dest_entity_type_id === 'menu') {
      return TRUE;
    }
    // Related special case: d7_language_content_menu_settings targets
    // menu_link_content entities, which are not bundleable, but we want it to
    // end up in the "Shared structure for menus" cluster.
    if ($migration_plugin->id() === 'd7_language_content_menu_settings') {
      return TRUE;
    }

    [
      'entity_type' => $source_entity_type,
      'bundle' => $source_bundle,
    ] = self::getMigrationSourceEntityParameters($migration_plugin);

    $src_config = $migration_plugin->getSourceConfiguration();
    $para_legacy_entity_type_ids = ['paragraphs_item', 'field_collection_item'];
    $migrations_which_have_entity_type_src_config = [
      'd7_field',
      'd7_field_instance',
      'd7_field_formatter_settings',
      'd7_field_instance_widget_settings',
      'd7_view_modes',
    ];

    if (in_array($source_entity_type, $para_legacy_entity_type_ids, TRUE) && in_array($migration_plugin->getBaseId(), $migrations_which_have_entity_type_src_config, TRUE)) {
      return TRUE;
    }

    if (!self::isConfigEntityDestination($migration_plugin)) {
      return FALSE;
    }

    // Shared structure for media.
    if (in_array($src_config['plugin'], self::MEDIA_MIGRATION_SPECIAL_SHARED_SRC_PLUGIN_IDS, TRUE)) {
      return TRUE;
    }

    // "shared structure" means it is shared across all bundles for a given
    // entity type.
    // Heuristic — some migration plugins might name this differently!
    // @todo Check if there are other such "shared structure" things besides
    //   d7_view_modes and d7_field.
    // @todo Document why not checking the destination's 'default_bundle' or
    //   the destination entity type id configuration?
    // If the migration is not for an entity type, or if it isn't
    // bundle-agnostic, it is definitely not a shared structure migration.
    if (!$source_entity_type || $source_bundle) {
      return FALSE;
    }

    // @todo Remove this line when derivatives for non-existing entity types
    //   cease to be generated.
    $expected_destination_entity_type = MigrationAlterer::ENTITY_TYPE_KNOWN_REMAP[$source_entity_type] ?? $source_entity_type;
    if (!$this->entityTypeManager->hasDefinition($expected_destination_entity_type)) {
      return FALSE;
    }

    // We only care about "shared structure" if there is something to share,
    // that is, if there are multiple bundles.
    $destination_definition = $this->entityTypeManager->getDefinition($expected_destination_entity_type, FALSE);
    $entity_type_has_bundles = $destination_definition && $destination_definition->getBundleEntityType() !== NULL;
    $dependencyless = empty($migration_plugin->getMetadata('after'));

    if ($entity_type_has_bundles && !$dependencyless) {
      throw new \LogicException(sprintf('The currently known shared structure migrations do not have any dependencies. This assumption does not hold for %s. It depends on: %s.', $migration_plugin->id(), implode(', ', $migration_plugin->getMetadata('after'))));
    }

    return $entity_type_has_bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin) : string {
    $source_config = $migration_plugin->getSourceConfiguration();
    $field_entity_type_id = $source_config['entity_type'] ?? NULL;
    $dest_entity_type_id = self::getDestinationEntityTypeId($migration_plugin);
    $configuration_for_dest_entity_type_id = $source_config['constants']['target_type'] ?? NULL;
    // Paragraph or field collection field config migrations have
    // 'paragraphs_item', 'field_collection_item' source entity type.
    // Rather than automatically generating a label for the Paragraphs &
    // Field Collection migrations, create these manually to ensure they
    // each get their own shared structure migration. (Otherwise there would
    // only be one, since both end up getting migrated into Paragraphs in
    // Drupal 9.)
    if (
      $field_entity_type_id === 'field_collection_item' ||
      $source_config['plugin'] === 'd7_field_collection_type'
    ) {
      $label = 'field collection items';
    }
    elseif (
      $field_entity_type_id === 'paragraphs_item' ||
      $source_config['plugin'] === 'd7_paragraphs_type'
    ) {
      $label = 'paragraphs';
    }
    // Media entities in Drupal 7 are (fieldable) file entities, so the
    // view mode and the field storage migration's source entity type ID
    // is "file".
    elseif (in_array($source_config['plugin'], self::MEDIA_MIGRATION_SPECIAL_SHARED_SRC_PLUGIN_IDS, TRUE) || $field_entity_type_id === 'file') {
      $entity_type = $this->entityTypeManager->getDefinition('media', FALSE);
      $label = $entity_type ? $entity_type->getPluralLabel() : 'media';
    }
    elseif ($field_entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($field_entity_type_id, FALSE);
      $label = $entity_type ? $entity_type->getPluralLabel() : $field_entity_type_id;
    }
    elseif ($configuration_for_dest_entity_type_id) {
      // F.e. d7_language_content_menu_settings.
      if ($configuration_for_dest_entity_type_id === 'menu_link_content') {
        $configuration_for_dest_entity_type_id = 'menu';
      }
      $entity_type = $this->entityTypeManager->getDefinition($configuration_for_dest_entity_type_id, FALSE);
      $label = $entity_type ? $entity_type->getPluralLabel() : $configuration_for_dest_entity_type_id;
    }
    elseif ($dest_entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($dest_entity_type_id, FALSE);
      $label = $entity_type ? $entity_type->getPluralLabel() : $dest_entity_type_id;
    }
    else {
      throw new \LogicException('label could not be determined');
    }

    return (string) $this->t('Shared structure for @entity-type-plural', [
      '@entity-type-plural' => $label,
    ]);
  }

}
