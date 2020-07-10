<?php

namespace Drupal\acquia_migrate\Exception;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Implements the AcquiaMigrateHttpExceptionInterface.
 *
 * {@internal}
 */
trait AcquiaMigrateHttpExceptionTrait {

  /**
   * A description of the identified problem that caused this exception.
   *
   * @var string
   */
  protected $problemDescription;

  /**
   * {@inheritdoc}
   */
  public function getHttpResponse(): Response {
    return JsonResponse::create($this->getErrorDocument(), $this->getStatusCode(), $this->getHeaders());
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getStatusCode(): int;

  /**
   * {@inheritdoc}
   */
  public function getHeaders(): array {
    return ['Content-Type' => 'application/vnd.api+json'];
  }

  /**
   * The default Acquia Migrate API response for this exception.
   *
   * @return array
   *   A JSON:API compliant error document.
   */
  protected function getErrorDocument(): array {
    return [
      'errors' => $this->getErrorObjects(),
    ];
  }

  /**
   * Gets a list of error objects.
   *
   * @return array
   *   An array of JSON:API compliant error objects.
   */
  protected function getErrorObjects(): array {
    return [$this->getErrorObject()];
  }

  /**
   * Gets a standard error object.
   *
   * @return array
   *   A JSON:API compliant error object.
   */
  protected function getErrorObject() {
    $error = [
      'code' => (string) $this->getStatusCode(),
      'status' => Response::$statusTexts[$this->getStatusCode()],
    ];
    if (isset($this->problemDescription)) {
      $error['detail'] = $this->problemDescription;
    }
    return $error;
  }

}
