<?php

namespace Drupal\acquia_migrate\Batch;

use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\Plugin\MigrationPluginManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Batch\BatchStorageInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Render\Markup;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\migrate\Event\MigrateEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Placeholder for managing migration batches.
 *
 * This lets the logic live separate from the HTTP API controller.
 *
 * @todo Update this docblock when this service if fleshed out.
 *
 * @internal
 */
final class MigrationBatchManager {

  /**
   * The import action type.
   */
  const ACTION_IMPORT = 'import';

  /**
   * The rollback action type.
   */
  const ACTION_ROLLBACK = 'rollback';

  /**
   * The rollback action type.
   */
  const ACTION_ROLLBACK_AND_IMPORT = 'rollback-and-import';

  /**
   * The refresh action type.
   */
  const ACTION_REFRESH = 'refresh';

  /**
   * A map of action types to callables to use in batch operations.
   *
   * @var array[string]callable
   */
  protected static $actionCallable = [
    'import' => [AcquiaMigrateUpgradeImportBatch::class, 'run'],
    'rollback' => [MigrateUpgradeRollbackBatch::class, 'run'],
    'refresh' => [__CLASS__, 'runRefresh'],
  ];

  /**
   * An HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * A batch storage service.
   *
   * @var \Drupal\Core\Batch\BatchStorageInterface
   */
  protected $batchStorage;

  /**
   * The application root directory name.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $repository;

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\acquia_migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * The migration batch coordinator.
   *
   * @var \Drupal\acquia_migrate\Batch\MigrationBatchCoordinator
   */
  protected $coordinator;

  /**
   * Whether the current request is for a refresh operation.
   *
   * @var bool
   *
   * @see \Drupal\acquia_migrate\Migration::ACTIVITY_REFRESHING
   */
  public static $isRefreshing = FALSE;

  /**
   * MigrationBatchManager constructor.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   An HTTP kernel.
   * @param \Drupal\Core\Batch\BatchStorageInterface $batch_storage
   *   A batch storage service.
   * @param string $app_root
   *   The application root directory name.
   * @param \Drupal\acquia_migrate\MigrationRepository $repository
   *   The migration repository.
   * @param \Drupal\acquia_migrate\Plugin\MigrationPluginManager $migration_plugin_manager
   *   The migration plugin manager.
   * @param \Drupal\acquia_migrate\Batch\MigrationBatchCoordinator $coordinator
   *   The migration batch coordinator.
   */
  public function __construct(HttpKernelInterface $http_kernel, BatchStorageInterface $batch_storage, $app_root, MigrationRepository $repository, MigrationPluginManager $migration_plugin_manager, MigrationBatchCoordinator $coordinator) {
    $this->httpKernel = $http_kernel;
    $this->batchStorage = $batch_storage;
    $this->appRoot = $app_root;
    $this->repository = $repository;
    $this->migrationPluginManager = $migration_plugin_manager;
    $this->coordinator = $coordinator;
  }

