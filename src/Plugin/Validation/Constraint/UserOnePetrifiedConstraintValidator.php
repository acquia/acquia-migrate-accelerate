<?php

namespace Drupal\acquia_migrate\Plugin\Validation\Constraint;

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

    if ($entity->getEntityTypeId() === 'user' && $entity->id() == 1) {
      $this->context->addViolation($constraint->petrifiedMessage);
    }
  }

}
