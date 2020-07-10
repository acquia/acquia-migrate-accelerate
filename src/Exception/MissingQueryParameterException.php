<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a required query parameter was not requested.
 *
 * @internal
 */
final class MissingQueryParameterException extends BadRequestHttpException {

  /**
   * MissingQueryParameterException constructor.
   *
   * @param string $query_parameter_name
   *   The missing query parameter's name.
   */
  public function __construct(string $query_parameter_name) {
    parent::__construct('The `' . $query_parameter_name . '` query parameter is required.');
  }

}
