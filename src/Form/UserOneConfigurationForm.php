<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\AccountForm;

/**
 * Form controller for configuring user 1 within Acquia Migrate Accelerate.
 *
 * Allows configuring user name, password and e-mail.
 *
 * Also gathers additional information:
 * - source site base URL
 *
 * But none of this additional information affects the hasBeenConfigured() flag.
 * Although this indeed mixes independent concerns, this was done to avoid
 * introducing too many separate steps during product onboarding.
 *
 * @internal
 */
final class UserOneConfigurationForm extends AccountForm {

  /**
   * The custom entity operation to add to the User entity type.
   *
   * (This ensures we can reuse the existing `_entity_form` infrastructure.)
   *
   * @see \acquia_migrate_entity_type_alter
   */
  const OPERATION = 'acquia_migrate_configure_user_one';

  /**
   * The key to track whether user 1 has already been configured.
   *
   * @see acquia_migrate_entity_field_access()
   * @see
   */
  const KEY = 'acquia_migrate.user_one_configured';

  /**
   * The key to the source site's base URL.
   *
   * @see acquia_migrate_form_alter()
   */
  const KEY_ADDITIONAL__SOURCE_SITE_BASE_URL = 'acquia_migrate.source_site_base_url';

  /**
   * The form display components this form is showing.
   *
   * This omits additional components that are being added by other modules,
   * such as the contact or metatag module.
   */
  const ALLOWED_FORM_DISPLAY_COMPONENTS = [
    'account',
  ];

  /**
   * The fields of user 1 that we're forcefully allowing to be edited.
   *
   * @see acquia_migrate_entity_field_access()
   * @see \Drupal\user\Entity\User::baseFieldDefinitions()
   */
  const ALLOWED_FIELDS = [
    'name',
    'pass',
    'mail',
  ];

  /**
   * Crucially, allow user 1 to be configured, once.
   *
   * @see acquia_migrate_install()
   * @see ::submitForm()
   * @see ::hasBeenConfigured()
   */
  public static function reset() {
    if (\Drupal::keyValue('acquia_migrate')->has(UserOneConfigurationForm::KEY)) {
      return;
    }

    \Drupal::keyValue('acquia_migrate')->set(UserOneConfigurationForm::KEY, FALSE);
  }

  /**
   * Checks whether user 1 has been configured by the user.
   *
   * @return bool
   *   TRUE when user 1 has been configured, FALSE otherwise.
   *
   * @see ::submitForm()
   * @see ::reset()
   */
  public static function hasBeenConfigured() {
    return \Drupal::keyValue('acquia_migrate')->get(UserOneConfigurationForm::KEY, FALSE) === TRUE;
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
    return AccessResult::allowedIf(!static::hasBeenConfigured())
      ->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\Entity\EntityForm::getEntityFromRouteMatch
   */
  public function getEntityFromRouteMatch(RouteMatchInterface $route_match, $entity_type_id) {
    // Rather than creating a User entity out of thin air, this form is always
    // about user 1.
    $user_one = $this->entityTypeManager->getStorage('user')->load(1);
    // Unset the values for the fields they're allowed to modify, to ensure it
    // feels exactly like setting up a new site for the first time for the end
    // user.
    foreach (static::ALLOWED_FIELDS as $field_name) {
      $user_one->$field_name->value = NULL;
    }
    return $user_one;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormDisplay(FormStateInterface $form_state) {
    $form_display = parent::getFormDisplay($form_state);

    $generated_form_display = $form_display->createCopy(static::OPERATION);
    foreach (array_keys($generated_form_display->getComponents()) as $name) {
      if (!in_array($name, static::ALLOWED_FORM_DISPLAY_COMPONENTS, TRUE)) {
        $generated_form_display->removeComponent($name);
      }
    }

    return $generated_form_display;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    // Display the sole allowed form display component as a fieldset. This is a
    // purely cosmetic change; the generated `name` attributes remain identical.
    assert(count(self::ALLOWED_FORM_DISPLAY_COMPONENTS) === 1);
    $form['account']['#type'] = 'fieldset';
    $form['account']['#title'] = $this->t('Configure user one');

    // Additional information to gather:
    // - source site base URL.
    $form['source_site_info'] = [
      '#type'   => 'fieldset',
      '#title' => 'Source site information',
      '#tree' => TRUE,
      '#weight' => -100,
    ];
    $form['source_site_info']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('Knowing the base URL of the source site will allow Acquia Migrate Accelerate to point back to the domain when relevant, for example when it finds data consistency issues.'),
      '#required' => TRUE,
      '#placeholder' => 'https://example.com',
      '#pattern' => 'https?://.*\..*',
      '#attributes' => [
        'autocorrect' => 'off',
        'autocapitalize' => 'off',
        'spellcheck' => 'false',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $source_site_info = rtrim($form_state->getValue([
      'source_site_info',
      'base_url',
    ]), '/');
    if ($error_message = $this->validateSourceSiteInfoBaseUrl($source_site_info)) {
      $form_state->setErrorByName('source_site_info][base_url', $error_message);
    }
  }

  /**
   * Validates the source site info base URL.
   *
   * @param string $form_value
   *   The value entered in the form, to be validated.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The validation error message for the source site base URL form element,
   *   or NULl if it is valid.
   */
  private function validateSourceSiteInfoBaseUrl(string $form_value) : ?TranslatableMarkup {
    $required_components = ['scheme', 'host'];
    $components = parse_url($form_value);
    if ($components === FALSE) {
      return $this->t('Invalid URL provided. Please enter a URL such as <code>https://example.com</code>.');
    }
    if ($required_components != array_keys($components)) {
      return $this->t('Please enter a URL containing only scheme and host, such as <code>https://example.com</code>.');
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // @see \Drupal\acquia_migrate\Form\UserOneConfigurationForm::reset()
    // @see \Drupal\acquia_migrate\Form\UserOneConfigurationForm::hasBeenConfigured()
    \Drupal::keyValue('acquia_migrate')->set(UserOneConfigurationForm::KEY, TRUE);
    // @see acquia_migrate_form_alter()
    \Drupal::keyValue('acquia_migrate')->set(UserOneConfigurationForm::KEY_ADDITIONAL__SOURCE_SITE_BASE_URL, rtrim($form_state->getValue([
      'source_site_info',
      'base_url',
    ]), '/'));
  }

}
