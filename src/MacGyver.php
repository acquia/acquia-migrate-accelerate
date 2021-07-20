<?php

namespace Drupal\acquia_migrate;

use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use Drupal\acquia_migrate\Batch\BatchStatus;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\SchemaObjectExistsException;

/**
 * MacGyver rescues us from abysmal performance due to unjoinable databases.
 *
 * WHO SAYS THESE TABLES CANNOT BE JOINED.
 * *cloud of smoke*
 * THEY WHO JOIN THE UNJOINABLE HAS ARRIVED.
 *
 * Introducing the Sourceination: the Drupal 7 Source database that lives
 * hidden in plain sight int the Drupal 9 Destination database, thanks to the
 * age-old magic of table prefixes. We affectionately call it Database
 * Injection ("SQL Injection" pales in comparison).
 *
 * To make Drupal aware of this Sourceination database connection without having
 * to get file system writing permissions, the AMA module generates a database
 * connection at runtime if the Sourceination has been successfully created.
 *
 * @see \Drupal\acquia_migrate\EventSubscriber\SourcinationRegisterer
 *
 * On a more boring but serious note:
 * - this contains some logic copied from \Drupal\Core\Command\DbDumpCommand
 *   (but with fixes: https://www.drupal.org/project/drupal/issues/3210913),
 *   specifically the following methods: ::getTables(), ::getTableSchema(),
 *   ::fieldTypeMap(), ::fieldSizeMap(), ::getTableIndexes(),
 *   ::getTableCollation().
 * - Whenever ::detectWhetherActionIsNeeded() returns TRUE, the HTTP API will
 *   return an `initial-import` link, which the client/UI will follow. This will
 *   trigger a batch: ::createDatabaseCopyBatch().
 * - To ensure the Sourceination is never stale, the refresh detection logic in
 *   \Drupal\acquia_migrate\MigrationFingerprinter::recomputeRecommended() will
 *   set the `acquia_migrate.ah.database_copied` key to FALSE. This will force
 *   ::detectWhetherActionIsNeeded() to return TRUE again.
 * - ::createDatabaseCopyBatch() will create a batch with the necessary
 *   operations, which eventually boil down to calling ::copyRecords() many,
 *   many, many times. (And setting some key-value pairs to ensure the migration
 *   system will use this alternative database connection.) If it's not
 *   first Sourceination it creates, it will also delete the stale one.
 *
 * It is possible to opt out.
 * @code
 * drush ev "\Drupal::keyValue('acquia_migrate')->get('acquia_migrate.ah.database_copied', TRUE);"
 * @endcode
 *
 * It is possible to completely reset MacGyver. First manually drop all tables
 * matching the regex /ama\d+_/ (so "ama1_", "ama2_", et cetera). Then run this:
 * @code
 * drush ev "\Drupal::state()->deleteMultiple(['migrate.fallback_state_key', 'acquia_migrate.ah.database_copy_time', 'acquia_migrate.ah.copy_start']);"
 * drush ev "\Drupal::keyValue('acquia_migrate')->deleteMultiple(['acquia_migrate.ah.current_copy_version', 'acquia_migrate.ah.database_copied', 'acquia_migrate.ah.database_copy_to_delete', 'acquia_migrate.ah.interrupted_database_copy_to_delete']);"
 * @encode
 *
 * @internal
 */
final class MacGyver {

  /**
   * The database tables we can safely assume to not be migration sources.
   *
   * @var string[]
   */
  const EXCLUDED_TABLES = [
    // Caches are rebuilt, not migrated.
    'cache_.+',
    // Migration metadata from migrations into Drupal 7 are irrelevant when
    // migrating to Drupal 9.
    'migrate_map_.+',
    'migrate_message_.+',
    // Avoid copying search indices.
    'search_api_item*',
    'search_dataset',
    'search_index',
    'search_node_links',
    'search_total',
     // Session metadata for the simplesamlphp_auth module is not migrated.
    'simplesaml_kvstore',
    'simplesaml_saml_LogoutStore',
    'simplesaml_tableVersion',
    // D7 watchdog is irrelevant in D9.
    'watchdog',
  ];

