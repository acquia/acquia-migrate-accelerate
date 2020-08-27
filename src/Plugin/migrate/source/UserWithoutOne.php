<?php

namespace Drupal\acquia_migrate\Plugin\migrate\source;

use Drupal\user\Plugin\migrate\source\d7\User;

/**
 * Drupal 7 user source from database; without user 1.
 *
 * @MigrateSource(
 *   id = "d7_user_without_one",
 *   source_module = "user"
 * )
 *
 * @see acquia_migrate_migration_plugins_alter()
 */
class UserWithoutOne extends User {

  /**
   * {@inheritdoc}
   */
  public function query() {
    return parent::query()->condition('u.uid', 1, '>');
  }

}
