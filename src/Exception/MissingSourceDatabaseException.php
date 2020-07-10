<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a migrate source database is not configured.
 *
 * @internal
 */
final class MissingSourceDatabaseException extends \RuntimeException implements AcquiaMigrateHttpExceptionInterface {

  use AcquiaMigrateHttpExceptionTrait;

  /**
   * InvalidFilterParameterException constructor.
   */
  public function __construct() {
    // Ensures a single, trailing period.
    $this->problemDescription = 'The migration source database has not been properly configured.';
    parent::__construct($this->problemDescription);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusCode(): int {
    return 500;
  }

}
