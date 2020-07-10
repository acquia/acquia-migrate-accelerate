<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a request filter is invalid.
 *
 * @internal
 */
final class InvalidFilterParameterException extends BadRequestHttpException {

  /**
   * InvalidFilterParameterException constructor.
   *
   * @param string $problem_description
   *   A description of the invalidity.
   * @param string $filter_parameter_value
   *   The invalid filter.
   */
  public function __construct(string $problem_description, string $filter_parameter_value) {
    // Calling rtrim() ensures a single, trailing period after the problem
    // description.
    parent::__construct(rtrim($problem_description, '.') . '. ' . "Invalid filter parameter received in the request URL's query string as: `filter={$filter_parameter_value}`.");
  }

}
