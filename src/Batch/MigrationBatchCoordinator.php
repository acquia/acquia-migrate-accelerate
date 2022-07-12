<?php

declare(strict_types = 1);

namespace Drupal\acquia_migrate\Batch;

use Drupal\Core\Http\RequestStack;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;

/**
 * Coordinates migration batches: only one session at a time can run them.
 *
 * Concurrent migration batches are not allowed: the migration system assumes
 * sequential execution. It lacks the metadata to safely execute migrations in
 * parallel.
 *
 * Furthermore, only the session that starts a migration can also pause/stop it.
 *
 * @internal
 */
final class MigrationBatchCoordinator {

  /**
   * The name used to identify the lock that ensures only a single active batch.
   *
   * @const string
   */
  const ACTIVE_BATCH = 'acquia_migrate__active_batch';

  /**
   * State key for tracking the session ID of the session currently in control.
   *
   * @const string
   */
  const ACTIVE_BATCH_SESSION = 'acquia_migrate.active_batch_session';

  /**
   * The persistent lock which is used to lock across requests.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $persistentLock;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The session ID for this request.
   *
   * @var string
   */
  protected $sessionId = NULL;

  /**
   * The controlling session ID state at service construction time.
   *
   * @var mixed
   */
  protected $controllingSessionId = NULL;

  /**
   * Bitfield to reflect the batch status at the beginning of this request.
   *
   * Bits:
   * - First bit: 0b01 for active batch, 0b00 for no active batch.
   * - Second bit: 0b10 for batch owned by current session, 0b00 otherwise.
   *
   * Interpretation:
   * - 0b00: a batch can be started by anyone
   * - 0b01: an active batch by another session; no batch can be started
   * - 0b11: an active batch by the current session, meaning it can be
   *   stopped, extended, et cetera.
   * - 0b10 is nonsensical (no active batch, but owned by current session)
   *
   * @var int
   */
  protected $batchState = NULL;

  /**
   * MigrationBatchCoordinator constructor.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $persistent_lock
   *   A persistent lock backend instance.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Http\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(LockBackendInterface $persistent_lock, StateInterface $state, RequestStack $request_stack) {
    $this->persistentLock = $persistent_lock;
    $this->state = $state;

    // Compute the batch state.
    $has_active_batch = !$persistent_lock->lockMayBeAvailable(static::ACTIVE_BATCH);
    $request = $request_stack->getCurrentRequest();
    $this->sessionId = $request->hasSession() ? $request->getSession()->getId() : 'drush';
    $this->controllingSessionId = $this->state->get(static::ACTIVE_BATCH_SESSION, '___NO_ACTIVE_BATCH_SESSION___');
    $active_batch_owned_by_current_session = $this->controllingSessionId === $this->sessionId;
    $this->batchState =
      (int) $active_batch_owned_by_current_session << 1
      |
      (int) $has_active_batch << 0;
  }

  /**
   * Checks whether the nth bit of $this->batchState is set.
   *
   * @param int $bit_number
   *   Which bit to check.
   *
   * @return bool
   *   Whether the nth bit is set.
   */
  private function batchStateBitIsSet(int $bit_number): bool {
    $check = $this->batchState >> ($bit_number - 1);
    // 1 means the bit is set, otherwise it is not set.
    return (bool) ($check & 1);
  }

  /**
   * Whether the coordinator knows of an active migration operation.
   *
   * @return bool
   *   Whether the coordinator knows of an active migration operation.
   */
  public function hasActiveOperation(): bool {
    // The first bit must be set.
    return $this->batchStateBitIsSet(1);
  }

  /**
   * Whether this PHP process can modify the active migration operation.
   *
   * (This implies the session associated with this PHP process is the
   * controlling session.)
   *
   * @return bool
   *   Whether this PHP process can modify the active migration operation.
   */
  public function canModifyActiveOperation(): bool {
    // The second bit must be set.
    return $this->batchStateBitIsSet(2);
  }

  /**
   * Starts a migration operation.
   *
   * @return bool
   *   TRUE if successful. FALSE if not — somebody else won the race.
   */
  public function startOperation(): bool {
    if ($this->hasActiveOperation()) {
      throw new \LogicException('An operation can only be started if none is active.');
    }

    $success = $this->persistentLock->acquire(static::ACTIVE_BATCH, min(30, ini_get('max_execution_time')));

    if ($success) {
      // Track which session triggered the active batch.
      $this->state->set(static::ACTIVE_BATCH_SESSION, $this->sessionId);

      // Update to reflect the current batch state instantaneously.
      $this->controllingSessionId = $this->sessionId;
      $this->batchState = 0b11;
    }

    // A race condition is possible, so the caller must check whether this was
    // successful.
    return $success;
  }

  /**
   * Extends the active migration operation.
   *
   * @return bool
   *   TRUE if successful. FALSE if not — likely because the lock was released
   *   because it was not extended in time.
   */
  public function extendActiveOperation(int $seconds = NULL): bool {
    // If this request was fired at a time where the batch was still active (not
    // yet interrupted), fail gracefully.
    // @see \Drupal\acquia_migrate\EventSubscriber\InstantaneousBatchInterruptor
    if (!$this->hasActiveOperation()) {
      return FALSE;
    }

    if (!$this->canModifyActiveOperation()) {
      throw new \LogicException('An operation can only be extended by the session that created it. Current session ID: `' . $this->sessionId . '`, controlling session ID: `' . $this->controllingSessionId . '`.');
    }

    $success = $this->persistentLock->acquire(static::ACTIVE_BATCH, $seconds ?? ini_get('max_execution_time'));

    // Extending an operation should always succeed. Still, race conditions are
    // not impossible (for example, a slow network), so the caller must still
    // check whether this was successful.
    return $success;
  }

  /**
   * Stops a migration operation.
   */
  public function stopOperation(): void {
    if (!$this->hasActiveOperation()) {
      throw new \LogicException('An operation can only be stopped if one is active.');
    }
    if (!$this->canModifyActiveOperation()) {
      throw new \LogicException('An operation can only be stopped by the session that created it.');
    }

    $this->persistentLock->release(static::ACTIVE_BATCH);

    // Delete the session metadata associated with the operation.
    $this->state->delete(static::ACTIVE_BATCH_SESSION);

    // Update to reflect the current batch state instantaneously.
    $this->batchState = 0b00;
  }

  /**
   * Whether the controlling session is known.
   *
   * Should always be true, may not be true if the system is an inconsistent
   * state — or if we hit a race condition.
   *
   * @return bool
   *   Whether the controlling session is known.
   */
  public function controllingSessionIsKnown(): bool {
    return $this->controllingSessionId !== NULL;
  }

  /**
   * Whether the controlling session is drush.
   *
   * @return bool
   *   Whether the controlling session is drush.
   */
  public function controllingSessionIsDrush(): bool {
    return $this->controllingSessionIsKnown() && $this->controllingSessionId === 'drush';
  }

}
