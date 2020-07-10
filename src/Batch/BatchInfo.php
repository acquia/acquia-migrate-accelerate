<?php

namespace Drupal\acquia_migrate\Batch;

/**
 * Value object containing batch information.
 *
 * @internal
 */
abstract class BatchInfo {

  /**
   * The ID of the batch.
   *
   * @var int
   */
  protected $id;

  /**
   * BatchStatus constructor.
   *
   * @param int $id
   *   The batch ID for which this info is provided.
   */
  public function __construct(int $id) {
    $this->id = $id;
  }

  /**
   * Gets the batch ID for which this state object is given.
   *
   * @return int
   *   The batch ID.
   */
  public function getId(): int {
    return $this->id;
  }

}