  /**
   * Read and copy rows per CHUNK_SIZE.
   *
   * With a high memory limit, this can easily be set to a higher amount.
   * In our extensive testing, we found setting the chunk size to 200 to be safe
   * while the PHP memory limit was 128 MB.
   * When we can assume a higher memory limit, we will also be able to increase
   * the chunk size.
   *
   * @const int
   */
  const CHUNK_SIZE = 200;

  /**
   * This key-value pair stores the current Sourceination DB version.
   *
   * The first Sourceination has version 1.
   *
   * If the database gets refreshed, Sourceination 1 continues to exist while
   * Sourceination 2 gets created. If Sourceination 2 finishes getting created,
   * Sourceination 1 gets deleted.
   *
   * This ensures:
   * - only one Sourceination ever exists.
   * - the Sourceination that is being copied is always the current
   *   Sourceination version plus one
   *
   * @const string
   */
  const CURRENT_SOURCE_DB_VERSION = 'acquia_migrate.ah.current_copy_version';

  /**
   * This key-value pair tracks if the Sourcination matches the current Source.
   *
   * - Non-existent key means it was never copied.
   * - During batch the key is deleted if it exists…
   * - … existent key set to FALSE is how we detect a mid-copy refresh.
   * - Existent key set to TRUE is when the Sourcination is up-to-date.
   *
   * @const string
   */
  const CAMOUFLAGE_REALISTIC = 'acquia_migrate.ah.database_copied';

  /**
   * This key-value pair tracks if there is a stale Sourcination to delete.
   *
   * @const string
   */
  const STALE_CAMOUFLAGE_LINGERING = 'acquia_migrate.ah.database_copy_to_delete';

  /**
   * This key-value pair tracks if there is a partial Sourcination to delete.
   *
   * @const string
   */
  const PARTIAL_CAMOUFLAGE_LINGERING = 'acquia_migrate.ah.interrupted_database_copy_to_delete';

  /**
   * This key-value pair tracks when the last Sourcination finished creating.
   *
   * @const string
   */
  const LAST_MACGYVER_EPISODE = 'acquia_migrate.ah.database_copy_time';

  /**
   * Gets the current database copy version.
   *
   * @return int
   *   A positive integer.
   */
  private static function getCurrentVersion() : int {
    return \Drupal::keyValue('acquia_migrate')->get(static::CURRENT_SOURCE_DB_VERSION, 0);
  }

  /**
   * Gets the next database copy version.
   *
   * @return int
   *   A positive integer.
   */
  private static function getNextVersion() : int {
    return self::getCurrentVersion() + 1;
  }

  /**
   * Computes a valid database prefix for a given version.
   *
   * @param int|null $version
   *   The version to generate a table prefix for; if omitted, then falls back
   *   to the current version.
   *
   * @return string
   *   A table prefix, including trailing underscore.
   */
  private static function computePrefix(int $version = NULL) : string {
    return sprintf("ama%d_", $version ?? self::getCurrentVersion());
  }

  /**
   * Whether a Sourceination is ready for use.
   *
   * @return bool
   *   TRUE when the Sourcination database connection is activated.
   *
   * @see \Drupal\acquia_migrate\EventSubscriber\SourcinationRegisterer
   */
  public static function isArmed() : bool {
    return \Drupal::state()->get('migrate.fallback_state_key') === 'acquia_migrate_copied_database';
  }

  /**
   * Ensure a Sourcination is available (and delete the old Sourcination).
   *
   * @return bool
   *   TRUE when action is needed
   */
  public static function detectWhetherActionIsNeeded() : bool {
    if (!AcquiaDrupalEnvironmentDetector::isAhEnv()) {
      return FALSE;
    }

    // @codingStandardsIgnoreStart
    return
      FALSE === \Drupal::keyValue('acquia_migrate')->get(static::CAMOUFLAGE_REALISTIC, FALSE)
      ||
      FALSE !== \Drupal::keyValue('acquia_migrate')->get(static::STALE_CAMOUFLAGE_LINGERING, FALSE);
    // @codingStandardsIgnoreEnd
  }

