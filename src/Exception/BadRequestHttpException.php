<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when the request is invalid.
 *
 * @internal
 */
class BadRequestHttpException extends \RuntimeException implements AcquiaMigrateHttpExceptionInterface {

  use AcquiaMigrateHttpExceptionTrait;

  /**
   * InvalidFilterParameterException constructor.
   *
   * @param string $problem_description
   *   A description of the invalidity.
   */
  public function __construct(string $problem_description) {
    parent::__construct($problem_description);
    // Ensures a single, trailing period.
    $this->problemDescription = rtrim($problem_description, '.') . '.';
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusCode() {
    return 400;
  }

}
