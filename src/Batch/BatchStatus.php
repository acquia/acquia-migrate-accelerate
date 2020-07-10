<?php

namespace Drupal\acquia_migrate\Batch;

/**
 * Value object containing batch state information.
 *
 * @internal
 */
final class BatchStatus extends BatchInfo {

  /**
   * The last known progress ratio, p, given as a float where 0 <= p <= 1.
   *
   * @var float
   *   The progress ratio.
   */
  protected $progress;

  /**
   * BatchStatus constructor.
   *
   * @param int $id
   *   The batch ID for which this info is provided.
   * @param float $progress
   *   A float greater than or equal to 0 and less than or equal to 1.
   */
  public function __construct(int $id, float $progress) {
    parent::__construct($id);
    $this->progress = $progress;
  }

  /**
   * Gets the progress of the batch.
   *
   * @return float
   *   The progress ratio, given as a float greater than or equal to 0 and less
   *   than or equal to 1.
   */
  public function getProgress(): float {
    return $this->progress;
  }

}
