<?php

namespace Drupal\acquia_migrate\Exception;

/**
 * Thrown when a row selected for preview could not be located.
 *
 * @internal
 */
final class RowNotFoundException extends RowPreviewException {

  /**
   * {@inheritdoc}
   */
  public function getStatusCode(): int {
    return 404;
  }

}
