<?php

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\acquia_migrate\MacGyver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registers the sourcination database connection with Drupal.
 *
 * Note: for Drush, this is registered in the AMA commands.
 *
 * @see \Drupal\acquia_migrate\Commands\AcquiaMigrateCommands::__construct()
 *
 * @internal
 */
class SourcinationRegisterer implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];

    // Run as early as possible.
    $events[KernelEvents::REQUEST][] = ['onRequest', 1000];

    return $events;
  }

  /**
   * Registers the sourcination database connection, if any.
   */
  public function onRequest() {
    if (MacGyver::isArmed()) {
      MacGyver::getSourceination();
    }
  }

}
