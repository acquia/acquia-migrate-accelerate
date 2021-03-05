<?php

namespace Drupal\acquia_migrate\Plugin\migrate\process;

use Drupal\acquia_migrate\AcquiaMigrateMigrateStub;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\migrate\process\MigrationLookup;
use Drupal\migrate\Plugin\MigrationDeriverTrait;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\MigrationConfigurationTrait;
use Drupal\migrate_drupal\NodeMigrateType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A migration lookup plugin which can create stubs for derived migrations.
 *
 * This plugin is used by AcquiaMigrateEntityReference and by
 * AcquiaMigrateNodeReference (entity|node) reference field migration plugins
 * for determining the final field value (target node ID) for the destination
 * site. This makes AM:A able to ignore the known
 * "d*_entity_reference_translation" follow-up migrations.
 *
 * @MigrateProcessPlugin(
 *   id = "acquia_migrate_migration_lookup"
 * )
 *
 * @see \Drupal\acquia_migrate\MigrationAlterer::removeFollowupMigrations()
 */
class AcquiaMigrateMigrationLookup extends MigrationLookup {

  use MigrationConfigurationTrait;
  use MigrationDeriverTrait;

  /**
   * The migrate stub service.
   *
   * @var \Drupal\acquia_migrate\AcquiaMigrateMigrateStub
   */
  protected $migrateStub;

  /**
   * The migration plugin manager service.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationPluginManager;

  /**
   * Constructs a MigrationLookup object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The Migration the plugin is being used in.
   * @param \Drupal\migrate\MigrateLookupInterface $migrate_lookup
   *   The migrate lookup service.
   * @param \Drupal\acquia_migrate\AcquiaMigrateMigrateStub $migrate_stub
   *   The migrate stub service.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_plugin_manager
   *   The migration plugin's manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, MigrateLookupInterface $migrate_lookup, AcquiaMigrateMigrateStub $migrate_stub, MigrationPluginManagerInterface $migration_plugin_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $migrate_lookup, $migrate_stub);
    $this->migrationPluginManager = $migration_plugin_manager;
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
      $container->get('migrate.lookup'),
      $container->get('acquia_migrate.stub'),
      $container->get('plugin.manager.migration')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $lookup_migration_ids = (array) $this->configuration['migration'];
    $destination_ids = NULL;
    $source_id_values = [];
    foreach ($lookup_migration_ids as $lookup_migration_id) {
      if (isset($this->configuration['source_ids'][$lookup_migration_id])) {
        $value = array_values($row->getMultiple($this->configuration['source_ids'][$lookup_migration_id]));
      }
      if (!is_array($value)) {
        $value = [$value];
      }
      $this->skipInvalid($value);
      $source_id_values[$lookup_migration_id] = $value;

      // Re-throw any PluginException as a MigrateException so the executable
      // can shut down the migration.
      try {
        $destination_id_array = $this->migrateLookup->lookup($lookup_migration_id, $value);
      }
      catch (PluginNotFoundException $e) {
        $destination_id_array = [];
      }
      catch (MigrateException $e) {
        throw $e;
      }
      catch (\Exception $e) {
        throw new MigrateException(sprintf('A %s was thrown while processing this migration lookup', gettype($e)), $e->getCode(), $e);
      }

      if ($destination_id_array) {
        $destination_ids = array_values(reset($destination_id_array));
        break;
      }
    }

    if (!$destination_ids && !empty($this->configuration['no_stub'])) {
      return NULL;
    }

    if (!$destination_ids) {
      $destination_ids = $this->getDestinationIds($source_id_values);
    }
    if ($destination_ids) {
      if (count($destination_ids) == 1) {
        return reset($destination_ids);
      }
      else {
        return $destination_ids;
      }
    }
  }

  /**
   * Returns the IDs of the migration plugins where the stub can be created.
   *
   * @return string[][]
   *   The full ID of the migrations, keyed by the plugin ID as they were
   *   defined in the process plugin's configuration.
   */
  protected function getStubMigrationIds(): array {
    $stub_migration_ids = [];
    $stub_migration_candidates = isset($this->configuration['stub_id'])
      ? (array) $this->configuration['stub_id']
      : (array) $this->configuration['migration'];

    if (count($stub_migration_candidates) > 1) {
      // "d7_comment" contains the "d7_node_complete" migration twice.
      $stub_migration_candidates = array_unique($stub_migration_candidates);

      [
        'legacy_core_version' => $legacy_drupal,
        'node_migration_is_complete' => $node_migration_type_is_complete,
      ] = static::getSourceMigrationInfo();

      // "statistics_node_translation_counter" searches source values in both
      // "d6_node_translation" and "d7_node_translation".
      if ($legacy_drupal) {
        $stub_migration_candidates = array_filter($stub_migration_candidates, function (string $id) use ($legacy_drupal) {
          return !preg_match('/^d[^' . $legacy_drupal . ']_.+$/', $id);
        });
      }

      // If this migration tries to look up a destination value in both
      // "d*_node_translation" and "d*_node_complete", we can decide which one
      // should be used.
      $node_migration_ids = [
        "d{$legacy_drupal}_node_translation",
        "d{$legacy_drupal}_node_complete",
        "d{$legacy_drupal}_node",
      ];
      if (empty(array_diff($stub_migration_candidates, $node_migration_ids))) {
        if ($node_migration_type_is_complete) {
          $translation_migration_key = array_search("d{$legacy_drupal}_node_translation", $stub_migration_candidates);
          $node_migration_key = array_search("d{$legacy_drupal}_node", $stub_migration_candidates);
          if ($translation_migration_key !== FALSE) {
            unset($stub_migration_candidates[$translation_migration_key]);
          }
          if ($node_migration_key !== FALSE) {
            unset($stub_migration_candidates[$node_migration_key]);
          }
        }
        else {
          $complete_migration_key = array_search("d{$legacy_drupal}_node_complete", $stub_migration_candidates);
          unset($stub_migration_candidates[$complete_migration_key]);
        }
      }
    }

    if (count($stub_migration_candidates) > 1) {
      return [];
    }

    foreach ($stub_migration_candidates as $lookup_migration_id) {
      $stub_migrations = [];
      try {
        $stub_migrations = $this->migrationPluginManager->createInstances([$lookup_migration_id]);
      }
      catch (PluginException $e) {
      }

      $stub_migration_ids[$lookup_migration_id] = array_reduce($stub_migrations, function (array $carry, MigrationInterface $migration) {
        $carry[] = $migration->id();
        return $carry;
      }, []);
    }

    return $stub_migration_ids;
  }

