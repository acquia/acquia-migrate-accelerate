<?php

namespace Drupal\acquia_migrate\Batch;

use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_drupal_ui\Batch\MigrateMessageCapture;

/**
 * Runs a single migration batch.
 *
 * Inspired by \Drupal\migrate_drupal_ui\Batch\MigrateUpgradeRollbackBatch.
 *
 * @internal
 */
final class MigrateUpgradeRollbackBatch {

  /**
   * Whether all rollbacks were completed.
   *
   * @var bool
   */
  public static $completed = FALSE;

  /**
   * The processed items for one batch of a given migration.
   *
   * @var int
   */
  protected static $numProcessed = 0;

  /**
   * MigrateMessage instance to capture messages during the migration process.
   *
   * @var \Drupal\migrate_drupal_ui\Batch\MigrateMessageCapture
   */
  protected static $messages;

  /**
   * Runs a single migrate batch import.
   *
   * @param int[] $initial_ids
   *   The full set of migration IDs to import.
   * @param array $context
   *   The batch context.
   */
  public static function run(array $initial_ids, array &$context) {
    if (!isset($context['sandbox']['migration_ids'])) {
      // If the rollback encounters an error, this will be marked FALSE.
      static::$completed = TRUE;
      $context['sandbox']['max'] = count($initial_ids);
      $context['sandbox']['current'] = 1;
      // migration_ids will be the list of IDs remaining to run.
      $context['sandbox']['migration_ids'] = $initial_ids;
    }

    // Number processed in this batch.
    static::$numProcessed = 0;

    $migration_id = reset($context['sandbox']['migration_ids']);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
    assert($migration instanceof MigrationInterface);

    try {
      $destination_plugin = $migration->getDestinationPlugin(FALSE);
    }
    catch (MigrateSkipRowException $e) {
      assert(FALSE, 'This exception is considered impossible because it should only occur when a stub is being requested from the destination plugin.');
      $context['finished'] = (int) TRUE;
      static::$completed = FALSE;
    }

    if ($destination_plugin->supportsRollback()) {
      static::$messages = new MigrateMessageCapture();

      try {
        $executable = new MigrateExecutable($migration, static::$messages);
        $executable->rollback();
        array_shift($context['sandbox']['migration_ids']);
        $context['sandbox']['current']++;
        $context['finished'] = 1 - count($context['sandbox']['migration_ids']) / $context['sandbox']['max'];
        static::$numProcessed++;
      }
      catch (\Exception $e) {
        \Drupal::logger('acquia_migrate')->error($e->getMessage());
        $context['finished'] = (int) TRUE;
        static::$completed = FALSE;
      }
    }
    else {
      \Drupal::logger('acquia_migrate')->error(sprintf('Unable to complete rollback because the %s migration does not support rollbacks.', $migration->label()));
      $context['finished'] = (int) TRUE;
      static::$completed = FALSE;
    }

    if ($context['finished'] === 1) {
      $completed =& drupal_static('acquia_migrate__rollback_completed');
      $completed = static::$completed;
    }
  }

}
