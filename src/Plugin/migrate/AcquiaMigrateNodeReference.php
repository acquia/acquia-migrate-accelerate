<?php

namespace Drupal\acquia_migrate\Plugin\migrate;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_drupal\Plugin\migrate\field\d7\NodeReference;

// @codingStandardsIgnoreStart
if (class_exists(NodeReference::class)) {
  // @todo Remove this complexity when 9.2.0 is released and we depend on it.
  abstract class AcquiaMigrateNodeReferenceBase extends NodeReference {}
}
else {
  abstract class AcquiaMigrateNodeReferenceBase extends \Drupal\field\Plugin\migrate\field\d7\NodeReference {}
}
// @codingStandardsIgnoreEnd

/**
 * Plugin replacement for node_reference migrate field plugin.
 *
 * @see acquia_migrate_migrate_field_info_alter()
 */
class AcquiaMigrateNodeReference extends AcquiaMigrateNodeReferenceBase {

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
            'plugin' => 'migmag_lookup',
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