  /**
   * Gets a batch ID for the initial import batch.
   *
   * This method should merely create the batch, but not begin processing it.
   *
   * @return \Drupal\acquia_migrate\Batch\BatchStatus
   *   A batch ID.
   *
   * @todo Remove or rewrite this once the "Content structure" screen is built.
   */
  public function createInitialMigrationBatch() : BatchStatus {
    $config = [];

    $migrations = $this->repository->getMigrations();
    $virtual_initial_migration = $this->repository->computeVirtualInitialMigration();
    $ordered_initial_migration_plugin_ids = $virtual_initial_migration->getDataMigrationPluginIds();

    \Drupal::logger('acquia_migrate')->debug('Initial migration plugins (@count) in import order: @initial-migration-plugin-ids', [
      '@count' => count($ordered_initial_migration_plugin_ids),
      '@initial-migration-plugin-ids' => implode(', ', $ordered_initial_migration_plugin_ids),
    ]);

    // Determine which migrations would effectively be completely imported
    // because of this initial import.
    $completely_imported_first_plugin = [];
    $completely_imported_last_plugin = [];
    foreach ($migrations as $migration) {
      $all_plugin_ids_for_migration = $migration->getMigrationPluginIds();
      if (empty(array_diff($all_plugin_ids_for_migration, $ordered_initial_migration_plugin_ids))) {
        $completely_imported_first_plugin[reset($all_plugin_ids_for_migration)] = $migration->id();
        $completely_imported_last_plugin[end($all_plugin_ids_for_migration)] = $migration->id();
      }
    }

    $operations = [];
    $operations[] = [
      [__CLASS__, 'recordImportStart'],
      [$virtual_initial_migration->id()],
    ];
    $operations[] = [[__CLASS__, 'overrideErrorHandler'], []];
    // Import in the specified order, but ensure the import duration is recorded
    // of all migrations that are completely imported.
    foreach ($ordered_initial_migration_plugin_ids as $plugin_id) {
      // Track start of a migration that will be completely imported.
      if (isset($completely_imported_first_plugin[$plugin_id])) {
        $migration_id = $completely_imported_first_plugin[$plugin_id];
        $operations[] = [[__CLASS__, 'recordImportStart'], [$migration_id]];
      }
      // Import a single migration plugin of a particular migration.
      $operations[] = [
        static::$actionCallable[static::ACTION_IMPORT],
        [
          [$plugin_id],
          $config,
        ],
      ];
      // Track duration of a migration that has been completely imported.
      if (isset($completely_imported_last_plugin[$plugin_id])) {
        $migration_id = $completely_imported_last_plugin[$plugin_id];
        $operations[] = [[__CLASS__, 'recordImportDuration'], [$migration_id]];
        $operations[] = [[__CLASS__, 'calculateCompleteness'], [$migration_id]];
      }
    }
    $operations[] = [[__CLASS__, 'recordInitialImportSuccessfulness'], []];
    $operations[] = [[__CLASS__, 'recordOverallProgress'], []];
    $operations[] = [[__CLASS__, 'restoreErrorHandler'], []];

    $new_batch = [
      'operations' => $operations,
    ];
    batch_set($new_batch);
    batch_process();
    $batch = batch_get();
    return new BatchStatus($batch['id'], 0);
  }

  /**
   * Gets a batch ID for a batch that processes the given migration ID.
   *
   * This method should merely create the batch, but not begin processing it.
   *
   * @param string $migration_id
   *   An ID for a migration, as represented in the migration UI. This is not
   *   necessarily a migration plugin ID.
   * @param string $action
   *   An action type. Either static::ACTION_IMPORT, static::ACTION_ROLLBACK,
   *   static::ACTION_ROLLBACK_AND_IMPORT, or static::ACTION_REFRESH.
   *
   * @return \Drupal\acquia_migrate\Batch\BatchStatus
   *   A batch ID.
   *
   * @throws \Drupal\acquia_migrate\Batch\BatchConflictException
   *   Thrown if a batch for the given migration is already in flight.
   */
  public function createMigrationBatch(string $migration_id, string $action): BatchStatus {
    $batch_tasks = $this->createMigrationBatchTasks($migration_id, $action);
    batch_set($batch_tasks);
    batch_process();
    $batch = batch_get();
    return new BatchStatus($batch['id'], 0);
  }

  /**
   * Helper function for createMigrationBatch()
   *
   * @param string $migration_id
   *   An ID for a migration, as represented in the migration UI. This is not
   *   necessarily a migration plugin ID.
   * @param string $action
   *   An action type. Either static::ACTION_IMPORT, static::ACTION_ROLLBACK,
   *   static::ACTION_ROLLBACK_AND_IMPORT, or static::ACTION_REFRESH.
   *
   * @return array
   *   Returns an array of batch tasks.
   */
  public function createMigrationBatchTasks(string $migration_id, string $action): array {
    assert(in_array($action, [
      static::ACTION_IMPORT,
      static::ACTION_ROLLBACK,
      static::ACTION_ROLLBACK_AND_IMPORT,
      static::ACTION_REFRESH,
    ], TRUE));
    if ($action === static::ACTION_ROLLBACK_AND_IMPORT) {
      $operations = [
        [[__CLASS__, 'resetImportMetadata'], [$migration_id]],
        $this->getBatchOperation($migration_id, static::ACTION_ROLLBACK),
        [[__CLASS__, 'recordImportStart'], [$migration_id]],
        $this->getBatchOperation($migration_id, static::ACTION_IMPORT),
        [[__CLASS__, 'recordImportDuration'], [$migration_id]],
      ];
    }
    elseif ($action === static::ACTION_IMPORT) {
      $operations = [
        [[__CLASS__, 'recordImportStart'], [$migration_id]],
        $this->getBatchOperation($migration_id, static::ACTION_IMPORT),
        [[__CLASS__, 'recordImportDuration'], [$migration_id]],
      ];
    }
    elseif ($action === static::ACTION_ROLLBACK) {
      $operations = [
        [[__CLASS__, 'resetImportMetadata'], [$migration_id]],
        $this->getBatchOperation($migration_id, static::ACTION_ROLLBACK),
      ];
    }
    else {
      $operations = [
        [[__CLASS__, 'resetImportMetadata'], [$migration_id]],
        [[__CLASS__, 'recordImportStart'], [$migration_id]],
        $this->getBatchOperation($migration_id, static::ACTION_REFRESH),
        [[__CLASS__, 'recordImportDuration'], [$migration_id]],
      ];
    }
    $operations[] = [[__CLASS__, 'calculateCompleteness'], [$migration_id]];
    $operations[] = [[__CLASS__, 'recordOverallProgress'], []];

    // Silence non-halting errors during migrations.
    array_unshift($operations, [[__CLASS__, 'overrideErrorHandler'], []]);
    array_push($operations, [[__CLASS__, 'restoreErrorHandler'], []]);

    $new_batch = [
      'operations' => $operations,
    ];
    return $new_batch;
  }

