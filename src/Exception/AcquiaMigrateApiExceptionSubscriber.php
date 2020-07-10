<?php

namespace Drupal\acquia_migrate\Exception;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Handles Acquia Migrate API exceptions.
 *
 * @internal
 */
final class AcquiaMigrateApiExceptionSubscriber extends HttpExceptionSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function onException(ExceptionEvent $event) {
    $throwable = $event->getThrowable();
    if ($throwable instanceof AcquiaMigrateHttpExceptionInterface) {
      $event->setResponse($throwable->getHttpResponse());
    }
    else {
      parent::onException($event);
    }
  }

}
