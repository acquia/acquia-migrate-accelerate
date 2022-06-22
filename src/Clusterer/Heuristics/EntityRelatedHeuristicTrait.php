<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer\Heuristics;

use Drupal\Component\Plugin\PluginBase;
use Drupal\migrate\Plugin\Migration;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;

/**
 * Logic used by entity-related heuristics.
 */
trait EntityRelatedHeuristicTrait {

  /**
   * IDs of known config destination plugins which aren't "entity" derivatives.
   *
   * @var string[]
   */
  protected static $knownConfigDestinationPlugins = [
    'component_entity_display',
    'component_entity_form_display',
  ];

  /**
   * Checks whether the given migration's destination is a config entity.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if this is a config entity migration, false otherwise.
   */
  protected static function isConfigEntityDestination(MigrationPlugin $migration_plugin) : bool {
    $destination_plugin_id = $migration_plugin->getDestinationPlugin()->getPluginId();
    return in_array('Configuration', $migration_plugin->getMigrationTags(), TRUE) &&
      (
        strpos($destination_plugin_id, 'entity:') === 0 ||
        in_array($destination_plugin_id, self::$knownConfigDestinationPlugins, TRUE)
      );
  }

  /**
   * Returns the entity type and the bundle parameters of the source plugin.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration to parse.
   *
   * @return string[]
   *   The destination entity type ID, if the migration's destination is an
   *   entity, NULL otherwise.
   */
  protected static function getMigrationSourceEntityParameters(MigrationPlugin $migration_plugin): array {
    $source_config = $migration_plugin->getSourceConfiguration();
    $entity_type = NULL;
    $bundle = NULL;

    switch ($migration_plugin->getBaseId()) {
      case 'd7_url_alias':
      case 'd7_menu_links':
      case 'd7_menu_links_localized':
      case 'd7_menu_links_translation':
      case 'node_translation_menu_links':
      case 'd7_path_redirect':
      case 'd7_metatag_field_instance':
      case 'd7_metatag_field_instance_widget_settings':
        $entity_type = $source_config['entity_type_id'] ?? NULL;
        $bundle = $source_config['bundle'] ?? NULL;
        break;

      // This is for the (legacy) migration path provided by
      // https://www.drupal.org/project/paragraphs.
      case 'multifield_type':
      case 'multifield_translation_settings':
        // This is for the (improved) migration path provided by
        // https://www.drupal.org/project/paragraphs_migration.
      case 'pm_multifield_type':
      case 'pm_multifield_translation_settings':
        $entity_type = 'multifield';
        break;

      case 'statistics_node_counter':
      case 'statistics_node_translation_counter':
        $entity_type = 'node';
        $bundle = $source_config['node_type'];
        break;

      default:
        $entity_type = $source_config['entity_type'] ?? $source_config['constants']['entity_type'] ?? NULL;
        $bundle = $source_config['node_type'] ?? $source_config['bundle'] ?? $source_config['type'] ?? NULL;
    }

    // Some of the comment related migrations are derived based on their host
    // node type ID (node bundle), others are derived based on the comment
    // bundle, which is "comment_node_<host-node-bundle>" or for forum comments,
    // it is "comment_forum".
    // We want to standardize this: let's always use the host node type.
    if ($entity_type === 'comment' && $bundle && !isset($source_config['node_type'])) {
      $bundle = preg_replace(
        [
          '/^comment_node_(.+)$/',
          // "d7_entity_reference_translation" contains the destination comment
          // bundle, which is "comment_forum".
          '/^comment_forum$/',
        ],
        [
          '${1}',
          'forum',
        ],
        $bundle
      );
    }

    return [
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ];
  }

