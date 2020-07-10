<?php

namespace Drupal\acquia_migrate\Plugin\migrate\id_map;

use Drupal\acquia_migrate\Controller\HttpApi;
use Drupal\acquia_migrate\MessageAnalyzer;
use Drupal\acquia_migrate\Migration;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * SQL-based map, with centralized message storage to allow sorting & filtering.
 *
 * @internal
 */
final class SqlWithCentralizedMessageStorage extends Sql {

  /**
   * A database table name.
   *
   * @var string
   */
  const CENTRALIZED_MESSAGE_TABLE = 'acquia_migrate_messages';

  /**
   * The column name to store the date and time for a message.
   *
   * @var string
   */
  const COLUMN_DATETIME = 'timestamp';

  /**
   * The column name to store the migration ID for a message.
   *
   * @var string
   */
  const COLUMN_MIGRATION_ID = 'sourceMigration';

  /**
   * The column name to store the migration plugin ID for a message.
   *
   * @var string
   */
  const COLUMN_MIGRATION_PLUGIN_ID = 'sourceMigrationPlugin';

  /**
   * The column name to store the consistent source ID for a message.
   *
   * @var string
   */
  const COLUMN_SOURCE_ID = 'source_id';

  /**
   * The column name to store the message category.
   *
   * @var string
   *   One of:
   *   - \Drupal\acquia_migrate\Controller\HttpApi::MESSAGE_CATEGORY_OTHER
   *   - \Drupal\acquia_migrate\Controller\HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION
   */
  const COLUMN_CATEGORY = 'messageCategory';

  /**
   * The column name to store the RFC5424 severity for a message.
   *
   * @var string
   */
  const COLUMN_SEVERITY = 'severity';

  /**
   * The ID of one of the migrations.
   *
   * @var string
   *
   * @see \Drupal\acquia_migrate\Migration
   * @see \Drupal\acquia_migrate\MigrationRepository
   */
  protected $correspondingMigrationId;

  /**
   * The logger to use.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The migration message analyzer.
   *
   * @var \Drupal\acquia_migrate\MessageAnalyzer
   */
  protected $messageAnalyzer;

