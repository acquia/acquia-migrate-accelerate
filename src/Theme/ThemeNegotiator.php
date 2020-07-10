<?php

namespace Drupal\acquia_migrate\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Ensures the Claro theme is used for all Acquia Migrate: Accelerate routes.
 */
final class ThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    return strpos($route_match->getRouteName(), 'acquia_migrate') === 0;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    return 'claro';
  }

}