  /**
   * Returns the destination entity type ID.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration to parse.
   *
   * @return string|null
   *   The destination entity type ID, if the migration's destination is an
   *   entity, NULL otherwise.
   */
  protected static function getDestinationEntityTypeId(MigrationPlugin $migration) : ?string {
    $destination_plugin = $migration->getDestinationPlugin();
    $destination_plugin_id = $destination_plugin->getPluginId();
    $destination_plugin_id_parts = explode($migration::DERIVATIVE_SEPARATOR, $destination_plugin_id);
    $entity_destination_base_plugin_ids = [
      'entity',
      'entity_revision',
      'entity_complete',
      'entity_reference_revisions',
    ];
    $entity_destination = in_array($destination_plugin_id_parts[0], $entity_destination_base_plugin_ids, TRUE);
    return $entity_destination
      ? $destination_plugin_id_parts[1]
      : NULL;
  }

  /**
   * Check whether a migration is content entity migration.
   *
   * Tag 'Content' is a requirement, but every migration can be excluded here
   * that are not considered part of the content model from the site builder's
   * perspective, e.g. path aliases.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if the migration is tagged with 'Content' and if its destination
   *   is considered part of the content model.
   */
  protected static function isContentEntityDestination(Migration $migration) : bool {
    $destination_plugin = $migration->getDestinationPlugin();
    assert($destination_plugin instanceof PluginBase);
    $destination_entity_type = self::getDestinationEntityTypeId($migration);
    $source_has_entity_type_parameter = !is_null(self::getMigrationSourceEntityParameters($migration)['entity_type']);
    // URL aliases are entities too as of Drupal 8.8.0, but they are not
    // considered part of the content model from the site builder's
    // perspective, so: explicitly exclude them. Except for the derivative
    // that contains the path aliases that do not target entities.
    // @see https://www.drupal.org/node/3013865.
    if ($destination_entity_type === 'path_alias' && $source_has_entity_type_parameter) {
      return FALSE;
    }

    // Exclude menu link content entities as well, except for the derivative
    // that contains the 'every other' menu links.
    // @see https://www.drupal.org/node/3013865.
    if ($destination_entity_type === 'menu_link_content' && $source_has_entity_type_parameter) {
      return FALSE;
    }

    // Exclude redirect content entities as well, except for the derivative
    // that contains the 'every other' redirects.
    // @see https://www.drupal.org/project/redirect/issues/3082364
    if ($destination_entity_type === 'redirect' && $source_has_entity_type_parameter) {
      return FALSE;
    }

    // We do not want to treat paragraph, field collection and multifield
    // migrations as stand-alone content entities, because they always belong to
    // a 'real' content entity, and we are able to migrate them per content
    // entity bundle. Paragraph and field collection migrations are using the
    // 'entity_reference_revisions' destination plugin, but multifield uses
    // 'entity_complete' (usually used for stand-alone content entities), hence
    // the need for this additional check.
    if ($migration->getBaseId() === 'pm_multifield' || $migration->getBaseId() === 'multifield') {
      return FALSE;
    }

    $destination_plugin_base_ids = [
      'entity',
      'entity_complete',
      'entity_revision',
    ];

    return in_array('Content', $migration->getMigrationTags(), TRUE) &&
      in_array($destination_plugin->getBaseId(), $destination_plugin_base_ids, TRUE);
  }

  /**
   * Check whether a migration is content entity translation migration.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if the migration is tagged with "Content" and with ("translation" OR
   *   "Multilingual") and the destination plugin's base ID is "entity.
   */
  protected static function isContentEntityTranslationDestination(Migration $migration) : bool {
    return static::isContentEntityDestination($migration) &&
      static::isTranslationMigration($migration);
  }

  /**
   * Check whether a migration is content entity translation migration.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if the migration is tagged with "translation" OR "Multilingual".
   */
  protected static function isTranslationMigration(Migration $migration) : bool {
    $tags = $migration->getMigrationTags();
    return in_array('translation', $tags, TRUE) ||
      in_array('Multilingual', $tags, TRUE);
  }

  /**
   * Check whether this migration migrates a content entity default translation.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration
   *   The migration plugin instance to check.
   *
   * @return bool
   *   TRUE if the migration is tagged with "Content" and does not have
   *   "translation" nor "Multilingual" tags.
   */
  protected static function isContentEntityDefaultTranslationDestination(Migration $migration) : bool {
    return static::isContentEntityDestination($migration) &&
      !static::isTranslationMigration($migration);
  }

}
