<?php

namespace Drupal\acquia_migrate\Plugin\Validation\Constraint;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\user\Entity\User;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UserOnePetrified constraint.
 */
class UserOnePetrifiedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($items, Constraint $constraint) {
    if (!isset($items) || !$items->value) {
      return;
    }

    $entity = $items->getParent()->getEntity();

    // This only validates user one modifications made through somewhere else
    // than a form (for example: a migration).
    if ($entity->getEntityTypeId() === 'user' && $entity->id() == 1 && !$entity->isNew() && !static::isValidatingInFormContext()) {
      $field_name = $items->getName();
      $original_user_one = User::load(1);
      $original_value = $original_user_one->get($field_name)->value;
      $new_value = $items->value;
      if ($original_value !== $new_value) {
        $this->context->addViolation($constraint->petrifiedMessage);
      }
    }
  }

  /**
   * Whether this is validating in a user account form context.
   *
   * Note:
   * - \Drupal\acquia_migrate\Form\UserOneConfigurationForm subclasses
   *   \Drupal\user\AccountForm
   * - \Drupal\user\AccountForm subclasses ContentEntityForm.
   *
   * @return bool
   *   TRUE when any content entity form is in the call stack.
   */
  private static function isValidatingInFormContext() {
    return in_array(ContentEntityForm::class, array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'class'), TRUE);
  }

}
