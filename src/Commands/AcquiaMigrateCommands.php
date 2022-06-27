<?php

declare(strict_types=1);
declare(ticks = 1);
namespace Drupal\acquia_migrate\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\acquia_migrate\Batch\AcquiaMigrateUpgradeImportBatch;
use Drupal\acquia_migrate\Batch\MigrationBatchCoordinator;
use Drupal\acquia_migrate\Batch\MigrationBatchManager;
use Drupal\acquia_migrate\Controller\HttpApi;
use Drupal\acquia_migrate\EventSubscriber\ServerTimingHeaderForResponseSubscriber;
use Drupal\acquia_migrate\MacGyver;
use Drupal\acquia_migrate\Migration;
use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\Recommendations;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\migrate\Plugin\migrate\destination\NullDestination;
use Drupal\migrate\Plugin\migrate\id_map\NullIdMap;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigratePluginManagerInterface;
use Drupal\migrate\Plugin\MigrateSourceInterface;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Acquia Migrate drush commands.
 */
final class AcquiaMigrateCommands extends DrushCommands {

  const STATUS_OPTIONS_AFTER_IMPORT = [
    'all' => FALSE,
    'verbose' => FALSE,
    'include-needs-review' => FALSE,
    'include-completed' => FALSE,
    'include-skipped' => FALSE,
  ];

  /**
   * The finished operations.
   *
   * Contains a list of arrays with two columns:
   * - a migration ID
   * - a status, usually an emoji such as ✅ or 🏁
   *
   * @var array[]
   */
  private $finishedOperations = [];

  /**
   * Whether the current (long-running) AMA command has been interrupted.
   *
   * @var bool
   */
  private $interrupted = FALSE;

  /**
   * Finished section.
   *
   * @var \Symfony\Component\Console\Output\ConsoleSectionOutput
   */
  protected $finishedSection;

  /**
   * Holds the context for operations.
   *
   * @var array
   */
  protected $context;

  /**
   * Migration labels.
   *
   * @var array
   */
  protected $migrationLabels = [];

  /**
   * Holds ProgressBar for overall Migration.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  protected $totalProgress;

  /**
   * Holds ProgressBar for Data Migration.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  protected $dataProgress;

  /**
   * Holds ProgressBar for Current migration plugin.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  protected $migrationPluginProgress;

  /**
   * Holds ProgressBar for Configuration Migration.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  protected $configProgress;

  /**
   * Holds ProgressBar for Migration Messages count.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  protected $messageCountProgress;

  /**
   * Holds the current migration.
   *
   * @var \Drupal\acquia_migrate\Migration
   */
  protected $currentMigration;

  /**
   * Holds the current ongoing migration.
   *
   * @var string
   */
  protected $currentPluginID;

  /**
   * The migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $migrationRepository;

  /**
   * A migration batch manager.
   *
   * @var \Drupal\acquia_migrate\Batch\MigrationBatchManager
   */
  protected $migrationBatchManager;

  /**
   * Key-value store service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The Acquia Migrate Accelerate recommendations.
   *
   * @var \Drupal\acquia_migrate\Recommendations
   */
  protected $recommendations;

  /**
   * The migration source plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigratePluginManagerInterface
   */
  protected $sourcePluginManager;

  /**
   * The migration batch coordinator.
   *
   * @var \Drupal\acquia_migrate\Batch\MigrationBatchCoordinator
   */
  protected $coordinator;

  /**
   * AcquiaMigrateCommands constructor.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $migration_repository
   *   The migration repository.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value
   *   Key-value store service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\acquia_migrate\Recommendations $recommendations
   *   The Acquia Migrate Accelerate recommendations.
   * @param \Drupal\migrate\Plugin\MigratePluginManagerInterface $source_plugin_manager
   *   The migration source plugin manager.
   * @param \Drupal\acquia_migrate\Batch\MigrationBatchManager $migration_batch_manager
   *   The migration batch manager.
   * @param \Drupal\acquia_migrate\Batch\MigrationBatchCoordinator $coordinator
   *   The migration batch coordinator.
   */
  public function __construct(MigrationRepository $migration_repository, KeyValueFactoryInterface $key_value, ModuleExtensionList $module_extension_list, Recommendations $recommendations, MigratePluginManagerInterface $source_plugin_manager, MigrationBatchManager $migration_batch_manager, MigrationBatchCoordinator $coordinator) {
    parent::__construct();
    $this->migrationRepository = $migration_repository;
    $this->keyValue = $key_value;
    $this->moduleExtensionList = $module_extension_list;
    $this->recommendations = $recommendations;
    $this->sourcePluginManager = $source_plugin_manager;
    $this->migrationBatchManager = $migration_batch_manager;
    $this->coordinator = $coordinator;
    if (MacGyver::isArmed()) {
      MacGyver::getSourceination();
    }
  }