  /**
   * Constructs a SqlWithCentralizedMessageStorage object.
   *
   * Sets up the tables and builds the maps,
   *
   * @param array $configuration
   *   The configuration.
   * @param string $plugin_id
   *   The plugin ID for the migration process to do.
   * @param mixed $plugin_definition
   *   The configuration for the plugin.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration to do.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger to use.
   * @param \Drupal\acquia_migrate\MessageAnalyzer $message_analyzer
   *   The migration message analyzer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, EventDispatcherInterface $event_dispatcher, LoggerChannelInterface $logger, MessageAnalyzer $message_analyzer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $event_dispatcher);
    $this->logger = $logger;
    $this->messageAnalyzer = $message_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('event_dispatcher'),
      $container->get('logger.channel.acquia_migrate_message'),
      $container->get('acquia_migrate.message_analyzer')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function ensureTables() {
    parent::ensureTables();

    if (!$this->getDatabase()->schema()->tableExists(static::CENTRALIZED_MESSAGE_TABLE)) {
      $fields = [];
      $fields['timestamp'] = [
        'type' => 'int',
        'not null' => TRUE,
      ];
      $fields[static::COLUMN_MIGRATION_ID] = [
        'type' => 'varchar',
        'length' => '255',
        'not null' => TRUE,
        'description' => 'The migration ID.',
      ];
      $fields[static::COLUMN_MIGRATION_PLUGIN_ID] = [
        'type' => 'varchar',
        'length' => '128',
        'not null' => TRUE,
        'description' => 'The migration plugin ID.',
      ];
      $fields['msgid'] = [
        'type' => 'serial',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ];
      $fields[$this::SOURCE_IDS_HASH] = [
        'type' => 'varchar',
        'length' => '64',
        'not null' => TRUE,
        'description' => 'Hash of source IDs.',
      ];
      $fields[static::COLUMN_SOURCE_ID] = [
        'type' => 'text',
        'size' => 'medium',
        'not null' => TRUE,
        'description' => 'Source ID.',
      ];
      $fields[static::COLUMN_CATEGORY] = [
        'type' => 'varchar',
        'length' => '32',
        'not null' => TRUE,
        'description' => 'One of the migration message categories.',
      ];
      $fields[static::COLUMN_SEVERITY] = [
        'type' => 'int',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ];
      $fields['message'] = [
        'type' => 'text',
        'size' => 'medium',
        'not null' => TRUE,
      ];
      $schema = [
        'description' => 'Messages generated during a migration process',
        'fields' => $fields,
        'primary key' => ['msgid'],
        'indexes' => [
          static::COLUMN_MIGRATION_PLUGIN_ID => [static::COLUMN_MIGRATION_PLUGIN_ID],
        ],
      ];
      $this->getDatabase()->schema()->createTable(static::CENTRALIZED_MESSAGE_TABLE, $schema);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveMessage(array $source_id_values, $message, $level = MigrationInterface::MESSAGE_ERROR) {
    foreach ($this->sourceIdFields() as $field_name => $source_id) {
      // If any key value is not set, we can't save.
      if (!isset($source_id_values[$field_name])) {
        return;
      }
    }

    $log_level = static::messageTypeToRfc5424LogLevel($level);

    // The columns that also exist in the parent implementation.
    $fields['message'] = $message;
    $fields[$this::SOURCE_IDS_HASH] = $this->getSourceIdsHash($source_id_values);

    // The columns we add.
    $fields[static::COLUMN_DATETIME] = \Drupal::time()->getCurrentTime();
    $fields[static::COLUMN_SOURCE_ID] = $this->computeSourceIdValue($source_id_values);
    $fields[static::COLUMN_MIGRATION_ID] = $this->getCorrespondingMigrationId();
    $fields[static::COLUMN_MIGRATION_PLUGIN_ID] = $this->migration->id();
    $fields[static::COLUMN_CATEGORY] = static::determineCategory($message);
    $fields[static::COLUMN_SEVERITY] = $log_level;

    // 1. Store in DB for UI-based analysis.
    $this->getDatabase()->insert(static::CENTRALIZED_MESSAGE_TABLE)
      ->fields($fields)
      ->execute();

    // 2. Send to logger for aggregate analysis.
    $solution = $this->messageAnalyzer->getSolution(
      $fields[static::COLUMN_MIGRATION_PLUGIN_ID],
      $fields['message']
    );
    $this->logger->log(
      $log_level,
      sprintf("source_id=%s|migration_id=%s|migration_plugin_id=%s|category=%s|severity=%s|message=%s|has_solution=%d",
        $fields[static::COLUMN_SOURCE_ID],
        $fields[static::COLUMN_MIGRATION_ID],
        $fields[static::COLUMN_MIGRATION_PLUGIN_ID],
        $fields[static::COLUMN_CATEGORY],
        $fields[static::COLUMN_SEVERITY],
        $fields['message'],
        $solution === NULL ? 0 : 1
      )
    );

    return parent::saveMessage($source_id_values, $message, $level);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $source_id_values, $messages_only = FALSE) {
    parent::delete($source_id_values, $messages_only);

    $message_query = $this->getDatabase()->delete(static::CENTRALIZED_MESSAGE_TABLE);
    $message_query->condition(static::COLUMN_MIGRATION_PLUGIN_ID, $this->migration->id());
    $message_query->condition($this::SOURCE_IDS_HASH, $this->getSourceIdsHash($source_id_values));
    $message_query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDestination(array $destination_id_values) {
    parent::deleteDestination($destination_id_values);

    $source_id_values = $this->lookupSourceId($destination_id_values);
    if (!empty($source_id_values)) {
      $message_query = $this->getDatabase()->delete(static::CENTRALIZED_MESSAGE_TABLE);
      $message_query->condition(static::COLUMN_MIGRATION_PLUGIN_ID, $this->migration->id());
      $message_query->condition($this::SOURCE_IDS_HASH, $this->getSourceIdsHash($source_id_values));
      $message_query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clearMessages() {
    parent::clearMessages();

    $message_query = $this->getDatabase()->delete(static::CENTRALIZED_MESSAGE_TABLE);
    $message_query->condition(static::COLUMN_MIGRATION_PLUGIN_ID, $this->migration->id());
    $message_query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function destroy() {
    parent::destroy();

    $message_query = $this->getDatabase()->delete(static::CENTRALIZED_MESSAGE_TABLE);
    $message_query->condition(static::COLUMN_MIGRATION_PLUGIN_ID, $this->migration->id());
    $message_query->execute();
  }

  /**
   * Computes migration ID corresponding to this IdMap's migration plugin ID.
   *
   * @return string
   *   A migration ID.
   *
   * @see \Drupal\acquia_migrate\MigrationRepository
   */
  private function getCorrespondingMigrationId() : string {
    if (!isset($this->correspondingMigrationId)) {
      $migrations = \Drupal::service('acquia_migrate.migration_repository')->getMigrations();
      $matching_migrations = array_filter($migrations, function (Migration $migration) {
        return in_array($this->migration->id(), $migration->getMigrationPluginIds(), TRUE);
      });

      if (!count($matching_migrations) == 1) {
        throw new \LogicException('Every migration plugin must be part of precisely one migration.');
      }
      $matching_migration = reset($matching_migrations);
      $this->correspondingMigrationId = $matching_migration->id();
    }
    return $this->correspondingMigrationId;
  }