  /**
   * Decorates AcquiaMigrateUpgradeImportBatch::run() to make it refresh.
   *
   * @param int[] $initial_ids
   *   The full set of migration plugin IDs to import.
   * @param array $config
   *   An array of additional configuration from the form.
   * @param array $context
   *   The batch context.
   *
   * @see AcquiaMigrateUpgradeImportBatch::run()
   * @see \Drupal\acquia_migrate\MigrationAlterer::addChangeTracking()
   */
  public static function runRefresh(array $initial_ids, array $config, array &$context) {
    // @see patches/core/track_changes_should_not_prevent_efficient_imports.patch
    static::$isRefreshing = TRUE;

    if (empty($context['sandbox']['purge_event_listener_added'])) {
      // The MigrationPurger event subscriber is manually registered here so
      // that is only active on refresh requests. If it were registered via a
      // service definition, it would be active on every import.
      $event_dispatcher = \Drupal::service('event_dispatcher');
      $listener_callable = [new MigrationPurger($event_dispatcher), 'purge'];
      $event_dispatcher->addListener(MigrateEvents::PRE_IMPORT, $listener_callable);
      $context['sandbox']['purge_event_listener_added'] = TRUE;
    }
    // A "refresh" is simply an import + a purge. "What?" you ask, "...but how
    // can an import 'refresh' already imported content?" Excellent question,
    // friend. During every import, the migrate system records the state of each
    // imported row. When an import is run again, the migrate system compares
    // the new state with the old state and updates the destination content with
    // new and modified content. This module does its best to ensure that that
    // change tracking is enabled for every migration plugin by altering every
    // plugin definition.
    $callable = [AcquiaMigrateUpgradeImportBatch::class, 'run'];
    $arguments = [$initial_ids, $config, &$context];
    call_user_func_array($callable, $arguments);
  }

  /**
   * Overrides Drupal's error handler to log non-halting PHP errors.
   *
   * Typically this means PHP notices and warnings are logged in poorly
   * code.
   *
   * Avoids noisy Drupal messages after running a migration with buggy code.
   *
   * @see \Drupal\acquia_migrate\Batch\MigrationBatchManager::logNonHaltingErrorsOrPassthrough()
   */
  public static function overrideErrorHandler() : void {
    set_error_handler(static::class . '::logNonHaltingErrorsOrPassthrough');
  }

  /**
   * Restores Drupal's error handler to log non-halting PHP errors.
   */
  public static function restoreErrorHandler() : void {
    // Restore Drupal's error handler.
    restore_error_handler();
  }

