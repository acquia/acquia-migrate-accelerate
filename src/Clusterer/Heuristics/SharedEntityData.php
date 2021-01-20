<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use League\Container\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Special entity types such as Paragraphs have the concept of "shared data".
 */
final class SharedEntityData implements IndependentHeuristicInterface, HeuristicWithComputedClusterInterface, ContainerInjectionInterface {

  use EntityRelatedHeuristicTrait;
  use StringTranslationTrait;
  use ContainerAwareTrait;

  /**
   * Base plugin IDs of paragraph entity migrations.
   *
   * @var string[]
   */
  const PARAGRAPHS_MIGRATION_BASE_PLUGIN_IDS = [
    'd7_paragraphs',
    'd7_paragraphs_revisions',
    'd7_field_collection',
    'd7_field_collection_revisions',
  ];

  /**
   * IDs of the legacy entity types migrated to paragraph entities.
   *
   * @var string[]
   */
  const PARAGRAPHS_LEGACY_ENTITY_TYPE_IDS = [
    'paragraphs_item',
    'field_collection_item',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function id() : string {
    return 'shared_data';
  }

  /**
   * Constructs a new SharedEntityData.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function matches(MigrationPlugin $migration_plugin) : bool {
    $dest_entity_type_id = self::getDestinationEntityTypeId($migration_plugin);

    // Nested paragraph migrations.
    if ($dest_entity_type_id !== 'paragraph') {
      return FALSE;
    }

    $plugin_id_parts = explode(PluginBase::DERIVATIVE_SEPARATOR, $migration_plugin->id());
    if (count($plugin_id_parts) < 3) {
      return FALSE;
    }
    [
      $base_plugin_id,
      $parent_entity_type_id,
    ] = $plugin_id_parts;
    if (
      in_array($base_plugin_id, self::PARAGRAPHS_MIGRATION_BASE_PLUGIN_IDS, TRUE) &&
      in_array($parent_entity_type_id, self::PARAGRAPHS_LEGACY_ENTITY_TYPE_IDS, TRUE)
    ) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function computeCluster(MigrationPlugin $migration_plugin) : string {
    $source_parent_type = $migration_plugin->getSourceConfiguration()['parent_type'] ?? 'unknown';
    $dest_entity_type_id = self::getDestinationEntityTypeId($migration_plugin) ?? 'unknown';

    switch ("$dest_entity_type_id::$source_parent_type") {
      case 'paragraph::field_collection_item':
        $entity_type_plural_label = $this->t('nested field collection items');
        break;

      case 'paragraph::paragraphs_item':
        $entity_type_plural_label = $this->t('nested paragraphs');
        break;

      default:
        $entity_type = $this->entityTypeManager->getDefinition($dest_entity_type_id, FALSE);
        $entity_type_plural_label = $entity_type ? $entity_type->getPluralLabel() : $dest_entity_type_id;
    }

    return (string) $this->t('Shared data for @entity-type-plural-label', [
      '@entity-type-plural-label' => $entity_type_plural_label,
    ]);
  }

}