  /**
   * Computes a consistent source ID.
   *
   * @param array $source_id_values
   *   The source identifiers.
   *
   * @return string
   *   A computed consistent source ID, containing all the source identifiers.
   */
  private function computeSourceIdValue(array $source_id_values) : string {
    // @see \Drupal\migrate\Plugin\migrate\id_map\Sql::getMessages()
    $parts = [];
    assert(array_keys($source_id_values) === array_keys($this->migration->getSourcePlugin()->getIds()));
    foreach ($source_id_values as $key => $value) {
      $parts[] = "$key=$value";
    }
    return implode('|', $parts);
  }

  /**
   * Determines the category of a message.
   *
   * @param string $message
   *   The message to analyze.
   *
   * @return string
   *   One of:
   *   - \Drupal\acquia_migrate\Controller\HttpApi::MESSAGE_CATEGORY_OTHER
   *   - \Drupal\acquia_migrate\Controller\HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION
   */
  private static function determineCategory(string $message) {
    return preg_match('/^\[[a-z_]+: /', $message) === 1
      ? HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION
      : HttpApi::MESSAGE_CATEGORY_OTHER;
  }

  /**
   * Converts MigrationInterface::MIGRATION_* to RFC 5424 log level.
   *
   * @param int $migration_message_type
   *   One of:
   *   - \Drupal\migrate\Plugin\MigrationInterface::MESSAGE_ERROR;
   *   - \Drupal\migrate\Plugin\MigrationInterface::MESSAGE_WARNING;
   *   - \Drupal\migrate\Plugin\MigrationInterface::MESSAGE_NOTICE;
   *   - \Drupal\migrate\Plugin\MigrationInterface::MESSAGE_INFORMATIONAL.
   *
   * @return int
   *   One of:
   *   - \Drupal\Core\Logger\RfcLogLevel::ERROR;
   *   - \Drupal\Core\Logger\RfcLogLevel::WARNING;
   *   - \Drupal\Core\Logger\RfcLogLevel::NOTICE;
   *   - \Drupal\Core\Logger\RfcLogLevel::INFO.
   */
  private static function messageTypeToRfc5424LogLevel(int $migration_message_type) : int {
    switch ($migration_message_type) {
      case MigrationInterface::MESSAGE_ERROR:
        return RfcLogLevel::ERROR;

      case MigrationInterface::MESSAGE_WARNING:
        return RfcLogLevel::WARNING;

      case MigrationInterface::MESSAGE_NOTICE:
        return RfcLogLevel::NOTICE;

      case MigrationInterface::MESSAGE_INFORMATIONAL:
        return RfcLogLevel::INFO;

      default:
        throw new \InvalidArgumentException('Migration message has an unofficial type!');
    }
  }

  /**
   * Returns the number of the fully imported items.
   *
   * This counts only the the fully imported items, so the items that needs
   * update are excluded. Items that needs to be updated can be stubs, and even
   * items that's source changed since the last import.
   * \Drupal\migrate\Plugin\migrate\id_map\Sql::importedCount() also includes
   * items that need update.
   *
   * @return int
   *   The number of fully imported ('done') items.
   */
  public function importedCountWithoutNeedsUpdateItems() {
    return $this->countHelper([
      MigrateIdMapInterface::STATUS_IMPORTED,
    ]);
  }

  /**
   * Returns the number of the processed items.
   *
   * If there aren't any fully imported items, and no ignored and failed items,
   * this function assumes that every other 'needs update' item is a stub.
   *
   * @return int
   *   The count of records in the map table, or 0 if only stub rows are found.
   */
  public function processedCountWithoutNeedsUpdateItems() {
    $ignored_failed_count = $this->countHelper(
      [
        MigrateIdMapInterface::STATUS_IGNORED,
        MigrateIdMapInterface::STATUS_FAILED,
      ],
      $this->mapTableName()
    );

    if ($this->importedCountWithoutNeedsUpdateItems() === 0 && $ignored_failed_count === 0) {
      return 0;
    }

    return $this->processedCount();
  }

}
