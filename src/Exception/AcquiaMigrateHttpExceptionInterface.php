<?php

namespace Drupal\acquia_migrate\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Interface for exceptions thrown by the Acquia Migrate API.
 *
 * @internal
 */
interface AcquiaMigrateHttpExceptionInterface extends HttpExceptionInterface {

  /**
   * Gets a JSON:API compliant response object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The intended response for this exception.
   */
  public function getHttpResponse(): Response;

}
