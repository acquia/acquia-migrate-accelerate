<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate;

use DateInterval;
use DateTime;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\migrate\source\DummyQueryTrait;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * Computes fingerprints for migration source data.
 *
 * A fingerprint is a hash generated from the content of all tables queried by
 * a particular migration. Only MySQL databases are capable of generating
 * fingerprints.
 *
 * @internal
 */
final class MigrationFingerprinter {

  /**
   * Default fingerprint value. Used by this module's hook_schema.
   *
   * @const string
   */
  const FINGERPRINT_NOT_COMPUTED = 'fingerprint_not_computed';

  /**
   * Fingerprint value used when a fingerprint cannot be generated.
   *
   * This is usually because the source database does not support the CHECKSUM
   * TABLE expression.
   *
   * @const string
   */
  const FINGERPRINT_NOT_SUPPORTED = 'fingerprint_not_supported';

  /**
   * Fingerprint value used if an error/exception occurred.
   *
   * @const string
   */
  const FINGERPRINT_FAILED = 'fingerprint_failed';

  /**
   * The maximum interval between recommended recomputes.
   *
   * 10 minutes. Represented as a string compatible with
   * \DateInterval::__construct().
   *
   * @const string
   *
   * @see https://php.net/manual/en/dateinterval.construct.php
   */
  const COMPUTE_MAX_AGE = 'PT10M';

  /**
   * The migration repository service.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $migrationRepository;

  /**
   * The destination database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * MigrationFingerprinter constructor.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $migration_repository
   *   The migration repository service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(MigrationRepository $migration_repository, Connection $database, StateInterface $state, FileSystemInterface $file_system) {
    $this->migrationRepository = $migration_repository;
    $this->database = $database;
    $this->state = $state;
    $this->fileSystem = $file_system;
  }

  /**
   * Computes all migration fingerprints and updates stored values as needed.
   */
  public function compute() {
    $this->state->set('acquia_migrate_last_fingerprint_compute_time', date_create('now')->format(DATE_RFC3339));
    $fingerprints = $this->database->select('acquia_migrate_migration_flags', 't')
      ->fields('t', [
        'migration_id',
        'last_import_fingerprint',
        'last_computed_fingerprint',
      ])
      ->execute()
      ->fetchAllAssoc('migration_id');
    $updates = [];
    foreach ($this->migrationRepository->getMigrations() as $migration) {
      if ($fingerprints[$migration->id()]->last_import_fingerprint === static::FINGERPRINT_NOT_SUPPORTED) {
        continue;
      }
      $fingerprint = $this->getMigrationFingerprint($migration);
      if ($fingerprints[$migration->id()]->last_computed_fingerprint !== $fingerprint) {
        $updates[$migration->id()] = $fingerprint;
      }
    }
    if (!empty($updates)) {
      foreach ($updates as $migration_id => $fingerprint) {
        $this->database->update('acquia_migrate_migration_flags')
          ->fields(['last_computed_fingerprint' => $fingerprint])
          ->condition('migration_id', $migration_id)
          ->execute();
      }
      $this->migrationRepository->getMigrations(TRUE);
    }
  }

  /**
   * Computes a fingerprint for the given migration.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   A migration.
   *
   * @return string
   *   A fingerprint of the data used by the given migration.
   */
  public function getMigrationFingerprint(Migration $migration): string {
    $tables = array_unique(array_reduce(array_map(function (MigrationInterface $instance) {
      $source = $instance->getSourcePlugin();
      if ($source instanceof SqlBase) {
        // Source plugins that use this trait do not implement the query()
        // method in a meaningful way. This is typically because they are using
        // values extracted from one-by-one from the source site's variable
        // table.
        if (in_array(DummyQueryTrait::class, class_uses(get_class($source)) ?: [])) {
          return ['variable'];
        }
        $query = $source->query();
        assert($query instanceof SelectInterface, sprintf('The source plugin class %s has not properly implemented the abstract SqlBase::query() method. Perhaps it needs to use %s?', get_class($source), DummyQueryTrait::class));
        return array_values(array_map(function (array $t) {
          return $t['table'];
        }, $query->getTables()));
      }
      return [];
    }, $migration->getMigrationPluginInstances()), 'array_merge', []));
    return $this->getTablesFingerprint($tables);
  }

