<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when an atomic operation fails to process.
 *
 * @internal
 */
final class FailedAtomicOperationException extends \RuntimeException implements AcquiaMigrateHttpExceptionInterface {

  use AcquiaMigrateHttpExceptionTrait;

  /**
   * The failed operation's errors.
   *
   * @var array
   */
  protected $errors;

  /**
   * FailedAtomicOperationException constructor.
   *
   * @param int $operation_index
   *   The index of the atomic operation that failed in the `atomic:operations`
   *   member of a request document.
   * @param array $errors
   *   A list of error objects for the failed operation.
   */
  public function __construct(int $operation_index, array $errors) {
    parent::__construct('An atomic operation failed to process.');
    $this->errors = array_map(function (array $error) use ($operation_index) {
      return array_merge_recursive($error, [
        'source' => [
          'pointer' => "/atomic:operations/{$operation_index}",
        ],
      ]);
    }, $errors);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusCode(): int {
    $first_error_code = intval($this->errors[0]['code'] ?? 500);
    assert($first_error_code >= 400, 'The error codes should be recognizable as HTTP client or server error status codes.');
    return $first_error_code >= 400 ? $first_error_code : 500;
  }

}