  /**
   * Audits installed modules.
   *
   * @command ama:module-audit
   * @filter-output
   *
   * @option installed Installed modules only. Default.
   * @option risky
   *
   * @default $options []
   *
   * @usage ama:module-audit
   *   Audits all installed modules.
   * @usage ama:module-audit --risky-only
   *   Returns only the risky modules.
   * @usage ama:module-audit --filter="risk!="
   *   Alias of --risky-only
   * @usage ama:module-audit --filter="risk~=/medium|high/"
   *   Returns only medium and high risk modules.
   * @usage ama:module-audit --installed=0
   *   Returns only modules that are not installed.
   * @usage ama:module-audit --filter="installed!="
   *   Alias of --installed=0.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amama, ama-module-audit
   *
   * @field-labels
   *   module: Module
   *   vetted: Vetted
   *   stable: Stable
   *   has_migrations: Has Migrations
   *   alters_migrations: Alters Migrations
   *   risk: Risk
   *   installed: Installed
   *   installation_time: Installation time
   * @default-fields module,vetted,stable,has_migrations,alters_migrations,risk,installed,installation_time
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations status formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function auditModules($options = [
    'installed' => TRUE,
    'risky' => self::OPT,
  ]) : RowsOfFields {
    $table = [];

    $vetted = $this->recommendations->getVettedDestinationModules();

    foreach ($this->moduleExtensionList->getList() as $module) {
      // Always ignore test-only modules.
      if ($module->info['package'] === 'Testing') {
        continue;
      }

      $installed = $module->status ? 'yes' : FALSE;
      if ($options['installed'] && !$installed) {
        continue;
      }

      // This assumes the recommended version of the vetted module is used.
      $is_vetted = in_array($module->getName(), $vetted);
      if (!$is_vetted) {
        // Perhaps this is a dependency of a vetted dependent. For that
        // dependent to have been vetted, this must have been vetted too.
        $is_vetted = !empty(array_intersect(array_keys($module->required_by), $vetted));
      }
      $has_migrations = $this->recommendations::moduleHasMigrations($module);
      $alters_migrations = $this->recommendations::moduleAltersMigrations($module);
      $is_stable = $this->recommendations::moduleIsStable($module);
      $risk = $this->recommendations::assessModuleInstallationMigrationBreakingRisk($is_vetted, $has_migrations, $alters_migrations, $is_stable);

      if ($options['risky'] && $risk === NULL) {
        continue;
      }

      $key = "module_install_timestamp:" . $module->getName();
      $installation_time = $this->keyValue->get('acquia_migrate')->get($key, NULL);
      $row = [
        'module' => $module->getName(),
        'installed' => $installed,
        'vetted' => $is_vetted ? 'yes' : FALSE,
        'stable' => $is_stable ? 'yes' : FALSE,
        'has_migrations' => $has_migrations ? 'yes' : FALSE,
        'alters_migrations' => $alters_migrations ? 'yes' : FALSE,
        'risk' => $risk,
        'installation_time' => $installation_time === NULL ? FALSE : (new \DateTime())->setTimestamp($installation_time)->format(\DateTime::RFC3339),
      ];

      $table[] = $row;
    }
    return new RowsOfFields($table);
  }

  /**
   * Status of Acquia Migrate Accelerate. ASCII view of the dashboard + details.
   *
   * @param string|null $migration_label_or_id
   *   (optional) A migration label or ID.
   * @param array $options
   *   The options to pass.
   *
   * @command ama:status
   * @filter-output
   *
   * @option include-needs-review Includes migrations on "needs review" UI tab.
   * @option include-completed Includes migrations on "completed" UI tab.
   * @option include-skipped Includes migrations on "skipped" UI tab.
   * @option all Includes ALL migrations, regardless of UI tab.
   *
   * @usage ama:status
   *   Shows the same dashboard customers see, but with more detail.
   * @usage ama:status "User accounts"
   *   Shows only the "User accounts" migration, but with more detail.
   * @usage ama:status "User accounts" --verbose
   *   Same, but now with details about the underlying migration plugins.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amas
   *
   * @field-labels
   *   migration: Migration
   *   tab: UI tab
   *   processed_count: Proc #
   *   imported_count: Imp #
   *   total_count: Tot #
   *   processed_pct: Proc %
   *   imported_pct: Imp %
   *   message_count: Messages
   *   validation_message_count: M (validation)
   *   other_message_count: M (other)
   *   activity: Activity
   * @default-fields migration,tab,processed_count,imported_count,total_count,processed_pct,imported_pct,message_count,validation_message_count,other_message_count,activity
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations' remaining rows formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function status(string $migration_label_or_id = NULL, array $options = [
    'include-needs-review' => FALSE,
    'include-completed' => FALSE,
    'include-skipped' => FALSE,
    'all' => FALSE,
  ]) {
    // The --all option automatically sets the --include-* options.
    if ($options['all']) {
      $options['include-needs-review'] = TRUE;
      $options['include-completed'] = TRUE;
      $options['include-skipped'] = TRUE;
    }

    $table = [];

    if ($migration_label_or_id !== NULL) {
      $migration_id = Migration::isValidMigrationId($migration_label_or_id)
        ? $migration_label_or_id
        : Migration::generateIdFromLabel($migration_label_or_id);

      $migration = $this->migrationRepository->getMigration($migration_id);
      assert($migration instanceof Migration);
      $migrations = [$migration_id => $migration];
    }
    else {
      $migrations = $this->migrationRepository->getMigrations();
      assert(Inspector::assertAllObjects($migrations, Migration::class));
    }

    foreach ($migrations as $migration) {
      if (!$options['include-completed'] && $migration->isCompleted() && $migration_label_or_id === NULL) {
        continue;
      }
      if (!$options['include-skipped'] && $migration->isSkipped() && $migration_label_or_id === NULL) {
        continue;
      }

      $tab = 'in-progress';
      if ($migration->isCompleted()) {
        $tab = 'completed';
      }
      elseif ($migration->isSkipped()) {
        $tab = 'skipped';
      }
      elseif ($migration->getUiProcessedCount() === $migration->getTotalCount() && $migration->getMessageCount()) {
        $tab = 'needs-review';
      }

      if (!$options['include-needs-review'] && $tab === 'needs-review' && $migration_label_or_id === NULL) {
        continue;
      }

      $processed_count = $migration->getUiProcessedCount();
      $message_count = $migration->getMessageCount();
      // @codingStandardsIgnoreStart
      $table[] = [
        'migration' => $migration->label(),
        'tab' => $tab,
        'processed_count' => sprintf("%6d", $processed_count),
        'imported_count' => $processed_count > 0 ? sprintf("%6d", $migration->getUiImportedCount()) : NULL,
        'total_count' => sprintf("%6d", $migration->getTotalCount()),
        'processed_pct' => sprintf("%3d%%", static::getPercentage($migration->getUiProcessedCount(), $migration->getTotalCount())),
        'imported_pct' => $processed_count > 0
          ? sprintf("%3d%%", static::getPercentage($migration->getUiImportedCount(), $migration->getTotalCount()))
          : NULL,
        'message_count' => sprintf("%6d", $message_count),
        'validation_message_count' => $message_count > 0
          ? sprintf("%6d", $migration->getMessageCount(HttpApi::MESSAGE_CATEGORY_ENTITY_VALIDATION))
          : NULL,
        'other_message_count' => $migration->getMessageCount() > 0
          ? sprintf("%6d", $migration->getMessageCount(HttpApi::MESSAGE_CATEGORY_OTHER))
          : NULL,
        'activity' => $migration->getActivity(),
        'completed' => $migration->isCompleted(),
      ];
      // @codingStandardsIgnoreEnd

      if (!$options['verbose']) {
        continue;
      }

      foreach ($migration->getMigrationPluginInstances() as $id => $migration_plugin) {
        if (!in_array($id, $migration->getDataMigrationPluginIds())) {
          continue;
        }
        $processed_count = $migration_plugin->getIdMap()->processedCountWithoutNeedsUpdateItems();
        $imported_count = $migration_plugin->getIdMap()->importedCountWithoutNeedsUpdateItems();
        $total_count = $migration_plugin->getSourcePlugin()->count();
        // @codingStandardsIgnoreStart
        $table[] = [
          'migration' => "    $id",
          'processed_count' => sprintf("%6d", $processed_count),
          'imported_count' => $processed_count > 0
            ? sprintf("%6d", $imported_count)
            : NULL,
          'total_count' => sprintf("%6d", $total_count),
          'processed_pct' => sprintf("%3d%%", static::getPercentage($processed_count, $total_count)),
          'imported_pct' => $processed_count > 0
            ? sprintf("%3d%%", static::getPercentage($imported_count, $total_count))
            : NULL,
          'activity' => $migration->getActivity() === Migration::ACTIVITY_IDLE
            ? NULL
            : strtolower($migration_plugin->getStatusLabel()),
        ];
        // @codingStandardsIgnoreEnd
      }
    }

    return new RowsOfFields($table);
  }

  /**
   * Gets a percentage without decimals, that rounds down instead of up.
   *
   * For example, 99/100 yields 99, but 199/200 and 16497/16499 still yield 99.
   *
   * Also handles the case of a zero denominator: returns 100.
   *
   * @param int $numerator
   *   The numerator.
   * @param int $denominator
   *   The denominator.
   *
   * @return int
   *   An integer between 0 and 100.
   */
  private static function getPercentage(int $numerator, int $denominator) : int {
    if ($denominator === 0) {
      return 100;
    }
    return (int) floor($numerator / $denominator * 100);
  }

