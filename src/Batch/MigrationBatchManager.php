<?php

namespace Drupal\acquia_migrate\Batch;

use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\Plugin\MigrationPluginManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Batch\BatchStorageInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\migrate_drupal_ui\Batch\MigrateUpgradeImportBatch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Placeholder for managing migration batches.
 *
 * This lets the logic live separate from the HTTP API controller.
 *
 * @todo: Update this docblock when this service if fleshed out.
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
   * A map of action types to callables to use in batch operations.
   *
   * @var array[string]callable
   */
  protected static $actionCallable = [
    'import' => [MigrateUpgradeImportBatch::class, 'run'],
    'rollback' => [MigrateUpgradeRollbackBatch::class, 'run'],
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
   */
  public function __construct(HttpKernelInterface $http_kernel, BatchStorageInterface $batch_storage, $app_root, MigrationRepository $repository, MigrationPluginManager $migration_plugin_manager) {
    $this->httpKernel = $http_kernel;
    $this->batchStorage = $batch_storage;
    $this->appRoot = $app_root;
    $this->repository = $repository;
    $this->migrationPluginManager = $migration_plugin_manager;
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
    $inital_migration_plugin_ids = [];
    $config = [];

    $migrations = $this->repository->getMigrations();
    $all_instances = [];
    foreach ($migrations as $migration) {
      $non_data_migration_plugin_ids = array_diff($migration->getMigrationPluginIds(), $migration->getDataMigrationPluginIds());

      // Also include all dependencies of non-data migration plugins.
      // For example, d7_field_instance:node:blog depends on d7_field:node, and
      // that is *not* a non-data migration plugin, so without this dependency
      // resolution, it would not be picked up.
      $dependencies = [];
      $instances = $migration->getMigrationPluginInstances();
      $all_instances += $instances;
      foreach ($non_data_migration_plugin_ids as $id) {
        $dependencies = array_merge($dependencies, array_diff($instances[$id]->getMigrationDependencies()['required'], $non_data_migration_plugin_ids, $inital_migration_plugin_ids));
      }

      // Also include all dependencies of data migration plugins if they live in
      // a "Shared structure for <entity type>" migration that we depend upon.
      $shared_structure_dependencies = array_filter($migration->getDependenciesWithReasons(), function ($k) {
        return strpos($k, 'Shared structure for ') !== FALSE;
      }, ARRAY_FILTER_USE_KEY);
      $shared_structure_migration_plugin_ids = NestedArray::mergeDeepArray($shared_structure_dependencies);
      foreach ($migration->getDataMigrationPluginIds() as $id) {
        $dependencies = array_merge($dependencies, array_intersect($instances[$id]->getMigrationDependencies()['required'], $shared_structure_migration_plugin_ids));
      }

      $inital_migration_plugin_ids = array_merge($inital_migration_plugin_ids, array_unique($dependencies), $non_data_migration_plugin_ids);
    }

    // We cannot trust that the required migration dependencies are listed in
    // the correct dependency order. Explicitly ensure they're ordered correctly
    // to ensure we can successfully import them.
    $initial_migrations = array_intersect_key($all_instances, array_combine($inital_migration_plugin_ids, $inital_migration_plugin_ids));
    $ordered_initial_migrations = $this->migrationPluginManager->buildDependencyMigration($initial_migrations, []);

    // Determine which migrations would effectively be completely imported
    // because of this initial import.
    $completely_imported = [];
    foreach ($migrations as $migration) {
      if (empty(array_diff($migration->getMigrationPluginIds(), $inital_migration_plugin_ids))) {
        $completely_imported[] = $migration->id();
      }
    }

    // Generate operations array:
    // 1. record the "import start" for all migrations in $completely_imported;
    // 2. import all initial migration plugins;
    // 3. record the "import duration" for all in $completely_imported;
    // 4. calculate completeness for all in $completely_imported.
    $operations = [];
    foreach ($completely_imported as $migration_id) {
      $operations[] = [[__CLASS__, 'recordImportStart'], [$migration_id]];
    }
    $operations[] = [
      static::$actionCallable[static::ACTION_IMPORT],
      [
        array_keys($ordered_initial_migrations),
        $config,
      ],
    ];
    foreach ($completely_imported as $migration_id) {
      $operations[] = [[__CLASS__, 'recordImportDuration'], [$migration_id]];
      $operations[] = [[__CLASS__, 'calculateCompleteness'], [$migration_id]];
    }

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
   *   An action type. Either static::ACTION_IMPORT or static::ACTION_ROLLBACK.
   *
   * @return \Drupal\acquia_migrate\Batch\BatchStatus
   *   A batch ID.
   *
   * @throws \Drupal\acquia_migrate\Batch\BatchConflictException
   *   Thrown if a batch for the given migration is already in flight.
   */
  public function createMigrationBatch(string $migration_id, string $action): BatchStatus {
    // @codingStandardsIgnoreStart
    assert(in_array($action, [static::ACTION_IMPORT, static::ACTION_ROLLBACK, static::ACTION_ROLLBACK_AND_IMPORT], TRUE));
    // @codingStandardsIgnoreEnd
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
    else {
      $operations = [
        [[__CLASS__, 'resetImportMetadata'], [$migration_id]],
        $this->getBatchOperation($migration_id, static::ACTION_ROLLBACK),
      ];
    }
    $operations[] = [[__CLASS__, 'calculateCompleteness'], [$migration_id]];
    $new_batch = [
      'operations' => $operations,
    ];
    batch_set($new_batch);
    batch_process();
    $batch = batch_get();
    return new BatchStatus($batch['id'], 0);
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
    assert($migration_repository instanceof MigrationRepository);
    $migration = $migration_repository->getMigration($migration_id);
    $is_completed = $migration->allRowsProcessed() && $migration->getMessageCount() === 0;
    \Drupal::database()->update('acquia_migrate_migration_flags')
      ->fields(['completed' => (int) $is_completed])
      ->condition('migration_id', $migration->id())
      ->execute();
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
    $arguments = [$this->repository->getMigration($migration_id)->getMigrationPluginIds()];
    if ($action === static::ACTION_IMPORT) {
      $config = [
        'source_base_path' => Settings::get('migrate_source_base_path'),
        'source_private_file_path' => Settings::get('migrate_source_private_file_path'),
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
      $status = Json::decode($response->getContent());
      $progress = floatval($status['percentage']) / 100;
      $progress = min($progress, 0.99);
      return new BatchStatus($batch_id, $progress);
    }
    else {
      $this->httpKernel->handle(Request::create($batch_url->setOption('query', [
        'id' => $batch_id,
        'op' => 'finished',
        '_format' => 'json',
      ])->toString()), HttpKernelInterface::SUB_REQUEST);
      return new BatchStatus($batch_id, 1);
    }
  }

}
