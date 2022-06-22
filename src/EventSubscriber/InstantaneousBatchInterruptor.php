<?php

declare(strict_types = 1);

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\acquia_migrate\Batch\MigrationBatchCoordinator;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationInterface as MigrationPluginInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Instantaneously interrupts the migrate executable.
 */
final class InstantaneousBatchInterruptor implements EventSubscriberInterface {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * State key for tracking whether the current activity should be stopped.
   *
   * @const string
   */
  const KEY = 'acquia_migrate.stop_activity';

  /**
   * The migration batch coordinator.
   *
   * @var \Drupal\acquia_migrate\Batch\MigrationBatchCoordinator
   */
  protected $coordinator;

  /**
   * The InstantaneousBatchInterruptor constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\acquia_migrate\Batch\MigrationBatchCoordinator $coordinator
   *   The migration batch coordinator.
   */
  public function __construct(StateInterface $state, MigrationBatchCoordinator $coordinator) {
    $this->state = $state;
    $this->coordinator = $coordinator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      MigrateEvents::POST_ROW_SAVE => [
        'postRowSave',
      ],
      MigrateEvents::POST_ROW_DELETE => [
        'postRowDelete',
      ],
    ];
  }

  /**
   * Interrupts batch post-row save if requested.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The Event to process.
   */
  public function postRowSave(MigratePostRowSaveEvent $event) {
    $this->interruptMigrateExecutable($event->getMigration());
  }

  /**
   * Interrupts batch post-row deletesif requested.
   *
   * @param \Drupal\migrate\Event\MigrateRowDeleteEvent $event
   *   The post-save event.
   */
  public function postRowDelete(MigrateRowDeleteEvent $event) {
    $this->interruptMigrateExecutable($event->getMigration());
  }

  /**
   * Interrupts the given migration plugin.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin to interrupt.
   *
   * @return bool
   *   TRUE if interruption was requested and performed, FALSE otherwise.
   */
  protected function interruptMigrateExecutable(MigrationPlugin $migration_plugin) {
    $this->state->resetCache();
    if ($this->state->get(self::KEY) === TRUE) {
      $this->state->delete(self::KEY);
      $migration_plugin->interruptMigration(MigrationPluginInterface::RESULT_STOPPED);
      // We must also release the lock since this interruption immediately.
      // @see \Drupal\acquia_migrate\Controller\HttpApi::migrationProcess()
      $this->coordinator->stopOperation();
      return TRUE;
    }
    return FALSE;
  }

}