  /**
   * Lists remaining rows.
   *
   * @param string $migration_label_or_id
   *   A migration label or ID.
   * @param string|null $data_migration_plugin_id
   *   (optional) A data migration plugin ID (of of the data migration plugins
   *   in this migration).
   * @param array $options
   *   The options to pass.
   *
   * @command ama:remaining-rows
   * @filter-output
   *
   * @option unprocessed-only
   * @option processed-only
   *
   * @default $options []
   *
   * @usage ama:remaining-rows
   *   Audits all remaining source rows in a migration.
   * @usage ama:module-audit --unprocessed-only
   *   Returns only the unprocessed source rows (below the high water property).
   * @usage ama:module-audit --processed-only
   *   Returns only the processed but unimported source rows.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amarr, ama-remaining-rows
   *
   * @field-labels
   *   migration_plugin_id: Migration Plugin ID
   *   source_id: Source ID
   *   assessment: Assessment
   *   belowhighwater: Below High Water
   * @default-fields migration_plugin_id,source_id,assessment,belowhighwater
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations' remaining rows formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function remainingRows(string $migration_label_or_id, string $data_migration_plugin_id = NULL, array $options = [
    'unprocessed-only' => self::OPT,
    'processed-only' => self::OPT,
  ]) {
    if ($options['processed-only'] && $options['unprocessed-only']) {
      throw new \Exception('Cannot use both --processed-only and --processed-only. Use either or neither, not both.');
    }

    $migration_id = Migration::isValidMigrationId($migration_label_or_id)
      ? $migration_label_or_id
      : Migration::generateIdFromLabel($migration_label_or_id);

    $migration = $this->migrationRepository->getMigration($migration_id);
    assert($migration instanceof Migration);
    $data_migration_plugin_ids = $migration->getDataMigrationPluginIds();
    if ($data_migration_plugin_id !== NULL) {
      if (!in_array($data_migration_plugin_id, $data_migration_plugin_ids)) {
        throw new \Exception(sprintf("Specified data migration plugin '%s' is not one of the data migration plugins in this migration.", $data_migration_plugin_id));
      }
      $data_migration_plugin_ids = [$data_migration_plugin_id];
    }
    $this->say(dt('Analyzing @count data migration plugins for remaining rows. Unprocessed rows require a complete scan, to cross-reference the complete set of source rows against the migrate ID mapping.', ['@count' => count($data_migration_plugin_ids)]));

    $table = [];

    $index = 0;
    foreach ($data_migration_plugin_ids as $migration_plugin_id) {
      $index++;
      $migration_plugin = $migration->getMigrationPluginInstances()[$migration_plugin_id];
      $definition = $migration_plugin->getPluginDefinition();

      // High water metadata.
      $source_configuration = $definition['source'];
      $high_water_property = array_key_exists('high_water_property', $source_configuration)
        ? $source_configuration['high_water_property']['name']
        : NULL;
      $high_water_mark = $this->getHighWater($migration_plugin_id);

      $source_plugin = $this->getAnalysisOnlySourcePluginFor($migration_plugin);

      try {
        $map = $migration_plugin->getIdMap();
        assert($map instanceof MigrateIdMapInterface);
        $imported_count = $map->importedCount();
      }
      catch (\Exception $e) {
        $error = dt(
          'Failure retrieving information on @migration: @message',
          ['@migration' => $migration_id, '@message' => $e->getMessage()]
        );
        $this->logger()->error($error);
        continue;
      }

      try {
        $total_count = $source_plugin->count();
        // -1 indicates uncountable sources.
        if ($total_count != -1) {
          $unprocessed_count = $total_count - $map->processedCount();
        }
        else {
          $unprocessed_count = NULL;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error(
          dt(
            'Could not retrieve source count from @migration: @message',
            ['@migration' => $migration_id, '@message' => $e->getMessage()]
          )
        );
        continue;
      }

      // Gather the set of processed but unimported rows. This is efficient.
      if (!$options['unprocessed-only'] && ($total_count == -1 || $imported_count < $total_count - $unprocessed_count)) {
        $processed_remaining_rows[$migration_plugin_id] = $map->getUnimportedRows();
        foreach ($processed_remaining_rows[$migration_plugin_id] as $row) {
          $table[] = [
            'migration_plugin_id' => $migration_plugin_id,
            'source_id' => $row->sourceid1,
            'assessment' => static::assessProcessedRow($row),
            'belowhighwater' => NULL,
          ];
        }
      }

      if ($options['processed-only']) {
        continue;
      }

      if ($unprocessed_count === $total_count) {
        $this->say(dt('[@index/@total] %migration-plugin-id has no processed rows, no need to scan.', [
          '@index' => $index,
          '@total' => count($data_migration_plugin_ids),
          '%migration-plugin-id' => $migration_plugin_id,
        ]));
      }
      elseif ($unprocessed_count === 0) {
        $this->say(dt('[@index/@total] %migration-plugin-id has no unprocessed rows, no need to scan.', [
          '@index' => $index,
          '@total' => count($data_migration_plugin_ids),
          '%migration-plugin-id' => $migration_plugin_id,
        ]));
      }
      // Gather the set of unprocessed rows. This is inefficient. (It is
      // inefficient because it requires looking up data in both the source DB
      // and the destination DB, which usually cannot be joined.)
      else {
        $unprocessed_remaining_rows = [];
        try {
          $this->say(dt('[@index/@total] %migration-plugin-id has @count unprocessed rows, scanning…', [
            '@count' => $unprocessed_count,
            '@index' => $index,
            '@total' => count($data_migration_plugin_ids),
            '%migration-plugin-id' => $migration_plugin_id,
          ]));
          $this->io()->progressStart($total_count);
          $source_plugin->rewind();
          while ($source_plugin->valid()) {
            $this->io()->progressAdvance();
            $row = $source_plugin->current();
            assert($row instanceof Row);
            $ids = $row->getSourceIdValues();
            if (static::isUnprocessedSourceRow($map, $ids)) {
              $unprocessed_remaining_rows[] = $row;
              $table[] = [
                'migration_plugin_id' => $migration_plugin_id,
                'source_id' => implode(':', $ids),
                'assessment' => 'unprocessed',
                // @see \Drupal\migrate\Plugin\migrate\source\SqlBase::initializeIterator
                'belowhighwater' => $high_water_mark !== NULL && (int) $row->getSourceProperty($high_water_property) <= $high_water_mark,
              ];
            }
            $source_plugin->next();
          }
          $this->io()->progressFinish();
        }
        catch (\Throwable $e) {
          $this->logger()->error(
            dt(
              'Failed while scanning for unprocessed rows in @migration: @message',
              ['@migration' => $migration_id, '@message' => $e->getMessage()]
            )
          );
          continue;
        }

        if (count($unprocessed_remaining_rows) === $unprocessed_count) {
          $this->logger()
            ->success(dt('Scan complete, found !unprocessed-count-found of !unprocessed-count-expected unprocessed rows.', [
              '!unprocessed-count-found' => count($unprocessed_remaining_rows),
              '!unprocessed-count-expected' => $unprocessed_count,
            ]) . "\n\n");
        }
        else {
          $this->logger()
            ->error(dt('Scan complete, found !unprocessed-count-found of !unprocessed-count-expected unprocessed rows.', [
              '!unprocessed-count-found' => count($unprocessed_remaining_rows),
              '!unprocessed-count-expected' => $unprocessed_count,
            ]) . "\n\n");
        }
        $this->say(dt('ℹ️  %migration-plugin-id @does-or-not use high_water_property. It is currently at @current-high-water-property.', [
          '%migration-plugin-id' => $migration_plugin_id,
          '@does-or-not' => $migration_plugin->getSourceConfiguration('high_water_property') ? 'DOES' : 'does NOT',
          '@current-high-water-property' => static::getHighWater($migration_plugin_id),
        ]));
      }
    }
    $this->writeln("\n\n");
    return new RowsOfFields($table);
  }

  /**
   * Import migrations in Acquia Migrate Accelerate.
   *
   * @param string|null $migrations
   *   (optional) A migration or comma seperated migrations.
   * @param array $options
   *   The options to pass.
   *
   * @command ama:import
   *
   * @option all Import all migrations.
   * @option i Make dependencies migration interactive.
   * dependencies.
   *
   * @usage ama:import --all
   *   Import all migrations.
   * @usage ama:import migration_label
   *   Import migration_label migration.
   * @usage ama:import migration_label1,migration_label2
   *   Import migration_label1 and migration_label2 migrations
   *   (given list of migrations).
   * @usage ama:import migration_label --i
   *   Interactive migration with optional dependency migration.
   * @usage ama:import migration_label1,migration_label2 --i
   *  Interactive migration with optional dependency migration.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @field-labels
   *   migration: Migration
   *   tab: UI tab
   *   processed_count: Proc #
   *   imported_count: Imp #
   *   total_count: Tot #
   *   processed_pct: Proc %
   *   imported_pct: Imp %
   *   message_count: Messages
   *   validation_message_count: M (validation)
   *   other_message_count: M (other)
   *   activity: Activity
   * @default-fields migration,tab,processed_count,imported_count,total_count,processed_pct,imported_pct,message_count,validation_message_count,other_message_count,activity
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations' status after importing, formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *
   * @aliases amai
   */
  public function import(string $migrations = NULL, array $options = [
    'all' => FALSE,
    'i' => FALSE,
  ]) {
    if (!$this->migrationRepository->migrationsHaveBeenPreselected()) {
      $this->output()->writeln('⛔️ Please use the UI first to select which data to migrate: go to /acquia-migrate-accelerate/start-page.');
      exit(1);
    }
    if (!empty($this->migrationRepository->getInitialMigrationPluginIdsWithRowsToProcess())) {
      $this->output()->writeln("⚠️️  If you want to be able to inspect the migrated data at arbitrary points in time, it is strongly recommended to first let the initial import finish, by visiting /acquia-migrate-accelerate/start-page — it will start automatically there.\n");
    }
    // Set the memory limit to 1 GB if it isn't already higher.
    $minimum_memory_limit_mb = 1024;
    $current_memory_limit = static::getBytes(ini_get('memory_limit'));
    if ($current_memory_limit < ($minimum_memory_limit_mb * 1024 * 1024)) {
      // Computing all derivatives is very resource-intensive. For complex
      // source sites, the number of derivatives to generate can be very high.
      // Increase the memory limit for the remainder of this request.
      ini_set('memory_limit', $minimum_memory_limit_mb . 'M');
    }

    pcntl_signal(SIGINT, [&$this, 'signal']);

    // Stuck migrations can only be determined *before* we start the operation!
    $stuck_migrations = $this->getStuckMigrations();

    // Attempt to start an operation.
    if ($this->coordinator->hasActiveOperation() || !$this->coordinator->startOperation()) {
      // Inform user that somebody else is already executing a migration,
      // and stop.
      $this->output()->writeln('⛔️ A migration operation is already active. Concurrent migration operations are not supported by the Drupal migration system.');
      return NULL;
    }
    else {
      AcquiaMigrateUpgradeImportBatch::$lastUpdationTime = microtime(TRUE);
      $all_migrations = array_keys($this->migrationRepository->getMigrations());

      if (!empty($stuck_migrations)) {
        $stuck_migration_labels = array_map(function (Migration $m) {
          return $m->label();
        }, $stuck_migrations);
        // When not interactive, always unstick.
        if (!$options['i']) {
          $this->unstickMigrations($stuck_migrations);
          $this->output()->write("ℹ️  The following migrations were unstuck: ");
          $this->output()->write(implode(', ', $stuck_migration_labels));
          $this->output()->writeln('.');
        }
        else {
          $this->output()->write("⚠️️  The following migrations are stuck: ");
          $this->output()->write(implode(', ', $stuck_migration_labels));
          $this->output()->writeln('.');
          $this->output()->writeln("Do you want to unstick these? Yes or No?");
          $input = readline("\n");
          $this->output()->writeln('');
          if (str_contains($input, 'y') || str_contains($input, 'Y')) {
            $this->unstickMigrations($stuck_migrations);
          }
        }
      }

      // Naked command ama:import.
      if ($migrations === NULL && $options['all'] === FALSE) {
        $this->output()
          ->writeln('Please provide migration label/labels from below or use the --all option.');
        $this->coordinator->stopOperation();
        return $this->status(NULL, static::STATUS_OPTIONS_AFTER_IMPORT);
      }

      switch ($options['all']) {
        case TRUE:
          // When all flag is set, migrate everything.
          $all_migration_labels = [];
          foreach ($all_migrations as $migration) {
            $all_migration_labels[] = Migration::labelForId($migration);
          }
          $remaining_migrations = $this->excludeCompletedMigrations($all_migration_labels);
          if (empty($remaining_migrations)) {
            $this->output()->writeln('All migrations are already migrated.');
            $this->coordinator->stopOperation();
            return NULL;
          }
          if (count($remaining_migrations) < count($all_migration_labels)) {
            $this->output->writeln(sprintf(
              "ℹ️  %d of the %d migrations already processed, now starting the remaining %d.\n",
              count($all_migration_labels) - count($remaining_migrations),
              count($all_migration_labels),
              count($remaining_migrations)
            ));
          }
          $this->executeMigrations($all_migration_labels);
          return $this->statusAfterImport($all_migration_labels);

        case FALSE:
          // When provided with multiple or a single migration label.
          $migration_labels = array_map('trim', explode(',', $migrations));
          $invalid_labels = $this->areInvalidLabels($migration_labels, $all_migrations);
          // If any of the labels are invalid.
          if (!empty($invalid_labels)) {
            $this->printInvalidLabels($invalid_labels);
            $this->coordinator->stopOperation();
            return $this->status(NULL, static::STATUS_OPTIONS_AFTER_IMPORT);
          }
          // Remove already executed migrations.
          $migration_labels_remaining = $this->excludeCompletedMigrations($migration_labels);
          if (empty($migration_labels_remaining)) {
            if (count($migration_labels) === 1) {
              $this->output()->writeln('Label is already migrated.');
            }
            else {
              $this->output()->writeln('Labels are already migrated.');
            }
            $this->coordinator->stopOperation();
            return NULL;
          }
          // Calculate migration dependencies.
          foreach ($migration_labels as $migration_label) {
            $check_unmet_dependencies[] = Migration::generateIdFromLabel($migration_label);
          }
          $migration_dependencies = $this->getUnmetDependencies($check_unmet_dependencies);
          switch ($options['i']) {
            case TRUE:
              // If there are no migration dependencies.
              if (empty($migration_dependencies)) {
                $this->executeMigrations($migration_labels);
                return $this->statusAfterImport($migration_labels);
              }
              $this->output()->write("Do you want to execute ");
              for ($i = 0; $i < count($migration_labels) - 1; $i++) {
                $this->output()->write($migration_labels[$i] . ', ');
              }
              $this->output()->write($migration_labels[count($migration_labels) - 1]);
              $this->output()->write(" with its unmet dependencies listed below Yes or No ?");
              $this->output()->writeln('');
              foreach ($migration_dependencies as $migration_dependency) {
                $this->migrationLabels[] = Migration::labelForId($migration_dependency);
                $this->output()->writeln(' - "' . Migration::labelForId($migration_dependency) . '"');
              }
              $input = readline("\n");
              if (str_contains($input, 'y') || str_contains($input, 'Y')) {
                $this->executeMigrations($migration_labels);
                return $this->statusAfterImport($migration_labels);
              }
              else {
                $this->output()->writeln("Okay, Migrations will not be executed now.");
                $this->coordinator->stopOperation();
                return NULL;
              }

            case FALSE:
              // If there are no migration dependencies.
              if (empty($migration_dependencies)) {
                $this->executeMigrations($migration_labels);
                return $this->statusAfterImport($migration_labels);
              }
              $this->output()->writeln("Unmet dependencies listed below, please migrate these earlier or use --i to run in interactive mode.");
              foreach ($migration_dependencies as $migration_dependency) {
                $this->output()->writeln(' - "' . Migration::labelForId($migration_dependency) . '"');
              }
              $this->coordinator->stopOperation();
              break;
          }

      }
    }
    return NULL;
  }