  /**
   * Computes a fingerprint for the given tables.
   *
   * @param string[] $tables
   *   A list of database table names.
   *
   * @return string
   *   A fingerprint of the data in the given tables.
   */
  public function getTablesFingerprint(array $tables): string {
    if (!static::isSourceDatabaseSupported()) {
      return static::FINGERPRINT_NOT_SUPPORTED;
    }
    switch (SourceDatabase::getConnection()->databaseType()) {
      case 'mysql':
        return $this->getMySqlTablesFingerprint($tables);

      case 'sqlite':
        return $this->getSqliteTablesFingerprint($tables);

      default:
        return static::FINGERPRINT_FAILED;
    }
  }

  /**
   * Computes a fingerprint for the given tables in a MySQL database.
   *
   * This method uses MySQL's CHECKSUM TABLE feature to compute a hash of the
   * table data.
   *
   * @param string[] $tables
   *   A list of database table names.
   *
   * @return string
   *   A fingerprint of the data in the given tables.
   */
  protected function getMySqlTablesFingerprint(array $tables) {
    $database = SourceDatabase::getConnection();
    // Using sprintf() and $db->query together should raise a red flag, however,
    // per drupal.org documentation, placeholders cannot be used for table
    // names. Therefore, tables are escaped using Connection::escapeTable().
    // While it's unlikely that these names could be changed by a malicious
    // user, we're better safe than sorry!
    // @see https://www.drupal.org/docs/drupal-apis/database-api/static-queries#s-quoting-field-names-and-aliases
    $escaped_tables = array_map(function (string $table) use ($database) {
      // Tables names must be enclosed in curly braces so that Drupal's DB layer
      // is able to prefix them, if necessary.
      return '{' . $database->escapeTable($table) . '}';
    }, $tables);
    $query_string = sprintf('CHECKSUM TABLE %s', implode(', ', $escaped_tables));
    if ($statement = $database->query($query_string, [], ['return' => Database::RETURN_STATEMENT])) {
      $result = $statement->fetchAll(\PDO::FETCH_COLUMN, 1);
      return hash('sha256', implode('.', $result));
    }
    return static::FINGERPRINT_FAILED;
  }

  /**
   * Computes a fingerprint for the given tables in a SQLite database.
   *
   * This method takes advantage of the fact that SQLite stores data in a single
   * file. To do so, it:
   *
   * 1. creates a copy of the source database file.
   * 2. establishes a new connection using that copied database file.
   * 3. DROPs all tables not specified by the $tables argument.
   * 4. executes a VACUUM statement.
   * 5. computes a hash of the copied and pruned database file.
   *
   * The VACUUM statement is necessary to actually remove the DROPed table data
   * from the database file, otherwise it is only marked as "free" but is still
   * present in the file. This would invalidate the fingerprint because it would
   * still be affected by data in those DROPed tables.
   *
   * @param string[] $tables
   *   A list of database table names.
   *
   * @return string
   *   A fingerprint of the data in the given tables.
   */
  protected function getSqliteTablesFingerprint(array $tables) {
    // Copy and create a temporary SQLite DB file.
    $source_db_options = SourceDatabase::getConnection()->getConnectionOptions();
    $db_file = $source_db_options['database'];
    $tmp_file = $this->fileSystem->tempnam('temporary://', 'db.');
    if (!$tmp_file) {
      return static::FINGERPRINT_FAILED;
    }
    $db_copy = $this->fileSystem->realpath($this->fileSystem->copy($db_file, $tmp_file, FileSystemInterface::EXISTS_REPLACE));
    $this->fileSystem->chmod($db_copy, 0660);

    // Establish a connection to the copied database.
    $db_options = array_intersect_key($source_db_options, array_flip([
      'prefix',
      'namespace',
      'driver',
    ]));
    $db_options['database'] = $db_copy;
    Database::addConnectionInfo('fingerprint', 'default', $db_options);
    /* @var \Drupal\Core\Database\Driver\sqlite\Connection $fingerprint_db */
    $fingerprint_db = Database::getConnection('default', 'fingerprint');

    // Iterate over all tables not supposed to be fingerprinted and DROP them.
    $all_tables = $fingerprint_db->select('sqlite_master', 'm')
      ->fields('m', ['name'])
      ->where('m.type = \'table\' AND m.name NOT LIKE \'sqlite_%\'')
      ->execute()
      ->fetchCol();
    $unrecognized_tables = array_diff($all_tables, $tables);
    foreach ($unrecognized_tables as $table) {
      $fingerprint_db->schema()->dropTable($table);
    }

    // VACUUM the database so that the DROPed table data is actually expunged
    // from the underlying SQLite file.
    $fingerprint_db->query("VACUUM");

    // Completely destroy the connection and all references to it. The VACUUM
    // statement does not take effect until this happens.
    Database::closeConnection($fingerprint_db->getTarget(), $fingerprint_db->getKey());
    Database::removeConnection($fingerprint_db->getKey());
    $fingerprint_db->destroy();
    $fingerprint_db = NULL;

    // Hash the SQLite file.
    $fingerprint = hash_file('sha256', $db_copy);

    $this->fileSystem->delete($db_copy);

    return $fingerprint;
  }