  /**
   * Gets a batch ID for the initial import batch.
   *
   * This method should merely create the batch, but not begin processing it.
   *
   * @return \Drupal\acquia_migrate\Batch\BatchStatus
   *   A batch ID.
   */
  public static function createDatabaseCopyBatch() : BatchStatus {
    // Start action, allow detecting a refresh mid-way.
    \Drupal::keyValue('acquia_migrate')->delete(static::CAMOUFLAGE_REALISTIC);

    \Drupal::state()->set('acquia_migrate.ah.copy_start', (int) \Drupal::time()->getRequestTime());
    \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
      sprintf("database_copy_event=%s|version=%d",
        'started',
        static::getNextVersion()
      )
    );

    $operations = [];
    $current_version = self::getCurrentVersion();
    $next_version = self::getNextVersion();

    // Previous MacGyvering was interrupted by a Refresh.
    if (\Drupal::keyValue('acquia_migrate')->get(static::PARTIAL_CAMOUFLAGE_LINGERING)) {
      $operations[] = [
        [__CLASS__, 'destroyStaleCamouflage'],
        [\Drupal::keyValue('acquia_migrate')->get(static::PARTIAL_CAMOUFLAGE_LINGERING)],
      ];
    }

    if (!\Drupal::keyValue('acquia_migrate')->get(static::STALE_CAMOUFLAGE_LINGERING, FALSE)) {
      $operations[] = [
        [__CLASS__, 'deployDevice'],
        [$next_version],
      ];

      // Camouflage (new copy) is ready, now deploy it (activate this prefix).
      $operations[] = [
        [__CLASS__, 'deployCamouflage'],
        [$next_version],
      ];

      // Check if there we need to delete the previous copy.
      if ($current_version > 0) {
        $operations[] = [
          [__CLASS__, 'destroyStaleCamouflage'],
          [$current_version],
        ];
      }
    }
    // Batch was interrupted before old DB copy could be deleted.
    else {
      $operations[] = [
        [__CLASS__, 'destroyStaleCamouflage'],
        [\Drupal::keyValue('acquia_migrate')->get(static::STALE_CAMOUFLAGE_LINGERING)],
      ];
    }