  /**
   * Creates valid stub in the right migration and returns its destination IDs.
   *
   * @param array $source_id_values
   *   The source ID values as collected by migration lookup.
   *
   * @return array|null
   *   The destination IDs of the stub, or NULL if it cannot be created.
   *
   * @throws \Drupal\migrate\MigrateException
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  protected function getDestinationIds(array $source_id_values): ?array {
    $destination_ids = NULL;
    $exception = NULL;
    foreach ($this->getStubMigrationIds() as $stub_migration_base_id => $stub_migration_full_ids) {
      foreach ($stub_migration_full_ids as $stub_migration_full_id) {
        try {
          $destination_ids = $this->migrateStub->createStub($stub_migration_full_id, $source_id_values[$stub_migration_base_id], [], FALSE, TRUE);
        }
        catch (\LogicException $e) {
          // MigrateStub::createStub() shouldn't throw LogicException because
          // we pass a full migration plugin ID param, but the original clause
          // also caught LogicException thrown in MigrateStub::doCreateStub().
          // So we have to continue to catch this for BC.
          // @todo Instead of this, MigrateStub::doCreateStub() should catch
          //   the expected Throwables and thrown them as MigrateException.
        }
        catch (PluginNotFoundException $e) {
          // This also catches PluginNotFoundException thrown in
          // MigrateStub::doCreateStub().
        }
        catch (MigrateException $exception) {
        }
        catch (MigrateSkipRowException $exception) {
        }
        catch (\Exception $exception) {
        }

        if ($destination_ids) {
          break 2;
        }
      }
    }

    // Rethrow the last exception as a MigrateException so the executable can
    // shut down the migration.
    if (empty($destination_ids) && $exception) {
      if (
        $exception instanceof MigrateException ||
        $exception instanceof MigrateSkipRowException
      ) {
        throw $exception;
      }

      throw new MigrateException(sprintf('A(n) %s was thrown while attempting to stub, with the following message: %s.', get_class($exception), $exception->getMessage()), $exception->getCode(), $exception);
    }

    return !empty($destination_ids) ? $destination_ids : NULL;
  }

  /**
   * Returns legacy core version and info about the node migration.
   *
   * @return array
   *   The legacy core version, keyed by "legacy_core_version" (a string or
   *   FALSE);
   *   A booleal indicating whether the node migration type is "complete" or
   *   not, keyed by "node_migration_is_complete".
   */
  protected static function getSourceMigrationInfo(): array {
    // Use the simplest Drupal source plugins.
    $source_db = static::getSourcePlugin('variable')->getDatabase();
    $legacy_core_version = static::getLegacyDrupalVersion($source_db);
    // @codingStandardsIgnoreLine
    $node_migration_is_complete = class_exists(NodeMigrateType::class) && is_callable([NodeMigrateType::class, 'getNodeMigrateType'])
      ? NodeMigrateType::getNodeMigrateType($source_db, $legacy_core_version) === NodeMigrateType::NODE_MIGRATE_TYPE_COMPLETE
      : FALSE;

    return [
      'legacy_core_version' => $legacy_core_version,
      'node_migration_is_complete' => $node_migration_is_complete,
    ];
  }

}
