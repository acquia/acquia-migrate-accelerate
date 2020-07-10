<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a row could not be previewed.
 *
 * This could be for a number of reasons, for example, if the migration's
 * dependencies have not been met. The specific reason should be given via the
 * constructor's problem description.
 *
 * @internal
 */
class RowPreviewException extends \InvalidArgumentException implements AcquiaMigrateHttpExceptionInterface {

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
  public function getStatusCode(): int {
    return 400;
  }

}