  /**
   * Converts shorthand memory notation value to bytes.
   *
   * @param string $val
   *   Memory size shorthand notation string. E.g., 128M.
   *
   * @see http://php.net/manual/en/function.ini-get.php
   */
  private static function getBytes(string $val) : int {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $val = substr($val, 0, -1);
    switch ($last) {
      // Fall through (absence of break statements) is intentional.
      case 'g':
        $val *= 1024;
      case 'm':
        $val *= 1024;
      case 'k':
        $val *= 1024;
    }
    return (int) $val;
  }

  /**
   * Gets all stuck migrations.
   *
   * @return \Drupal\acquia_migrate\Migration[]
   *   A list of stuck migrations.
   */
  private function getStuckMigrations(): array {
    $stuck_migrations = [];
    $migrations = $this->migrationRepository->getMigrations();
    foreach ($migrations as $migration) {
      if ($migration->getActivity() === Migration::ACTIVITY_STUCK) {
        $stuck_migrations[] = $migration;
      }
    }
    return $stuck_migrations;
  }

  /**
   * Unsticks all (stuck) migrations.
   */
  private function unstickMigrations(array $migrations): void {
    if (!$this->coordinator->canModifyActiveOperation()) {
      throw new \LogicException('This can only be called if the current process is the one in control of the active operation.');
    }
    // When no migration is currently executing (because no lock is held), yet
    // migrations are marked as active, that means that one or more of the
    // active migration's migration plugins are incorrectly marked as active.
    // Reset their status.
    // This is essentially equivalent to `drush mrs *`.
    foreach ($migrations as $migration) {
      $stuck_migration_plugin_ids = [];
      foreach ($migration->getMigrationPluginInstances() as $migration_plugin_instance) {
        if ($migration_plugin_instance->getStatus() === MigrationInterface::STATUS_IDLE) {
          continue;
        }
        $migration_plugin_instance->setStatus(MigrationInterface::STATUS_IDLE);
        $stuck_migration_plugin_ids[] = $migration_plugin_instance->id();
      }
      // @codingStandardsIgnoreLine
      \Drupal::logger('acquia_migrate_drush')
        ->info('The "@migration-id" migration was unstuck, specifically the following data migration plugin IDs: @stuck-migration-plugin-ids.', [
          '@migration-id' => $migration->label(),
          '@stuck-migration-plugin-ids' => implode(', ', $stuck_migration_plugin_ids),
        ]);
    }
  }

