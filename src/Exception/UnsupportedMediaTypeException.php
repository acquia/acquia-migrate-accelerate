<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown if the request does not use the JSON:API media type.
 *
 * @internal
 */
class UnsupportedMediaTypeException extends BadRequestHttpException {

  /**
   * UnsupportedMediaTypeException constructor.
   *
   * @param string $problem_description
   *   Optional. A description of the problem with the content's media type.
   */
  public function __construct(string $problem_description = NULL) {
    parent::__construct($problem_description ?: 'The request must use the JSON:API media type.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusCode() {
    return 415;
  }

}
