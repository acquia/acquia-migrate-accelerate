<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\shortcut\Plugin\migrate\destination\ShortcutSetUsers;
use Drupal\user\Entity\User;

/**
 * Rollbackable user shortcut set destination plugin.
 *
 * @see \Drupal\shortcut\Plugin\migrate\destination\ShortcutSetUsers
 *
 * @internal
 *
 * @MigrateDestination(
 *   id = "rollbackable_shortcut_set_users"
 * )
 */
final class RollbackableShortcutSetUsers extends ShortcutSetUsers {

  /**
   * {@inheritdoc}
   */
  protected $supportsRollback = TRUE;

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    /** @var \Drupal\user\UserInterface $account */
    $account = User::load($destination_identifier['uid']);

    if ($destination_identifier['set_name'] === $this->shortcutSetStorage->getAssignedToUser($account)) {
      $this->shortcutSetStorage->unassignUser($account);
    }
  }

}
