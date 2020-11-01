<?php

namespace Drupal\acquia_migrate\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;

/**
 * Loses all mails.
 *
 * @Mail(
 *   id = "terrible_mail_man",
 *   label = @Translation("Terrible Mail Man"),
 *   description = @Translation("World's most terrible mail man: loses all mails. Exactly what you want during migrations. After finishing the migration, please stop using the world's most terrible mail man.")
 * )
 */
class TerribleMailMan implements MailInterface {

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    return TRUE;
  }

}
