<?php

namespace Drupal\acquia_migrate\Plugin\migrate;

use Drupal\field\Plugin\migrate\field\d7\NodeReference;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * Plugin replacement for node_reference migrate field plugin.
 *
 * @see acquia_migrate_migrate_field_info_alter()
 */
class AcquiaMigrateNodeReference extends NodeReference {

  /**
   * {@inheritdoc}
   */
  public function defineValueProcessPipeline(MigrationInterface $migration, $field_name, $data) {
    $process = [
      'plugin' => 'sub_process',
      'source' => $field_name,
      'process' => [
        'target_id' => [
          [
            'plugin' => 'skip_on_empty',
            'source' => 'nid',
            'method' => 'process',
          ],
          [
            'plugin' => 'acquia_migrate_migration_lookup',
            'migration' => 'd7_node_complete',
          ],
          [
            'plugin' => 'acquia_migrate_default_value',
            'default_value_source' => ['nid'],
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
