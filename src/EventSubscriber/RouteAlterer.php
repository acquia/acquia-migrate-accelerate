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
   * Alters the 'acquia_migrate.*' routes.
   *
   * @param \Drupal\Core\Routing\RouteBuildEvent $event
   *   The event to process.
   */
  public function alterAcquiaMigrateRoutes(RouteBuildEvent $event) : void {
    foreach ($event->getRouteCollection()->getIterator() as $route_name => $route) {
      if (strpos($route_name, 'acquia_migrate.') === 0) {
        // Prevent the contributed `redirect` module from interfering.
        $route->setDefault('_disable_route_normalizer', TRUE);
        // Prevent language negotiation path prefixes from being added to our
        // routes.
        $route->setOption('default_url_options', ['path_processing' => FALSE]);
      }
    }
  }

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
    $events[RoutingEvents::ALTER][] = ['alterAcquiaMigrateRoutes'];
    $events[RoutingEvents::ALTER][] = ['alterModuleInstallationRoutes'];
    return $events;
  }

}
