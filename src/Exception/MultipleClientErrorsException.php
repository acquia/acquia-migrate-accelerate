<?php

namespace Drupal\acquia_migrate\Exception;

use Drupal\Component\Assertion\Inspector;

/**
 * When multiple exceptions are encountered, they can be rolled into this one.
 *
 * @internal
 */
final class MultipleClientErrorsException extends BadRequestHttpException implements AcquiaMigrateHttpExceptionInterface {

  /**
   * The underlying exceptions.
   *
   * @var \Drupal\acquia_migrate\Exception\BadRequestHttpException[]
   */
  protected $exceptions;

  /**
   * MultipleClientErrorsException constructor.
   *
   * @param \Drupal\acquia_migrate\Exception\BadRequestHttpException[] $exceptions
   *   The multiple client error exceptions.
   */
  public function __construct(array $exceptions) {
    Inspector::assertAllObjects($exceptions, BadRequestHttpException::class);
    $this->exceptions = $exceptions;
    parent::__construct('Multiple client errors.');
  }

  /**
   * {@inheritdoc}
   */
  protected function getErrorObjects(): array {
    return array_map(function (BadRequestHttpException $exception) {
      return $exception->getErrorObject();
    }, $this->exceptions);
  }

}