  /**
   * Logs non-halting PHP errors, passes through the rest to Drupal's default.
   *
   * @param int $error_level
   *   The level of the error raised.
   * @param string $message
   *   The error message.
   * @param string $filename
   *   The filename that the error was raised in.
   * @param int $line
   *   The line number the error was raised at.
   */
  public static function logNonHaltingErrorsOrPassthrough($error_level, $message, $filename, $line) : void {
    $backtrace = debug_backtrace();

    $non_halting_error_levels = [
      E_DEPRECATED,
      E_USER_DEPRECATED,
      E_NOTICE,
      E_USER_NOTICE,
      E_STRICT,
      E_WARNING,
      E_USER_WARNING,
      E_CORE_WARNING,
    ];
    if (!in_array($error_level, $non_halting_error_levels, TRUE)) {
      // Pass through to the Drupal error handler.
      _drupal_error_handler($error_level, $message, $filename, $line);
      return;
    }

    static $logger;
    if (!isset($logger)) {
      $logger = \Drupal::logger('acquia_migrate_silenced_broken_code');
    }
    // Most of the code below is copied from _drupal_error_handler_real().
    // @see _drupal_error_handler_real()
    $types = drupal_error_levels();
    [$severity_msg, $severity_level] = $types[$error_level];
    $caller = Error::getLastCaller($backtrace);
    $logger->log($severity_level, 'Silenced %type: @message in %function (line %line of %file) @backtrace_string.', [
      '%type' => $severity_msg,
      // The standard PHP error handler considers that the error messages
      // are HTML. We mimic this behavior here.
      '@message' => Markup::create(Xss::filterAdmin($message)),
      '%function' => $caller['function'],
      '%file' => $caller['file'],
      '%line' => $caller['line'],
      '@backtrace_string' => (new \Exception())->getTraceAsString(),
    ]);
  }

  /**
   * Recalculates completeness after import/rollback.
   *
   * Only marked as completed if all rows were processed and 0 messages exist.
   *
   * @param string $migration_id
   *   A migration ID.
   */
  public static function calculateCompleteness(string $migration_id) {
    $migration_repository = \Drupal::service('acquia_migrate.migration_repository');
    /** @var \Drupal\acquia_migrate\MigrationFingerprinter $migration_fingerprinter */
    $migration_fingerprinter = \Drupal::service('acquia_migrate.migration_fingerprinter');
    assert($migration_repository instanceof MigrationRepository);
    $migration = $migration_repository->getMigration($migration_id);
    $is_completed = $migration->allRowsProcessed() && $migration->getMessageCount() === 0;
    $fingerprint = $migration_fingerprinter->getMigrationFingerprint($migration);
    \Drupal::database()->update('acquia_migrate_migration_flags')
      ->fields([
        'completed' => (int) $is_completed,
        'last_import_fingerprint' => $fingerprint,
        'last_computed_fingerprint' => $fingerprint,
      ])
      ->condition('migration_id', $migration->id())
      ->execute();
  }

