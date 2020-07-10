<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a migration being looked up cannot be located.
 *
 * @internal
 */
final class MigrationNotFoundException extends \InvalidArgumentException implements AcquiaMigrateHttpExceptionInterface {

  use AcquiaMigrateHttpExceptionTrait;

  /**
   * {@inheritdoc}
   */
  public function getStatusCode(): int {
    return 404;
  }

}
