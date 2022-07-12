<?php

namespace Drupal\acquia_migrate\Plugin\migrate;

use Drupal\field\Plugin\migrate\field\d7\EntityReference;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * Plugin replacement for entityreference migrate field plugin.
 */
class AcquiaMigrateEntityReference extends EntityReference {

  /**
   * {@inheritdoc}
   */
  public function defineValueProcessPipeline(MigrationInterface $migration, $field_name, $data) {
    $field_definition_data_raw = $data['field_definition']['data'] ?? serialize(NULL);
    $field_definition_data = unserialize($field_definition_data_raw, ['allowed_classes' => FALSE]);
    $target_type = $field_definition_data['settings']['target_type'] ?? NULL;

    // Only 'node' target types should be handled differently.
    // @see acquia_migrate_migrate_field_info_alter()
    if ($target_type !== 'node') {
      parent::defineValueProcessPipeline($migration, $field_name, $data);
      return;
    }

    $process = [
      'plugin' => 'sub_process',
      'source' => $field_name,
      'process' => [
        'target_id' => [
          [
            'plugin' => 'skip_on_empty',
            'source' => 'target_id',
            'method' => 'process',
          ],
          [
            'plugin' => 'migmag_lookup',
            'migration' => 'd7_node_complete',
          ],
          [
            'plugin' => 'acquia_migrate_default_value',
            'default_value_source' => ['target_id'],
          ],
          [
            'plugin' => 'extract',
            'index' => [0],
          ],
        ],
      ],
    ];

    $migration->mergeProcessOfProperty($field_name, $process);
  }

}
