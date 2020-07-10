<?php

namespace Drupal\acquia_migrate\Batch;

/**
 * Thrown if a batch has already been created for a migration and is ongoing.
 *
 * @internal
 */
final class BatchConflictException extends \RuntimeException {}
