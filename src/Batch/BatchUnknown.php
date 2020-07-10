<?php

namespace Drupal\acquia_migrate\Batch;

/**
 * Value object given when batch information is retrieved for an unknown batch.
 *
 * @internal
 */
final class BatchUnknown extends BatchInfo {

  /**
   * BatchUnknown constructor.
   */
  public function __construct() {
    parent::__construct(-1);
  }

  /**
   * This method should not be called, an unknown batch does not have an ID.
   *
   * @throws \LogicException
   *   Always thrown.
   */
  public function getId(): int {
    throw new \LogicException('This method should not be called, an unknown batch does not have an ID.');
  }

}