  /**
   * Signal handler.
   *
   * @param int $signo
   *   Signal number.
   */
  public function signal(int $signo): void {
    switch ($signo) {
      case SIGINT:
        // Ensure that no new queued work is started.
        $this->interrupted = TRUE;
        // If there is a currently active migration, gracefully interrupt it.
        if (!$this->currentMigration) {
          return;
        }
        $plugin_instances = $this->currentMigration->getMigrationPluginInstances();
        foreach ($plugin_instances as $plugin_instance) {
          if ($plugin_instance->getStatus() !== MigrationInterface::STATUS_IDLE) {
            $plugin_instance->interruptMigration(MigrationInterface::RESULT_STOPPED);
          }
        }
    }
  }

  /**
   * Gets the status for the given migration labels after they were imported.
   *
   * @param string[] $migration_labels
   *   A list of migration labels.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   An object that will be printed as a table in the terminal.
   */
  protected function statusAfterImport(array $migration_labels): RowsOfFields {
    $status = [];
    foreach ($migration_labels as $migration_label) {
      $status = array_merge(
        $status,
        $this->status($migration_label, static::STATUS_OPTIONS_AFTER_IMPORT)->getArrayCopy()
      );
    }
    return new RowsOfFields($status);
  }

  /**
   * Generates the "finished" section based on $this->finishedOperations.
   *
   * @return string[]
   *   Terminal output.
   */
  private function generateFinishedSection(): array {
    $output = [];
    foreach ($this->finishedOperations as $finished) {
      [$migration_id, $status] = $finished;
      $output[] = sprintf(" \033[1;32m%s \033[0m%s", $status, Migration::labelForId($migration_id));
    }
    return $output;
  }

  /**
   * Executes provided migration IDs with its dependencies.
   *
   * @param string $migration_id
   *   Migration ID which needs to be migrated with all dependencies.
   */
  protected function recurDependencyExecute(string $migration_id): void {
    // When interrupted, start no further work.
    if ($this->interrupted) {
      return;
    }

    // Ensure dependencies are executed first.
    $dependencies = $this->getUnmetDependencies([$migration_id]);
    foreach ($dependencies as $dependency) {
      $this->recurDependencyExecute($dependency);
    }

    $migration = $this->migrationRepository->getMigration($migration_id);

    // Execute the migration if:
    // - not interrupted
    // - all dependent rows have been processed (to a degree, see docs)
    // - it hasn't already finished (due to another migration depending on this)
    if (!$this->interrupted && $migration->allDependencyRowsProcessed() && !in_array($migration_id, array_column($this->finishedOperations, 0))) {
      // Migrations can be requested even if they're already done.
      if ($migration->allRowsProcessed()) {
        $this->finishedOperations[] = [$migration_id, '🏁'];
      }
      else {
        $this->runBatch($migration);
        if (!$this->interrupted) {
          $this->finishedOperations[] = [$migration_id, '✅'];
        }
        else {
          // This was interrupted aka paused: indicate that in the status.
          $this->finishedOperations[] = [$migration_id, '⏸ '];
        }
      }
      $this->finishedSection->overwrite($this->generateFinishedSection());
    }
  }

