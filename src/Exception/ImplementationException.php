<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when an unexpected error occurs, indicating an implementation problem.
 *
 * @internal
 */
class ImplementationException extends \LogicException implements AcquiaMigrateHttpExceptionInterface {

  use AcquiaMigrateHttpExceptionTrait;

  /**
   * {@inheritdoc}
   */
  public function getStatusCode(): int {
    return 500;
  }

}