    $new_batch = [
      'operations' => $operations,
      'finished' => [__CLASS__, 'armDevice'],
    ];
    batch_set($new_batch);
    batch_process();
    $batch = batch_get();
    return new BatchStatus($batch['id'], 0);
  }

  /**
   * OP2: Swaps the camouflage if already active, otherwise prepares it.
   *
   * (After ::armDevice() has run at least once, this will atomatically swap the
   * Sourcination connection inter-request.)
   *
   * @param int $new_version
   *   The version of the new Soureination.
   * @param array $context
   *   The batch context array.
   */
  public static function deployCamouflage(int $new_version, array &$context) : void {
    if (static::interruptedByRefresh($new_version, $context)) {
      return;
    }

    \Drupal::keyValue('acquia_migrate')->set(self::CURRENT_SOURCE_DB_VERSION, $new_version);
    // Force the old copy to be deleted as part of this batch.
    if ($new_version > 1) {
      \Drupal::keyValue('acquia_migrate')->set(static::STALE_CAMOUFLAGE_LINGERING, $new_version - 1);
    }
  }

  /**
   * OP3: Destroys stale Sourceination, if any.
   *
   * @param int $stale_version
   *   The version of the stale Sourceination.
   * @param array $context
   *   The batch context array.
   */
  public static function destroyStaleCamouflage(int $stale_version, array &$context) : void {
    // Do not attempt to detect an interruption now, it's too late already. But
    // respect an interruption detected by one of the earlier batch operations.
    if (isset($context['results']['refresh_interrupt_detected'])) {
      return;
    }

    // @todo log how long this takes
    $stale_sourceination_connection = self::getSourceination(static::computePrefix($stale_version));

    if (!isset($context['sandbox']['tables'])) {
      $context['sandbox']['tables'] = self::getTables($stale_sourceination_connection);
      $context['sandbox']['total_table_count'] = count($context['sandbox']['tables']);
    }

    $table = reset($context['sandbox']['tables']);
    $stale_sourceination_connection->schema()->dropTable($table);
    array_shift($context['sandbox']['tables']);

    if (empty($context['sandbox']['tables'])) {
      \Drupal::keyValue('acquia_migrate')->delete(static::STALE_CAMOUFLAGE_LINGERING);
      \Drupal::keyValue('acquia_migrate')->delete(static::PARTIAL_CAMOUFLAGE_LINGERING);
    }

    $context['finished'] = static::computeCopyProgress($context);
  }

  /**
   * OP4: Activates the Sourceination that was just created.
   *
   * @param bool $success
   *   TRUE if batch successfully completed.
   * @param array $results
   *   Batch results.
   */
  public static function armDevice(bool $success, array $results) : void {
    // Do not attempt to detect an interruption now, it's too late already. But
    // respect an interruption detected by one of the earlier batch operations.
    if (isset($results['refresh_interrupt_detected'])) {
      return;
    }

    if ($success) {
      // Make the migration system use this copy.
      \Drupal::keyValue('acquia_migrate')->set(static::CAMOUFLAGE_REALISTIC, TRUE);
      // Current version has already been set in ::deployCamouflage().
      \Drupal::state()->set('acquia_migrate_copied_database', [
        'target' => 'default',
        'key' => 'sourceination',
      ]);
      // @see ::isArmed()
      \Drupal::state()->set('migrate.fallback_state_key', 'acquia_migrate_copied_database');
      // Inform MigrationFingerPrinter that it's now safe to recompute.
      \Drupal::state()->set(static::LAST_MACGYVER_EPISODE, date_create('now')->format(DATE_RFC3339));

      $end_time = (int) \Drupal::time()->getRequestTime();
      $start_time = \Drupal::state()->get('acquia_migrate.ah.copy_start');
      \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
        sprintf("database_copy_event=%s|version=%d|duration=%d",
          'finished',
          static::getCurrentVersion(),
          $end_time - $start_time
        )
      );
      \Drupal::state()->delete('acquia_migrate.ah.copy_start');
    }
  }

  /**
   * Constructs the Sourceination connection.
   *
   * @return \Drupal\Core\Database\Connection
   *   A database connection.
   *
   * @see \Drupal\Tests\migrate\Kernel\MigrateTestBase::createMigrationConnection()
   */
  public static function getSourceination(string $prefix = NULL) : Connection {
    // @todo Validate that the prefix does not exist in any existing DB table name.
    if ($prefix === NULL) {
      $prefix = self::computePrefix();
      $connection_key = 'sourceination';
    }
    else {
      $connection_key = sprintf('customsourceination_%s', $prefix);
    }

    $connection_info = Database::getConnectionInfo('default');
    foreach ($connection_info as $target => $value) {
      $connection_info[$target]['prefix']['default'] = $prefix;
    }
    Database::addConnectionInfo($connection_key, 'default', $connection_info['default']);

    return Database::getConnection('default', $connection_key);
  }

  /**
   * Detects whether a MacGyver batch process was interrupted by a refresh.
   *
   * @param int $interrupted_version
   *   The interrupted version of the Sourceination (was under construction).
   * @param array $context
   *   The batch context array.
   *
   * @return bool
   *   TRUE when this batch process was indeed interrupted, FALSE otherwise.
   *
   * @see \Drupal\acquia_migrate\MigrationFingerprinter::recomputeRecommended()
   */
  private static function interruptedbyRefresh(int $interrupted_version, array &$context) : bool {
    if (isset($context['results']['refresh_interrupt_detected']) || (static::detectWhetherActionIsNeeded() && \Drupal::keyValue('acquia_migrate')->has(static::CAMOUFLAGE_REALISTIC))) {
      // Force the in-progress copy to be destroyed.
      \Drupal::keyValue('acquia_migrate')->set(static::PARTIAL_CAMOUFLAGE_LINGERING, $interrupted_version);
      $context['results']['refresh_interrupt_detected'] = TRUE;
      $context['finished'] = 1;
      return TRUE;
    }

    return FALSE;
  }

  /**
   * OP1: Copies the Source into the Destination: the Sourceination.
   *
   * @param int $new_version
   *   The version to use for this Sourcination.
   * @param array $context
   *   The batch context array.
   */
  public static function deployDevice(int $new_version, array &$context) : void {
    if (static::interruptedByRefresh($new_version, $context)) {
      return;
    }

    $source_connection = Database::getConnection('default', 'migrate');
    $sourceination_connection = static::getSourceination(static::computePrefix($new_version));

    if (!isset($context['sandbox']['tables'])) {
      $context['sandbox']['tables'] = self::getTables($source_connection);
      $context['sandbox']['total_table_count'] = count($context['sandbox']['tables']);
    }

    $table = reset($context['sandbox']['tables']);

    // No more tables to copy: we are finished.
    if (!$table) {
      $context['finished'] = 1;
      return;
    }

    if (!isset($context['sandbox'][$table])) {
      // First create the table in the Sourceination, using the same schema as
      // in the source.
      $schema = self::getTableSchema($source_connection, $table);
      try {
        $sourceination_connection->schema()->createTable($table, $schema);
      }
      catch (SchemaObjectExistsException $e) {
        // Attempting to recreate the same table is harmless.
      }

      $source_count = (int) $source_connection->select($table)->countQuery()->execute()->fetchField();
      $sourceination_count = (int) $sourceination_connection->select($table)->countQuery()->execute()->fetchField();

      // Check if this table has already been fully copied.
      if ($source_count === $sourceination_count) {
        // Remove this table from the list of tables to copy.
        array_shift($context['sandbox']['tables']);
        $context['finished'] = static::computeCopyProgress($context);
        return;
      }

      $context['sandbox'][$table]['start_row'] = $sourceination_count;
      $context['sandbox'][$table]['source_count'] = $source_count;
    }

    // Otherwise, resume copying rows into this table.
    $start_row = $context['sandbox'][$table]['start_row'];
    if (self::copyRecords($source_connection, $sourceination_connection, $table, $start_row)) {
      $context['sandbox'][$table]['start_row'] += self::CHUNK_SIZE;
      // If the copying of record succeeded, check if we then finished copying
      // all records in this table.
      if ($start_row + self::CHUNK_SIZE >= $context['sandbox'][$table]['source_count']) {
        array_shift($context['sandbox']['tables']);
      }
      // The per-table sandbox is no longer necessary.
      unset($context['sandbox'][$table]);
    }

    $context['finished'] = static::computeCopyProgress($context);
  }

  /**
   * Computes the table copy progress.
   *
   * @param array $context
   *   The Batch API context.
   *
   * @return float
   *   The progress percentage.
   */
  private static function computeCopyProgress(array $context) : float {
    $total_table_count = $context['sandbox']['total_table_count'];
    // MacGyver is always defensive.
    if ($total_table_count == 0) {
      return 1.0;
    }

    $processed_table_count = $total_table_count - count($context['sandbox']['tables']);
    return $processed_table_count / $total_table_count;
  }

  /**
   * Copies a chunk of records in $table from Source to Sourceination.
   *
   * @param \Drupal\Core\Database\Connection $source_connection
   *   The Source connection.
   * @param \Drupal\Core\Database\Connection $sourceination_connection
   *   The Sourceination connection.
   * @param string $table
   *   The table to copy a chunk of records for.
   * @param int $offset
   *   The offset for copying — all records before it have already been copied.
   *
   * @return bool
   *   FALSE upon any kind of failure; TRUE if successful.
   */
  protected static function copyRecords(Connection $source_connection, Connection $sourceination_connection, string $table, int $offset) {
    // Defensively read from the source.
    try {
      $read_query = $source_connection->query(sprintf('SELECT * FROM {%s} LIMIT %d, %d', $table, $offset, self::CHUNK_SIZE));
      $results = [];
      while (($row = $read_query->fetchAssoc()) !== FALSE) {
        $results[] = $row;
      }
    }
    catch (\Throwable $t) {
      return FALSE;
    }

    if (empty($results)) {
      return TRUE;
    }

    // Defensively write to the Sourceination.
    try {
      $write_query = $sourceination_connection->insert($table);
      $fields_set = FALSE;
      foreach ($results as $result) {
        if (!$fields_set) {
          $write_query->fields(array_keys($result));
          $fields_set = TRUE;
        }
        $write_query->values(array_values($result));
      }
      $write_query->execute();
    }
    catch (\Throwable $t) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Returns a list of tables, not including those set to be excluded.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   *
   * @return string[]
   *   An array of table names.
   *
   * @see \Drupal\Core\Command\DbDumpCommand::getTables()
   */
  protected static function getTables(Connection $connection) : array {
    $tables = array_values($connection->schema()->findTables('%'));

    foreach ($tables as $key => $table) {
      // Remove any explicitly excluded tables.
      foreach (static::EXCLUDED_TABLES as $pattern) {
        if (preg_match('/^' . $pattern . '$/', $table)) {
          unset($tables[$key]);
        }
      }
    }

    sort($tables);

    return $tables;
  }

  /**
   * Returns a schema array for a given table.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param string $table
   *   The table name.
   *
   * @return array
   *   A schema array (as defined by hook_schema()).
   *
   * @todo This implementation is hard-coded for MySQL.
   *
   * @see \Drupal\Core\Command\DbDumpCommand::getTableSchema()
   */
  protected static function getTableSchema(Connection $connection, $table) : array {
    // Check this is MySQL.
    if ($connection->databaseType() !== 'mysql') {
      throw new \RuntimeException('This script can only be used with MySQL database backends.');
    }

    $query = $connection->query("SHOW FULL COLUMNS FROM {" . $table . "}");
    $definition = [];
    while (($row = $query->fetchAssoc()) !== FALSE) {
      $name = $row['Field'];
      // Parse out the field type and meta information.
      preg_match('@([a-z]+)(?:\((\d+)(?:,(\d+))?\))?\s*(unsigned)?@', $row['Type'], $matches);
      $type = self::fieldTypeMap($connection, $matches[1]);
      if ($row['Extra'] === 'auto_increment') {
        // If this is an auto increment, then the type is 'serial'.
        $type = 'serial';
      }
      $definition['fields'][$name] = [
        'type' => $type,
        'not null' => $row['Null'] === 'NO',
      ];
      if ($size = self::fieldSizeMap($connection, $matches[1])) {
        $definition['fields'][$name]['size'] = $size;
      }
      if (isset($matches[2]) && $type === 'numeric') {
        // Add precision and scale.
        $definition['fields'][$name]['precision'] = $matches[2];
        $definition['fields'][$name]['scale'] = $matches[3];
      }
      elseif ($type === 'time') {
        // @todo Core doesn't support these, but copied from `migrate-db.sh` for now.
        // Convert to varchar.
        $definition['fields'][$name]['type'] = 'varchar';
        $definition['fields'][$name]['length'] = '100';
      }
      elseif ($type === 'date') {
        // TRICKY: THIS IS A CHANGE.
        // @see https://www.drupal.org/project/drupal/issues/3210913
        $definition['fields'][$name]['mysql_type'] = 'date';
      }
      elseif ($type === 'timestamp') {
        $definition['fields'][$name]['mysql_type'] = 'timestamp';
      }
      elseif ($type === 'datetime') {
        // Adjust for other database types.
        $definition['fields'][$name]['mysql_type'] = 'datetime';
        $definition['fields'][$name]['pgsql_type'] = 'timestamp without time zone';
        $definition['fields'][$name]['sqlite_type'] = 'varchar';
        $definition['fields'][$name]['sqlsrv_type'] = 'smalldatetime';
      }
      elseif (!isset($definition['fields'][$name]['size'])) {
        // Try use the provided length, if it doesn't exist default to 100. It's
        // not great but good enough for our dumps at this point.
        $definition['fields'][$name]['length'] = isset($matches[2]) ? $matches[2] : 100;
      }

      if (isset($row['Default'])) {
        $definition['fields'][$name]['default'] = $row['Default'];
      }

      if (isset($matches[4])) {
        $definition['fields'][$name]['unsigned'] = TRUE;
      }

      // Check for the 'varchar_ascii' type that should be 'binary'.
      if (isset($row['Collation']) && $row['Collation'] == 'ascii_bin') {
        $definition['fields'][$name]['type'] = 'varchar_ascii';
        $definition['fields'][$name]['binary'] = TRUE;
      }

      // Check for the non-binary 'varchar_ascii'.
      if (isset($row['Collation']) && $row['Collation'] == 'ascii_general_ci') {
        $definition['fields'][$name]['type'] = 'varchar_ascii';
      }

      // Check for the 'utf8_bin' collation.
      if (isset($row['Collation']) && $row['Collation'] == 'utf8_bin') {
        $definition['fields'][$name]['binary'] = TRUE;
      }
    }

    // Set primary key, unique keys, and indexes.
    self::getTableIndexes($connection, $table, $definition);

    // Set table collation.
    self::getTableCollation($connection, $table, $definition);

    return $definition;
  }

  /**
   * Given a database field type, return a Drupal type.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param string $type
   *   The MySQL field type.
   *
   * @return string
   *   The Drupal schema field type. If there is no mapping, the original field
   *   type is returned.
   *
   * @see \Drupal\Core\Command\DbDumpCommand::fieldTypeMap
   */
  protected static function fieldTypeMap(Connection $connection, $type) : string {
    // Convert everything to lowercase.
    $map = array_map('strtolower', $connection->schema()->getFieldTypeMap());
    $map = array_flip($map);

    // The MySql map contains type:size. Remove the size part.
    return isset($map[$type]) ? explode(':', $map[$type])[0] : $type;
  }

  /**
   * Given a database field type, return a Drupal size.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param string $type
   *   The MySQL field type.
   *
   * @return string|null
   *   The Drupal schema field size.
   *
   * @see \Drupal\Core\Command\DbDumpCommand::fieldSizeMap
   */
  protected static function fieldSizeMap(Connection $connection, $type) : ?string {
    // Convert everything to lowercase.
    $map = array_map('strtolower', $connection->schema()->getFieldTypeMap());
    $map = array_flip($map);

    // TRICKY: THIS IS A CHANGE.
    // @see https://www.drupal.org/project/drupal/issues/3210913
    if (!isset($map[$type])) {
      return NULL;
    }

    $schema_type = explode(':', $map[$type])[0];
    // Only specify size on these types.
    if (in_array($schema_type, ['blob', 'float', 'int', 'text'])) {
      // The MySql map contains type:size. Remove the type part.
      return explode(':', $map[$type])[1];
    }

    return NULL;
  }

  /**
   * Adds primary key, unique keys, and index information to the schema.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param string $table
   *   The table to find indexes for.
   * @param array &$definition
   *   The schema definition to modify.
   *
   * @see \Drupal\Core\Command\DbDumpCommand::getTableIndexes()
   */
  protected static function getTableIndexes(Connection $connection, string $table, array &$definition) : void {
    // Note, this query doesn't support ordering, so that is worked around
    // below by keying the array on Seq_in_index.
    $query = $connection->query("SHOW INDEX FROM {" . $table . "}");
    while (($row = $query->fetchAssoc()) !== FALSE) {
      $index_name = $row['Key_name'];
      $column = $row['Column_name'];
      // Key the arrays by the index sequence for proper ordering (start at 0).
      $order = $row['Seq_in_index'] - 1;

      // If specified, add length to the index.
      if ($row['Sub_part']) {
        $column = [$column, $row['Sub_part']];
      }

      if ($index_name === 'PRIMARY') {
        $definition['primary key'][$order] = $column;
      }
      elseif ($row['Non_unique'] == 0) {
        $definition['unique keys'][$index_name][$order] = $column;
      }
      else {
        $definition['indexes'][$index_name][$order] = $column;
      }
    }
  }

  /**
   * Set the table collation.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param string $table
   *   The table to find indexes for.
   * @param array &$definition
   *   The schema definition to modify.
   *
   * @see \Drupal\Core\Command\DbDumpCommand::getTableCollation
   */
  protected static function getTableCollation(Connection $connection, string $table, array &$definition) : void {
    // Remove identifier quotes from the table name. See
    // \Drupal\Core\Database\Driver\mysql\Connection::$identifierQuotes.
    $table = trim($connection->prefixTables('{' . $table . '}'), '"');
    $query = $connection->query("SHOW TABLE STATUS WHERE NAME = :table_name", [':table_name' => $table]);
    $data = $query->fetchAssoc();

    // Map the collation to a character set. For example, 'utf8mb4_general_ci'
    // (MySQL 5) or 'utf8mb4_0900_ai_ci' (MySQL 8) will be mapped to 'utf8mb4'.
    list($charset,) = explode('_', $data['Collation'], 2);

    // Set `mysql_character_set`. This will be ignored by other backends.
    $definition['mysql_character_set'] = $charset;
  }

}