  /**
   * Runs the actual batch.
   *
   * @param \Drupal\acquia_migrate\Migration $migration
   *   The migration to import.
   */
  protected function runBatch(Migration $migration): void {
    $batch_tasks = $this->migrationBatchManager->createMigrationBatchTasks($migration->id(), MigrationBatchManager::ACTION_IMPORT);
    $this->currentMigration = $migration;
    $total_rows = $migration->getTotalCount();
    $config_rows = 0;
    $config_migration_plugin_instances = array_intersect_key(
      $migration->getMigrationPluginInstances(),
      array_fill_keys($migration->getSupportingConfigurationMigrationPluginIds(), TRUE)
    );
    foreach ($config_migration_plugin_instances as $migration_plugin) {
      $config_rows += $migration_plugin->getSourcePlugin()->count();
    }
    // Start progress bars.
    $this->configProgress->setMessage('   Config:');
    $this->configProgress->start($config_rows);
    $this->dataProgress->setMessage('   Data:');
    $this->dataProgress->setMessage($migration->label(), 'migration_label');
    $this->dataProgress->start($total_rows);
    $this->messageCountProgress->setMessage('   Messages:');
    $this->messageCountProgress->start();
    // Execution.
    $this->executeBatchOperations($batch_tasks['operations']);
    $this->dataProgress->finish();
    $this->configProgress->finish();
    $this->messageCountProgress->finish();
    $this->currentMigration = NULL;
    $this->currentPluginID = NULL;
    $this->drawProgress(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  final public function executeBatchOperation($export_task, &$context): void {
    // In order for being able to validate an export plugin, steps are only
    // allowed to get an \ArrayAccess instance as parameter.
    // This helper callback translates a Drupal Form API batch context with type
    // 'array' to an \ArrayObject and keeps sync its values with the original
    // batch context.
    $array_access_context = $context;
    try {
      $migration_task = array_shift($export_task);
      $arguments = array_shift($export_task) ?: [];
      $function = is_array($migration_task)
        ? implode('::', $migration_task)
        : $migration_task;
      // Switch Case for different migration tasks.
      switch (count($arguments)) {
        case 0:
          $function($array_access_context);
          break;

        case 1:
          $function($arguments[0], $array_access_context);
          break;

        case 2:
          $function($arguments[0], $arguments[1], $array_access_context);
          break;
      }
      if (is_array($context)) {
        foreach ($array_access_context as $key => $value) {
          $context[$key] = $value;
        }
      }
      return;
    }
    catch (\Throwable $exception) {
      // Cleanly stop the operation to keep AMA in a consistent state.
      $this->coordinator->stopOperation();
      // … and then re-throw!
      throw $exception;
    }
  }

  /**
   * Get configuration progress.
   */
  protected function getConfigProgress(Migration $migration): int {
    $config_rows = 0;
    $config_migration_plugin_instances = array_intersect_key(
      $migration->getMigrationPluginInstances(),
      array_fill_keys($migration->getSupportingConfigurationMigrationPluginIds(), TRUE)
    );
    foreach ($config_migration_plugin_instances as $migration_plugin) {
      $config_rows += $migration_plugin->getIdMap()->processedCount();
    }
    return $config_rows;
  }

  /**
   * Draw and maintain progress bars.
   */
  public function drawProgress($force = FALSE) {
    // Extend lock on every processed row.
    $this->coordinator->extendActiveOperation(10);
    // Allow updates only when >=1 s has passed from the last update.
    if ((microtime(TRUE) - AcquiaMigrateUpgradeImportBatch::$lastUpdationTime) < 1) {
      // … unless this is a forced progress drawing.
      if (!$force) {
        return;
      }
    }
    AcquiaMigrateUpgradeImportBatch::$lastUpdationTime = microtime(TRUE);

    // Forcefully refresh the migration repository, to ensure up-to-date counts.
    ServerTimingHeaderForResponseSubscriber::dropQueryLog();
    $this->migrationRepository->getMigrations(TRUE);

    $this->totalProgress->setProgress($this->countRows($this->migrationLabels, 'processed'));
    // Protect against edge case: we may draw progress when there is no
    // currently active batch (for example when interrupting the in-progress
    // migration).
    if (!$this->context || empty($this->context['sandbox'])) {
      return;
    }

    // Update $this->currentMigration to ensure up-to-date counts.
    $this->currentMigration = $this->migrationRepository->getMigration($this->currentMigration->id());

    // Assuming that we always have an active Migration PluginID.
    $currently_processing_migration_plugin = $this->currentMigration->getActiveMigrationPluginId() ?: '';

    $this->dataProgress->setProgress($this->currentMigration->getProcessedCount());
    $current_config_progress = $this->getConfigProgress($this->currentMigration);
    $this->configProgress->setProgress($current_config_progress);
    $this->messageCountProgress->setProgress($this->currentMigration->getMessageCount());
    if (!isset($this->currentPluginID)) {
      $this->currentPluginID = $currently_processing_migration_plugin;
      $this->migrationPluginProgress->setMessage($this->currentPluginID);
      $max = $this->currentMigration->getMigrationPluginInstances()[$this->currentPluginID]->getSourcePlugin()->count();
      $this->migrationPluginProgress->start($max);
    }
    // Check if we have finished migrating the last plugin ID.
    if ($this->currentPluginID !== $currently_processing_migration_plugin) {
      $this->currentPluginID = $currently_processing_migration_plugin;
      $max = $this->currentMigration->getMigrationPluginInstances()[$this->currentPluginID]->getSourcePlugin()->count();
      $this->migrationPluginProgress->setMaxSteps($max);
      $this->migrationPluginProgress->setMessage($this->currentPluginID);
      $this->migrationPluginProgress->setProgress(0);
    }
    $this->migrationPluginProgress->setProgress($this->currentMigration->getMigrationPluginInstances()[$this->currentPluginID]->getIdMap()->processedCount());
  }

  /**
   * Executes batch operations.
   */
  protected function executeBatchOperations($operations) {
    // Set $callable to be used in AcquiaMigrateUpgradeImportBatch.php.
    AcquiaMigrateUpgradeImportBatch::$callable = [$this, 'drawProgress'];
    AcquiaMigrateUpgradeImportBatch::$isDrush = 'drush';
    $context = [];
    $this->context = &$context;
    foreach ($operations as $operation) {
      $context = array_filter([
        'finished' => 1,
        'results' => $context['results'] ?? NULL,
      ]);
      try {
        do {
          $verbosity = $this->output()->getVerbosity();
          $this->output()->setVerbosity(OutputInterface::VERBOSITY_QUIET);
          // Execute a single operation.
          $this->executeBatchOperation($operation, $context);
          $this->output()->setVerbosity($verbosity);
        } while ($context['finished'] < 1);

      }
      catch (\Exception $exception) {
        $this->coordinator->stopOperation();
      }
      ServerTimingHeaderForResponseSubscriber::dropQueryLog();
      $this->migrationRepository->getMigrations(TRUE);
    }
    $this->migrationPluginProgress->clear();
  }

  /**
   * Gets total, processed or imported row counts for the given migrations.
   *
   * @param array $migration_labels
   *   Array of migration labels.
   * @param string $count_type
   *   Either 'total', 'processed' or 'imported'.
   *
   * @return int
   *   The requested row count type.
   */
  protected function countRows(array $migration_labels, string $count_type) : int {
    $count = 0;
    foreach ($migration_labels as $migration_label) {
      $migration = $this->migrationRepository->getMigration(Migration::generateIdFromLabel($migration_label));
      switch ($count_type) {
        case 'total':
          $count += $migration->getTotalCount();
          break;

        case 'processed':
          $count += $migration->getProcessedCount();
          break;

        case 'imported':
          $count += $migration->getImportedCount();
          break;

        default:
          throw new \InvalidArgumentException();
      }
    }
    return $count;
  }

  /**
   * Checks if the given migration already has all its rows processed.
   *
   * @param string $migration_id
   *   Migration ID that needs to be checked if its migrated or not.
   */
  protected function isNotMigrated(string $migration_id) : bool {
    return !$this->migrationRepository->getMigration($migration_id)->allRowsProcessed();
  }

  /**
   * Validate migration labels.
   *
   * @param array $migration_labels
   *   Migration labels which need to be validated.
   * @param array $all_migrations
   *   All valid migration Ids.
   */
  protected function areInvalidLabels(array $migration_labels, array $all_migrations) {
    $migration_ids = [];
    foreach ($migration_labels as $migration_label) {
      array_push($migration_ids, Migration::generateIdFromLabel($migration_label));
    }
    return array_diff($migration_ids, $all_migrations);
  }

  /**
   * Function to print invalid labels.
   *
   * @param array $invalid_labels
   *   Array of invalid labels.
   */
  protected function printInvalidLabels(array $invalid_labels) {
    $this->output()->writeln('The invalid labels are :');
    // List all invalid labels.
    foreach ($invalid_labels as $invalid_label) {
      $this->output()->writeln(' - "' . Migration::labelForId($invalid_label) . '"');
    }
    $this->output()->writeln('');
    $label_or_labels = 'labels';
    if (count($invalid_labels) === 1) {
      $label_or_labels = 'label';
    }
    $this->output()->writeln('Please provide valid migration ' . $label_or_labels . ' from below or use the --all option.');
  }

  /**
   * Function to exclude migrations which are already in review or completed.
   *
   * @param string[] $temp_migration_labels
   *   Array of migration labels.
   */
  protected function excludeCompletedMigrations(array $temp_migration_labels) : array {
    $migration_labels = [];
    // Remove migrations which are already executed.
    foreach ($temp_migration_labels as $migration_label) {
      if ($this->isNotMigrated(Migration::generateIdFromLabel($migration_label))) {
        array_push($migration_labels, $migration_label);
      }
    }
    return $migration_labels;
  }

  /**
   * Calculate unmet dependencies.
   *
   * @param string[] $migration_ids
   *   Array of migration labels.
   */
  protected function getUnmetDependencies(array $migration_ids): array {
    $migration_dependencies = [];
    foreach ($migration_ids as $migration_id) {
      $temp_migration_dependencies = $this->migrationRepository->getMigration($migration_id)
        ->getDependencies();
      foreach ($temp_migration_dependencies as $migration_dependency) {
        $migration = $this->migrationRepository->getMigration($migration_dependency);
        if (!$migration->allRowsProcessed()) {
          array_push($migration_dependencies, $migration_dependency);
        }
      }
    }
    return $migration_dependencies;
  }

  /**
   * Executes a list of migrations.
   *
   * @param array $migration_labels
   *   Array of migration labels.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function executeMigrations(array $migration_labels) : void {
    // Create section for total progress bar.
    // Set decorated to True explicitly to support ANSI on acquia-cli.
    $this->output()->setDecorated(TRUE);
    $section_total_progress = $this->output()->section();
    $this->totalProgress = new ProgressBar($section_total_progress);
    ProgressBar::setFormatDefinition('custom4', "%message% [%bar%]\n\033[1;37m📈 %current% processed, %current_imported% imported of %max% total rows (memory consumption: %memory:6s%/%memory_limit:6s%)\n🏁 %remaining_better:6s% (elapsed: %elapsed:6s% for %accurate_progress% rows)\033[0m" . "\n");
    $memory_limit_bytes = static::getBytes(ini_get('memory_limit'));
    $this->totalProgress->setMessage(Helper::formatMemory($memory_limit_bytes), 'memory_limit');
    ProgressBar::setPlaceholderFormatterDefinition('memory', function () use ($memory_limit_bytes) {
      $mem = memory_get_usage(TRUE);
      // Sets initial color to green then yellow at 70% and red at 90%.
      $colors = '42;37';
      if ($mem / $memory_limit_bytes > 0.7) {
        $colors = '43;37';
      }
      if ($mem / $memory_limit_bytes > 0.9) {
        $colors = '41;37';
      }
      return "[" . $colors . 'm ' . Helper::formatMemory($mem) . " [0m";
    });
    $this->totalProgress->setBarCharacter('<fg=green>■</>');
    $this->totalProgress->setEmptyBarCharacter('<fg=red>-</>');
    $this->totalProgress->setProgressCharacter('');
    $this->totalProgress->setBarWidth((new Terminal())->getWidth());
    // Set custom formats for progress bar.
    $this->totalProgress->setFormat('custom4');
    $this->finishedSection = $this->output()->section();
    assert($this->finishedSection instanceof ConsoleSectionOutput);
    $section_data_progress = $this->output()->section();
    $section_config_progress = $this->output()->section();
    $section_message_progress = $this->output()->section();
    $section_plugin_progress = $this->output()->section();
    $this->dataProgress = new ProgressBar($section_data_progress);
    $this->configProgress = new ProgressBar($section_config_progress);
    $this->messageCountProgress = new ProgressBar($section_message_progress);
    $this->migrationPluginProgress = new ProgressBar($section_plugin_progress);
    // Define custom formats for progress bars.
    ProgressBar::setFormatDefinition('custom0', "[\033[1;37m%migration_label%\033[0m]:" . "\n" . "%message% %current:7s%/%max%");
    ProgressBar::setFormatDefinition('custom', '   Current Migration Plugin: %message% [%bar%] %current:3s%/%max%');
    ProgressBar::setFormatDefinition('custom1', '%message% %current:5s%/%max%');
    ProgressBar::setFormatDefinition('custom2', '%message% %current:5s%');
    // Set custom formats for progress bars.
    $this->migrationPluginProgress->setFormat('custom');
    $this->configProgress->setFormat('custom1');
    $this->messageCountProgress->setFormat('custom2');
    $this->dataProgress->setFormat('custom0');
    $this->migrationLabels = array_merge($migration_labels, $this->migrationLabels);
    $total_rows = $this->countRows($this->migrationLabels, 'total');
    $this->totalProgress->setMessage("\033[1;37mTotal progress:\033[0m");
    $this->totalProgress->setMaxSteps($total_rows);
    // Initialize total progress and make the %remaining% placeholder actually
    // correct: the upstream one is not aware of *continuing* a progress bar.
    $total_progress_starting_steps = $this->countRows($this->migrationLabels, 'processed');
    ProgressBar::setPlaceholderFormatterDefinition(
      'current_imported',
      function (ProgressBar $bar) {
        return $this->countRows($this->migrationLabels, 'imported');
      }
    );
    ProgressBar::setPlaceholderFormatterDefinition(
      'accurate_progress',
      function (ProgressBar $bar) use ($total_progress_starting_steps) {
        $accurate_progress = max(0, $bar->getProgress() - $total_progress_starting_steps);
        return $accurate_progress;
      }
    );
    ProgressBar::setPlaceholderFormatterDefinition(
      'remaining_better',
      function (ProgressBar $bar, OutputInterface $output) use ($total_progress_starting_steps) {
        $accurate_progress = max(0, $bar->getProgress() - $total_progress_starting_steps);
        if ($accurate_progress === 0) {
          return 'estimating…';
        }
        $remaining = round((time() - $bar->getStartTime()) / ($accurate_progress) * ($bar->getMaxSteps() - $accurate_progress));
        return Helper::formatTime($remaining);
      }
    );
    $this->totalProgress->setProgress($total_progress_starting_steps);
    foreach ($migration_labels as $migration_label) {
      // GenerateIdFromLabel() validates the migration_label with conversion.
      $migration_id = Migration::generateIdFromLabel($migration_label);
      $this->recurDependencyExecute($migration_id);
    }
    $section_config_progress->clear();
    $section_data_progress->clear();
    $section_message_progress->clear();
    // Calling drawProgress() because of the optimisation in place which only
    // allows it to update after 1 sec of previous call which may lead to
    // inconsistencies.
    // Passed True to make sure it always happens.
    $this->drawProgress(TRUE);
    $this->totalProgress->clear();
    $this->coordinator->stopOperation();
  }

  /**
   * Export migration messages as a csv.
   *
   * @param string|null $input
   *   Migration label or migration plugin ID.
   * @param string|null $messageCategory
   *   Category of message.
   * @param array $options
   *   The options to pass.
   *
   * @command ama:messages:export
   *
   * @option all Export all migration messages.
   * @option ml Migration label.
   * @option mp Migration plugin.
   * @option c Category of message.
   *
   * @usage ama:messages:export --all
   *   Export all migration messages.
   * @usage ama:messages:export --ml 'migration_label'
   *   Export messages for given migration label.
   * @usage ama:messages:export --mp 'migration_plugin'
   *   Export messages for given migration plugin.
   * @usage ama:messages:export --ml 'migration_label' --c 'entity_validation'
   *   Export messages for given migration label and category.
   * @usage ama:messages:export --mp 'migration_plugin' --c 'entity_validation'
   *   Export messages for given migration plugin and category.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amame
   */
  public function exportMessages(string $input = NULL, string $messageCategory = NULL, array $options = [
    'all' => FALSE,
    'ml' => FALSE,
    'mp' => FALSE,
    'c' => FALSE,
  ]) {
    if (!$options['all'] && empty($input))  {
      $this->output()->writeln('⛔️ Invalid parameters, refer --help.');
      return;
    }   // Export all the migration messages if all flag is set.
    if ($options['all']) {
      $this->executeQuery();
    }
    // If migration label flag is set.
    elseif ($options['ml']) {
      $all_migrations = array_keys($this->migrationRepository->getMigrations());
      $migration_id = Migration::generateIdFromLabel($input);
      if (!in_array($migration_id, $all_migrations, TRUE)) {
        $this->output()->writeln('⛔️ Invalid migration label');
        return;
      }
      $this->executeQuery($migration_id, 'sourceMigration', $messageCategory);
    }
    // If migration plugin flag is set.
    elseif ($options['mp']) {
      $all_migrations = $this->migrationRepository->getMigrations();
      $all_migration_plugin_ids = [];
      foreach ($all_migrations as $migration) {
        $all_migration_plugin_ids = array_merge($all_migration_plugin_ids, $migration->getMigrationPluginIds());
      }
      if (!in_array($input, $all_migration_plugin_ids, TRUE)) {
        $this->output()->writeln('⛔️ Invalid migration plugin ID');
        return;
      }
      $migration_plugin = $input;
      $this->executeQuery($migration_plugin, 'sourceMigrationPlugin', $messageCategory);
    }
  }

  /**
   * Execute query.
   *
   * @param string|null $migration_or_migration_plugin
   *   Migration label or migration plugin.
   * @param string|null $migration_or_migration_plugin_column
   *   switch between sourceMigration and sourceMigrationPlugin.
   * @param string|null $messageCategory
   *   Category if message.
   */
  protected function executeQuery(string $migration_or_migration_plugin = NULL, string $migration_or_migration_plugin_column = NULL, string $messageCategory = NULL) {
    $db_connection = Database::getConnection();
    if (!$db_connection) {
      $this->output()->writeln('⛔️ Unable to connect to database.');
      return;
    }
    $messages = $db_connection->select('acquia_migrate_messages', 'messages')
      ->fields('messages');
    if ($migration_or_migration_plugin) {
      $messages->condition($migration_or_migration_plugin_column, $migration_or_migration_plugin);
    }
    if ($messageCategory) {
      $messages->condition('messageCategory', $messageCategory);
    }
    $final_messages = $messages->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $this->writeIntoFile($final_messages);
  }

  /**
   * Writes messages to a file.
   *
   * @param array $messages
   *   Messages to write to a file.
   */
  private function writeIntoFile(array $messages) {
    // Open file.
    $f = fopen('messages.csv', 'w+');
    if ($f === FALSE) {
      die('Error opening the file messages.csv');
    }
    $header = ['timestamp', 'sourceMigration', 'sourceMigrationPlugin', 'msgid', 'source_ids_hash', 'source_id', 'messageCategory', 'severity', 'message'];
    fputcsv($f, $header);
    foreach ($messages as $message) {
      fputcsv($f, $message);
    }
    // Close file.
    fclose($f);
    $this->output()->writeln('✅ Messages saved to messages.csv in the docroot.');
  }

  /**
   * Gets the analysis-only instantiation of the source plugin for a migration.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin instance whose source plugin to get an analysis-only
   *   instantiation for.
   *
   * @return \Drupal\migrate\Plugin\MigrateSourceInterface
   *   The analysis-only source plugin for the given migration plugin.
   */
  private function getAnalysisOnlySourcePluginFor(MigrationPlugin $migration_plugin) : MigrateSourceInterface {
    $definition = $migration_plugin->getPluginDefinition();

    // Also ensure there is no query alteration for a high water property.
    if ($definition['source']['high_water_property']) {
      unset($definition['source']['high_water_property']);
    }

    // Analyses should look at uncached data.
    if ($definition['source']['cache_counts']) {
      unset($definition['source']['cache_counts']);
    }

    $source_plugin = $this->sourcePluginManager->createInstance(
      $definition['source']['plugin'],
      $definition['source'],
      // Ensure that there _is_ no map data, to be able to analyze every source
      // row without side effects. This also ensures the map is not joinable, so
      // there is no need to set `ignore_map`.
      // @see \Drupal\migrate\Plugin\migrate\source\SqlBase::initializeIterator()
      new NullMigration()
    );
    assert($source_plugin instanceof MigrateSourceInterface);
    return $source_plugin;
  }

  /**
   * Checks whether a source row exists in the given ID map plugin instance.
   *
   * @param \Drupal\migrate\Plugin\MigrateIdMapInterface $map
   *   Map plugin.
   * @param array $source_id_values
   *   The source identifier keyed values of the record, e.g. ['nid' => 5].
   *
   * @return bool
   *   Whether the source row is unprocessed or not.
   */
  private static function isUnprocessedSourceRow(MigrateIdMapInterface $map, array $source_id_values) : bool {
    $raw_row = $map->getRowBySource($source_id_values);
    $is_unprocessed = $raw_row['source_row_status'] === NULL;
    return $is_unprocessed;
  }

  /**
   * Assesses a processed row.
   *
   * @param object $row
   *   A raw row from SqlWithCentralizedMessageStorage::getUnimportedRows().
   *
   * @return string
   *   Whether the source row is unprocessed or not.
   *
   * @throws \Exception
   *   When an unknown migrate map status is encountered.
   *
   * @see \Drupal\acquia_migrate\Plugin\migrate\id_map\SqlWithCentralizedMessageStorage::getUnimportedRows()
   */
  private static function assessProcessedRow(\stdClass $row) : string {
    switch ($row->source_row_status) {
      case MigrateIdMapInterface::STATUS_IMPORTED:
        $assessment = 'imported';
        break;

      case MigrateIdMapInterface::STATUS_NEEDS_UPDATE:
        $assessment = 'needs_update';
        break;

      case MigrateIdMapInterface::STATUS_IGNORED:
        $assessment = 'ignored';
        break;

      case MigrateIdMapInterface::STATUS_FAILED:
        $assessment = 'failed';
        break;

      default:
        throw new \Exception('Unknown migration map status!');
    }
    return $assessment;
  }

  /**
   * The current value of the high water mark for the given migration plugin.
   *
   * The high water mark defines a timestamp stating the time the import was
   * last run. If the mark is set, only content with a higher timestamp will be
   * imported.
   *
   * @return int|null
   *   A Unix timestamp representing the high water mark, or NULL if no high
   *   water mark has been stored.
   *
   * @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::getHighWater
   */
  private function getHighWater(string $migration_plugin_id) : ?int {
    return (int) $this->keyValue->get('migrate:high_water')->get($migration_plugin_id, 0);
  }

}

/**
 * A "null" migration: one without a source and an empty ID map.
 */
final class NullMigration extends MigrationPlugin {

  /**
   * {@inheritdoc}
   */
  public function __construct() {
    // This is needed to avoid crashing SourcePluginBase.
    // @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::__construct()
    $this->idMapPlugin = new NullIdMap([], 'null', []);
    // This is needed to avoid crashing certain hook_migrate_prepare_row()
    // implementations.
    // @see metatag_migrate_prepare_row()
    $this->destinationPlugin = new NullDestination([], 'null', [], $this);
  }

}
