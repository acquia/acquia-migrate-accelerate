<?php

namespace Drupal\acquia_migrate\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks if the entity being modified is user 1.
 *
 * @Constraint(
 *   id = "UserOnePetrified",
 *   label = @Translation("User 1 petrified", context = "Validation"),
 * )
 */
class UserOnePetrifiedConstraint extends Constraint {

  /**
   * The message to use when the constraint is violated.
   *
   * @var string
   */
  public $petrifiedMessage = 'User 1 name & e-mail cannot be changed while using Acquia Migrate Accelerate.';

}
