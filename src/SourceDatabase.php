<?php

namespace Drupal\acquia_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;

/**
 * Utilities for working with the migrate source database.
 *
 * @internal
 */
final class SourceDatabase {

  /**
   * The expected database target and key for the migrate source database.
   *
   * @var array[string]string
   *
   * @see \Drupal\migrate\Plugin\migrate\source\SqlBase::setUpDatabase()
   */
  protected static $defaultConnectionDetails = [
    'target' => 'default',
    'key' => 'migrate',
  ];

  /**
   * Whether a source database connection has been configured.
   *
   * @return bool
   *   TRUE if a source database connection is available, FALSE otherwise.
   */
  public static function isConnected(): bool {
    try {
      static::getConnection()->select(MigrationFingerprinter::CANARY_TABLE)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (ConnectionNotDefinedException $e) {
      return FALSE;
    }
    catch (DatabaseExceptionWrapper $e) {
      // If even the migration fingerprinter's canary table is not present, then
      // the database cannot contain Drupal 7 data. Merely a valid database
      // connection is insufficient for Acquia Migrate Accelerate to function.
      return FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Gets the migrate source database connection.
   *
   * Callers should test AcquiaMigrateSourceDatabase::isConnected() before
   * calling this method.
   *
   * @return \Drupal\Core\Database\Connection
   *   The connection.
   */
  public static function getConnection(): Connection {
    $source_connection_details = \Drupal::state()->get('acquia_migrate_test_database', static::$defaultConnectionDetails);
    return Database::getConnection($source_connection_details['target'], $source_connection_details['key']);
  }

}
