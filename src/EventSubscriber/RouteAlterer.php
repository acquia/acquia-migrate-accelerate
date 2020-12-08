<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\acquia_migrate\Form\PotentialMigrationBreakingModuleAwareModulesListForm;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Alters routes.
 */
final class RouteAlterer implements EventSubscriberInterface {

  /**
   * Alters the 'system.modules_list' route.
   *
   * @param \Drupal\Core\Routing\RouteBuildEvent $event
   *   The event to process.
   *
   * @see \Drupal\system\Form\ModulesListForm::submitForm()
   * @see \Drupal\system\Form\ModulesListConfirmForm
   * @see \Drupal\acquia_migrate\Form\PotentialMigrationBreakingModuleInstallationConfirmForm
   */
  public function alterModuleInstallationRoutes(RouteBuildEvent $event) : void {
    $route = $event->getRouteCollection()->get('system.modules_list');
    if ($route) {
      $route->setDefault('_form', PotentialMigrationBreakingModuleAwareModulesListForm::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[RoutingEvents::ALTER][] = ['alterModuleInstallationRoutes'];
    return $events;
  }

}