  /**
   * Records the overall progress of this site's migration.
   */
  public static function recordOverallProgress(): void {
    $migration_repository = \Drupal::service('acquia_migrate.migration_repository');
    assert($migration_repository instanceof MigrationRepository);
    $migrations = $migration_repository->getMigrations();

    // Aggregate completion tracking.
    $processed_rows = 0;
    $imported_rows = 0;
    $total_rows = 0;
    foreach ($migrations as $migration) {
      $processed_rows += $migration->getUiProcessedCount();
      $imported_rows += $migration->getUiImportedCount();
      $total_rows += $migration->getTotalCount();
    }
    \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
      sprintf("processed_pct_of_total_rows=%.4f",
        $processed_rows / $total_rows
      )
    );
    \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
      sprintf("imported_pct_of_total_rows=%.4f",
        $imported_rows / $total_rows
      )
    );
  }

  /**
   * Records import timestamp, resets last import duration (before importing).
   *
   * @param string $migration_id
   *   A migration ID.
   */
  public static function recordImportStart(string $migration_id) {
    // 1. Store in DB for UI-based analysis.
    \Drupal::database()->update('acquia_migrate_migration_flags')
      ->fields([
        'last_import_timestamp' => (int) \Drupal::time()->getRequestTime(),
        'last_import_duration' => NULL,
      ])
      ->condition('migration_id', $migration_id)
      ->execute();

    // 2. Send to logger for aggregate analysis.
    \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
      sprintf("migration_id=%s|last_import_timestamp=%d",
        $migration_id,
        (int) \Drupal::time()->getRequestTime()
      )
    );
  }

  /**
   * Records the duration of the import (after importing).
   *
   * @param string $migration_id
   *   A migration ID.
   */
  public static function recordImportDuration(string $migration_id) {
    // 1. Store in DB for UI-based analysis.
    \Drupal::database()->update('acquia_migrate_migration_flags')
      ->expression('last_import_duration', ':current_timestamp - last_import_timestamp', [':current_timestamp' => \Drupal::time()->getCurrentTime()])
      ->condition('migration_id', $migration_id)
      ->execute();

    // 2. Send to logger for aggregate analysis.
    $migration = \Drupal::service('acquia_migrate.migration_repository')->getMigration($migration_id);
    $data_migration_plugin_ids = $migration->getDataMigrationPluginIds();
    $primary_data_migration_plugin_id = reset($data_migration_plugin_ids);
    $primary_migration_plugin = $migration->getMigrationPluginInstances()[$primary_data_migration_plugin_id];
    \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
      sprintf("migration_id=%s|duration=%d|count=%d|total=%d|messages=%d|status=%s",
        $migration_id,
        $migration->getLastImportDuration(),
        $migration->getImportedCount(),
        $migration->getTotalCount(),
        $migration->getMessageCount(),
        $primary_migration_plugin->getStatusLabel()
      )
    );

    // Recording the import duration happens at the *end* of a batch process,
    // where GET requests do not trigger
    // \Drupal\acquia_migrate\EventSubscriber\CacheableAcquiaMigrateResponseSubscriber::invalidateAcquiaMigrateResponsesOnMutate()
    // and hence we need to manually invalidate cached responses. The cache tags
    // array is intentionally left empty.
    // @see \Drupal\acquia_migrate\Cache\AcquiaMigrateCacheTagsInvalidator::invalidateTags()
    Cache::invalidateTags([]);
  }

  /**
   * Records the duration of the initial import, and logs debug details.
   */
  public static function recordInitialImportSuccessfulness() : void {
    $repository = \Drupal::service('acquia_migrate.migration_repository');
    assert($repository instanceof MigrationRepository);

    $todo = $repository->getInitialMigrationPluginIdsWithRowsToProcess();

    // 1. Log locally to simplify debugging.
    if (count($todo) === 0) {
      \Drupal::logger('acquia_migrate')->info('Initial import: 100%.');
    }
    else {
      $all = $repository->getInitialMigrationPluginIds();
      \Drupal::logger('acquia_migrate')->warning('Initial import: @percentage%. @remaining-count remain: @remaining-migration-plugin-ids.', [
        '@percentage' => round((count($all) - count($todo)) / count($all)),
        '@remaining-count' => count($todo),
        '@remaining-migration-plugin-ids' => implode(', ', $todo),
      ]);
    }

    // 2. Send to logger for aggregate analysis. Pretend this is just another
    // migration by pretending the migration ID was "_INITIAL_".
    $migration = $repository->computeVirtualInitialMigration();
    $special_fingerprint_processed = sprintf("%d/%d", $migration->getProcessedCount(), $migration->getTotalCount());
    $special_fingerprint_imported = sprintf("%d/%d", $migration->getImportedCount(), $migration->getTotalCount());
    \Drupal::database()->update('acquia_migrate_migration_flags')
      ->fields([
        'completed' => (int) (count($todo) === 0),
        // NOTE: we do not actually store a fingerprint for the virtual
        // "initial" migration, but the processed, imported and total count.
        'last_computed_fingerprint' => $special_fingerprint_processed,
        'last_import_fingerprint' => $special_fingerprint_imported,
      ])
      ->expression('last_import_duration', ':current_timestamp - last_import_timestamp', [':current_timestamp' => \Drupal::time()->getCurrentTime()])
      ->condition('migration_id', $migration->id())
      ->execute();
    \Drupal::service('logger.channel.acquia_migrate_statistics')->info(
      sprintf("migration_id=%s|duration=%d|count=%d|total=%d|messages=%d|status=%s",
        $migration->label(),
        $migration->getLastImportDuration(),
        // NOTE: all other migrations (non-initial) use ::getImportedCount()!
        // @see ::recordImportDuration
        $migration->getProcessedCount(),
        $migration->getTotalCount(),
        $migration->getMessageCount(),
        // @see \Drupal\migrate\Plugin\Migration::$statusLabels
        'Idle'
      )
    );

    // No need for the cache-related shenanigans like in ::recordImportDuration
    // because this is not (currently) displayed in the UI.
  }

  /**
   * Resets last import timestamp and duration (before rolling back).
   *
   * @param string $migration_id
   *   A migration ID.
   */
  public static function resetImportMetadata(string $migration_id) {
    \Drupal::database()->update('acquia_migrate_migration_flags')
      ->fields([
        'last_import_timestamp' => NULL,
        'last_import_duration' => NULL,
      ])
      ->condition('migration_id', $migration_id)
      ->execute();
  }

  /**
   * Get a single operation for a given migration and action.
   *
   * @param string $migration_id
   *   The migration ID.
   * @param string $action
   *   The action that the operation should perform.
   *
   * @return array
   *   A tuple whose first value is a callable and whose second value is an
   *   array of arguments.
   */
  protected function getBatchOperation(string $migration_id, string $action) {
    $arguments = $action === static::ACTION_REFRESH
      ? [$this->repository->getMigration($migration_id)->getDataMigrationPluginIds()]
      : [$this->repository->getMigration($migration_id)->getMigrationPluginIds()];
    if ($action === static::ACTION_IMPORT || $action == static::ACTION_REFRESH) {
      $public_files_path = Settings::get('migrate_source_base_path');
      // Automatically use the weird alternative location for private files on
      // Acquia Cloud.
      // @see https://support.acquia.com/hc/en-us/articles/360005307793-Setting-the-private-file-directory-on-Acquia-Cloud
      $acquia_cloud_weird_alternative_files_path = dirname($public_files_path . '/files-private');
      if (file_exists($acquia_cloud_weird_alternative_files_path)) {
        $private_files_path = $acquia_cloud_weird_alternative_files_path;
      }
      else {
        $private_files_path = Settings::get('migrate_source_private_file_path');
      }
      $config = [
        'source_base_path' => $public_files_path,
        'source_private_file_path' => $private_files_path,
      ];
      array_push($arguments, $config);
    }
    elseif ($action === static::ACTION_ROLLBACK) {
      $arguments[0] = array_reverse($arguments[0]);
    }
    return [static::$actionCallable[$action], $arguments];
  }

  /**
   * Processes the given migration batch ID.
   *
   * If the migration batch is already done, calling this method should have no
   * side-effect . If it is not done, calling this method should cause the batch
   * to be processed using _batch_process before returning. Thus, a batch is
   * processed by repeatedly calling this method.
   *
   * @param int $batch_id
   *   A batch ID.
   *
   * @return \Drupal\acquia_migrate\Batch\BatchStatus|\Drupal\acquia_migrate\Batch\BatchUnknown
   *   A BatchStatus object or a BatchUnknown object if the batch ID is unknown.
   *
   * @throws \Exception
   *   Thrown when an exception occurs during processing.
   */
  public function isMigrationBatchOngoing(int $batch_id): BatchInfo {
    // This session must own the active operation.
    if (!$this->coordinator->hasActiveOperation()) {
      throw new \LogicException('There is no active operation.');
    }
    if (!$this->coordinator->canModifyActiveOperation()) {
      throw new \LogicException('The active operation is not owned by this session.');
    }

    // Load the queried batch from storage, if it exists.
    $batch = $this->batchStorage->load($batch_id);
    if (!$batch) {
      return new BatchUnknown();
    }
    $batch_url = Url::fromRoute('system.batch_page.json');
    if (!($batch['sets'][$batch['current_set']]['success'] ?? FALSE)) {
      $response = $this->httpKernel->handle(Request::create($batch_url->setOption('query', [
        'id' => $batch_id,
        'op' => 'do',
        '_format' => 'json',
      ])->toString()), HttpKernelInterface::SUB_REQUEST);
      if ($response->isServerError()) {
        // @todo create BatchError class?
        return new BatchUnknown();
      }
      $status = Json::decode($response->getContent());
      $progress = floatval($status['percentage']) / 100;
      $progress = min($progress, 0.99);
      return new BatchStatus($batch_id, $progress);
    }
    else {
      $response = $this->httpKernel->handle(Request::create($batch_url->setOption('query', [
        'id' => $batch_id,
        'op' => 'finished',
        '_format' => 'json',
      ])->toString()), HttpKernelInterface::SUB_REQUEST);
      if ($response->isServerError()) {
        // @todo create BatchError class?
        return new BatchUnknown();
      }
      return new BatchStatus($batch_id, 1);
    }
  }

}