  /**
   * Whether the given fingerprints indicate that source data has been modified.
   *
   * @param string $old_fingerprint
   *   The older fingerprint. Usually the fingerprint taken after an import.
   * @param string $new_fingerprint
   *   The newest fingerprint.
   *
   * @return bool
   *   TRUE if the fingerprints differ and the old fingerprint is not a
   *   disqualifying constant, FALSE otherwise. A disqualifying constant could
   *   be the default constant used before an import have ever been run,
   *   therefore, there cannot have been a change since that import.
   */
  public static function detectChange(string $old_fingerprint, string $new_fingerprint): bool {
    return $old_fingerprint !== static::FINGERPRINT_NOT_COMPUTED && $old_fingerprint !== $new_fingerprint;
  }

  /**
   * Whether a stale data check should be recommended to the client.
   *
   * @return bool
   *   TRUE if more than 10 minutes have elapsed since the last check or if
   *   a change has been detected in a canary table, FALSE otherwise.
   */
  public function recomputeRecommended(): bool {
    if (!static::isSourceDatabaseSupported()) {
      return FALSE;
    }
    $compute_max_age = new DateInterval(static::COMPUTE_MAX_AGE);
    $last_compute = DateTime::createFromFormat(DATE_RFC3339, $this->state->get('acquia_migrate_last_fingerprint_compute_time', '2019-03-09T03:01:00-06:00'));
    $expiry = $last_compute->add($compute_max_age);
    if ($expiry < date_create('now')) {
      return TRUE;
    }
    $last_canary_fingerprint = $this->state->get('acquia_migrate_last_canary_fingerprint', MigrationFingerprinter::FINGERPRINT_NOT_COMPUTED);
    // The variable table is checked as a "canary in the coal mine" because it
    // is should change fairly frequently because it stores the last cron run
    // time. If this table has changed, it's a good indicator that a new source
    // database backup is being used.
    $fingerprint = $this->getTablesFingerprint(['variable']);
    if ($fingerprint === static::FINGERPRINT_FAILED) {
      return FALSE;
    }
    $this->state->set('acquia_migrate_last_canary_fingerprint', $fingerprint);
    return MigrationFingerprinter::detectChange($last_canary_fingerprint, $fingerprint);
  }

  /**
   * Detect if this class supports the source database driver.
   *
   * @return bool
   *   TRUE if the database driver is mysql, FALSE otherwise.
   */
  protected static function isSourceDatabaseSupported(): bool {
    return in_array(SourceDatabase::getConnection()->databaseType(), ['mysql', 'sqlite'], TRUE);
  }

}
