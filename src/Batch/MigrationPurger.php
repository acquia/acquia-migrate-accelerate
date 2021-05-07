<?php

namespace Drupal\acquia_migrate\Batch;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Import and sync source and destination.
 *
 * Duplicated from Drupal\migrate_tools\EventSubscriber\MigrationImportSync
 * unless otherwise noted. Changes from the original are denoted by the string
 * CHANGED.
 *
 * CHANGED:
 * - This class's name has been changed.
 * - This class's namespace has been changed.
 * - This class has been marked final.
 * - This class no longer implements EventSubscriberInterface.
 *
 * @internal
 */
final class MigrationPurger {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $dispatcher;

  /**
   * MigrationPurger constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   */
  public function __construct(EventDispatcherInterface $dispatcher) {
    $this->dispatcher = $dispatcher;
  }

  /**
   * Event callback to sync source and destination.
   *
   * CHANGED: this method has been renamed from 'sync' to 'purge'.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The migration import event.
   */
  public function purge(MigrateImportEvent $event) {
    $migration = $event->getMigration();
    // CHANGED: the next line's condition has been changed so that the code
    // block always runs. The original condition was dependent on migrate_tool's
    // migrate-import Drush command.
    if (TRUE) {
      $id_map = $migration->getIdMap();
      // Force 'needs update' state for every imported record. This is needed
      // for finding those source ID values which were deleted from the source
      // since the last import.
      $id_map->prepareUpdate();
      // Clone so that any generators aren't initialized prematurely.
      $source = clone $migration->getSourcePlugin();
      $source->rewind();
      $source_id_values = [];
      while ($source->valid()) {
        $source_id_values[] = $source->current()->getSourceIdValues();
        $source->next();
      }
      $id_map->rewind();
      $destination = $migration->getDestinationPlugin();
      while ($id_map->valid()) {
        $map_source_id = $id_map->currentSource();
        // If the destination found in ID map does not have a cooresponding
        // entry in the source (so it is missing), then it means it was deleted
        // from the source.
        if (!in_array($map_source_id, $source_id_values, TRUE)) {
          $destination_ids = $id_map->currentDestination();
          $this->dispatchRowDeleteEvent(MigrateEvents::PRE_ROW_DELETE, $migration, $destination_ids);
          $this->dispatchRowDeleteEvent(MigratePlusEvents::MISSING_SOURCE_ITEM, $migration, $destination_ids);
          $destination->rollback($destination_ids);
          $this->dispatchRowDeleteEvent(MigrateEvents::POST_ROW_DELETE, $migration, $destination_ids);
          $id_map->delete($map_source_id);
        }
        $id_map->next();
      }
      $this->dispatcher->dispatch(MigrateEvents::POST_ROLLBACK, new MigrateRollbackEvent($migration));
    }
  }

  /**
   * Dispatches MigrateRowDeleteEvent event.
   *
   * @param string $event_name
   *   The event name to dispatch.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The active migration.
   * @param array $destination_ids
   *   The destination identifier values of the record.
   */
  protected function dispatchRowDeleteEvent($event_name, MigrationInterface $migration, array $destination_ids) {
    // Symfony changing dispatcher so implementation could change.
    $this->dispatcher->dispatch($event_name, new MigrateRowDeleteEvent($migration, $destination_ids));
  }

}
