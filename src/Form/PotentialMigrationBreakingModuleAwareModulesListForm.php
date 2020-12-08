<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Form;

use Drupal\acquia_migrate\Recommendations;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\system\Form\ModulesListForm;
use Drupal\user\PermissionHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a confirmation form when enabling potential migration-breaking modules.
 *
 * @internal
 */
final class PotentialMigrationBreakingModuleAwareModulesListForm extends ModulesListForm {

  /**
   * The recommendations.
   *
   * @var \Drupal\acquia_migrate\Recommendations
   */
  protected $recommendations;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('keyvalue.expirable')->get('module_list'),
      $container->get('access_manager'),
      $container->get('current_user'),
      $container->get('user.permissions'),
      $container->get('extension.list.module'),
      $container->get('acquia_migrate.recommendations')
    );
  }

  /**
   * Constructs a ModulesListForm object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   Access manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\user\PermissionHandlerInterface $permission_handler
   *   The permission handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $extension_list_module
   *   The module extension list.
   * @param \Drupal\acquia_migrate\Recommendations $recommendations
   *   The recommendations.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, KeyValueStoreExpirableInterface $key_value_expirable, AccessManagerInterface $access_manager, AccountInterface $current_user, PermissionHandlerInterface $permission_handler, ModuleExtensionList $extension_list_module, Recommendations $recommendations) {
    parent::__construct($module_handler, $module_installer, $key_value_expirable, $access_manager, $current_user, $permission_handler, $extension_list_module);
    $this->recommendations = $recommendations;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve a list of modules to install and their dependencies.
    $modules = $this->buildModuleList($form_state);

    $vetted_destination_modules = $this->recommendations->getVettedDestinationModules();
    $unvetted_modules_to_be_installed = array_diff(array_keys($modules['install']), $vetted_destination_modules);
    if (!empty($unvetted_modules_to_be_installed)) {
      // Write the list of changed module states into a key value store.
      $account = $this->currentUser()->id();
      $this->keyValueExpirable->setWithExpire($account, $modules, 60);

      // Redirect to the confirmation form.
      $form_state->setRedirect('system.modules_list_migration_breaking_confirm');

      // We can exit here because at least one module has dependencies
      // which we have to prompt the user for in a confirmation form.
      return;
    }

    return parent::submitForm($form, $form_state);
  }

}
