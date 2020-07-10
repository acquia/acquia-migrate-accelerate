<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\migrate\Plugin\MigrateDestinationInterface;

/**
 * Rollbackable destination interface.
 *
 * Shim for our destination plugin replacements that make non-rollbackable
 * migrations rollbackable.
 */
interface RollbackableInterface extends MigrateDestinationInterface {

  /**
   * The rollback data table name.
   *
   * @var string
   */
  const ROLLBACK_DATA_TABLE = 'acquia_migrate_config_rollback_data';

  /**
   * The rollback state table name.
   *
   * @var string
   */
  const ROLLBACK_STATE_TABLE = 'acquia_migrate_config_new';

  /**
   * Plugin ID column name.
   *
   * @var string
   */
  const ROLLBACK_MIGRATION_PLUGIN_ID_COL = 'migration_plugin_id';

  /**
   * Config ID column name.
   *
   * @var string
   */
  const ROLLBACK_CONFIG_ID_COL = 'config_id';

  /**
   * Langcode column name.
   *
   * @var string
   */
  const ROLLBACK_CONFIG_LANGCODE_COL = 'langcode';

  /**
   * Rollback data column name.
   *
   * @var string
   */
  const ROLLBACK_DATA_COL = 'rollback_data';

  /**
   * Name of the field name column name.
   *
   * @var string
   */
  const ROLLBACK_DISPLAY_FIELD_NAME_COL = 'field_name';

}
