<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a requested query parameter is not allowed.
 *
 * @internal
 */
final class QueryParameterNotAllowedException extends BadRequestHttpException {

  /**
   * The disallowed query parameter name.
   *
   * @var string
   */
  protected $queryParameterName;

  /**
   * QueryParameterNotAllowedException constructor.
   *
   * @param string $query_parameter_name
   *   The disallowed query parameter name.
   */
  public function __construct(string $query_parameter_name) {
    parent::__construct('The `' . $query_parameter_name . '` query parameter is not allowed.');
    $this->queryParameterName = $query_parameter_name;
  }

  /**
   * {@inheritdoc}
   */
  protected function getErrorObject(): array {
    $source = ['source' => ['parameter' => $this->queryParameterName]];
    return parent::getErrorObject() + $source;
  }

}
