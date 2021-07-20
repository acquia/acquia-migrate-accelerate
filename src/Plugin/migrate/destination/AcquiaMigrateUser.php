<?php

namespace Drupal\acquia_migrate\Plugin\migrate\destination;

use Drupal\migrate\Row;
use Drupal\user\Entity\User;
use Drupal\user\Plugin\migrate\destination\EntityUser;

/**
 * Replacement destination plugin for "entity:user".
 *
 * Excludes 'name', 'mail' and 'init' (the initial email) from the migrated
 * properties for user 1.
 *
 * @see acquia_migrate_entity_base_field_info_alter()
 * @see \Drupal\acquia_migrate\Plugin\Validation\Constraint\UserOnePetrifiedConstraintValidator
 */
class AcquiaMigrateUser extends EntityUser {

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    if ($row->getDestinationProperty('uid') == 1) {
      $user_one = User::load(1);
      assert($user_one !== NULL);

      // If this is a stub, and user one already exists, let's return its IDs
      // without trying to update the entity.
      if ($row->isStub()) {
        $entity = $this->getEntity($row, $old_destination_id_values);
        assert(!$entity->isNew());
        // The d7_user_entity_translation migration defines a second source ID:
        // the language ID.
        if ($this->isTranslationDestination()) {
          return ["1", $entity->language()->getId()];
        }
        return ["1"];
      }

      // This is an actual migration for user one. The parent class ensures that
      // the root account password remains unchanged. But we do need to keep
      // also the original name, email (and initial email) as well.
      $row->setDestinationProperty('name', $user_one->get('name')->value);
      $row->setDestinationProperty('mail', $user_one->get('mail')->value);
      $row->setDestinationProperty('init', $user_one->get('init')->value);
      // User 1 must never be blocked.
      $row->setDestinationProperty('status', 1);
    }

    return parent::import($row, $old_destination_id_values);
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $user_id = (int) reset($destination_identifier);
    // Explicitly prevent the deletion of user "1" and "0".
    if ($user_id < 2 && !$this->isTranslationDestination()) {
      return;
    }
    parent::rollback($destination_identifier);
  }

}
