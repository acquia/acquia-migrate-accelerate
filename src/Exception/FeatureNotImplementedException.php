<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown if a particular feature has not yet been implemented.
 *
 * @internal
 */
class FeatureNotImplementedException extends ImplementationException {

  /**
   * FeatureNotImplementedException constructor.
   *
   * @param string $problem_description
   *   A description of the invalidity.
   */
  public function __construct(string $problem_description) {
    parent::__construct($problem_description);
    // Ensures a single, trailing period.
    $this->problemDescription = rtrim($problem_description, '.') . '.';
  }

}
