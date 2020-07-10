<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown if the request's Accept header doesn't include a JSON:API media type.
 *
 * @internal
 */
final class NotAcceptableException extends BadRequestHttpException {

  /**
   * NotAcceptableException constructor.
   */
  public function __construct() {
    parent::__construct('The request must include the JSON:API media type in its Accept header.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusCode() {
    return 406;
  }

}
