<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for acknowledging the need to protect this site if public.
 *
 * @internal
 */
final class PublicAcknowledgementForm extends FormBase {

  /**
   * The key to track whether the security notice has already been acknowledged.
   *
   * @see acquia_migrate_entity_field_access()
   * @see
   */
  const KEY = 'acquia_migrate.public_acknowledged';

  /**
   * Crucially, allow the security notice to be acknowledged, once.
   *
   * @see acquia_migrate_install()
   * @see ::submitForm()
   * @see ::hasBeenAcknowledged()
   */
  public static function reset() {
    if (\Drupal::keyValue('acquia_migrate')->has(static::KEY)) {
      return;
    }

    \Drupal::keyValue('acquia_migrate')->set(static::KEY, FALSE);
  }

  /**
   * Checks whether the security notice has been acknowledged by the user.
   *
   * @return bool
   *   TRUE when the security notice has been acknowledged, FALSE otherwise.
   *
   * @see ::submitForm()
   * @see ::reset()
   */
  public static function hasBeenAcknowledged() {
    return \Drupal::keyValue('acquia_migrate')->get(static::KEY, FALSE) === TRUE;
  }

  /**
   * Indicates whether this form (and its route) should be available or not.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   An access result.
   *
   * @see \Drupal\Core\Access\CustomAccessCheck
   */
  public static function isAvailable() {
    return AccessResult::allowedIf(!static::hasBeenAcknowledged())
      ->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_migrate_public_acknowledgement_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // @see ::reset()
    // @see ::hasBeenConfigured()
    // @codingStandardsIgnoreLine
    \Drupal::keyValue('acquia_migrate')->set(static::KEY, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $html = <<<HTML
<p>This environment was created specifically for migrating from Drupal 7 to
Drupal 9. It is an Acquia Cloud environment like any other, which means it's
publicly accessible.</p>
<p>That means it is easy for anybody in your organization who is given the URL
to help out with the migration — including people who are not developers.</p>
<p>Initially, there will not be any data in this Drupal 9 site. As you migrate
more data from Drupal 7 over, chances are there will be sensitive information in
there too. If any sensitive information is stored in your Drupal 7 site, then
please <a href="https://docs.acquia.com/cloud-platform/arch/security/nonprod/">
consider password-protecting this site</a>.</p>
HTML;
    $form['text'] = [
      '#markup' => $html,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('I understand'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

}
