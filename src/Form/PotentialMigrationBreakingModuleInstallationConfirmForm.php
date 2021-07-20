<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Form;

use Drupal\acquia_migrate\Recommendations;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Render\Markup;
use Drupal\system\Form\ModulesListConfirmForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds a confirmation form for enabling potential migration-breaking modules.
 *
 * @internal
 */
final class PotentialMigrationBreakingModuleInstallationConfirmForm extends ModulesListConfirmForm {

  /**
   * The recommendations.
   *
   * @var \Drupal\acquia_migrate\Recommendations
   */
  protected $recommendations;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * Constructs a PotentialMigrationBreakingModuleInstallationConfirmForm.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   The module installer.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   * @param \Drupal\acquia_migrate\Recommendations $recommendations
   *   The recommendations.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, KeyValueStoreExpirableInterface $key_value_expirable, Recommendations $recommendations) {
    parent::__construct($module_handler, $module_installer, $key_value_expirable);
    $this->recommendations = $recommendations;
    $this->moduleExtensionList = \Drupal::service('extension.list.module');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('keyvalue.expirable')->get('module_list'),
      $container->get('acquia_migrate.recommendations'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you wish to enable unvetted  modules that may break migrations?');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_migrate_potential_migration_breaking_module_installation_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildMessageList() {
    $this->messenger()->addWarning($this->t('Modules with unvetted migration paths may break <em>Acquia Migrate Accelerate</em>. Use at your own risk.'));

    $items = parent::buildMessageList();

    $modules_to_be_installed = array_intersect_key($this->moduleExtensionList->getList(), $this->modules['install']);
    $vetted_destination_modules = $this->recommendations->getVettedDestinationModules();
    $unvetted_modules_to_be_installed = array_diff_key($modules_to_be_installed, array_combine($vetted_destination_modules, $vetted_destination_modules));

    // First generate a message for the modules that are being installed that
    // have been vetted.
    $vetted_modules_to_be_installed = array_diff_key($modules_to_be_installed, $unvetted_modules_to_be_installed);
    if (!empty($vetted_modules_to_be_installed)) {
      $human_module_names = array_intersect_key($this->modules['install'], $vetted_modules_to_be_installed);
      $args = ['@modules' => Markup::create(implode('</li><li>', $human_module_names))];
      $items[] = $this->t('✅ The following modules <strong>have been vetted</strong> in combination with <em>Acquia Migrate Accelerate</em>: <ul><li>@modules</li></ul>', $args);
    }

    // Analyze the risk of each of the unvetted modules.
    $unvetted_stable = array_filter($unvetted_modules_to_be_installed, [
      $this->recommendations,
      'moduleIsStable',
    ]);
    $unvetted_has_migrations = array_filter($unvetted_modules_to_be_installed, [
      $this->recommendations,
      'moduleHasMigrations',
    ]);
    $unvetted_alters_migrations = array_filter($unvetted_modules_to_be_installed, [
      $this->recommendations,
      'moduleAltersMigrations',
    ]);
    $unvetted_modules_to_be_installed_by_risk = [
      'NULL' => [],
      'low' => [],
      'medium' => [],
      'high' => [],
    ];
    foreach ($unvetted_modules_to_be_installed as $module_name => $module) {
      $risk = $this->recommendations::assessModuleInstallationMigrationBreakingRisk(
        FALSE,
        in_array($module_name, array_keys($unvetted_has_migrations), TRUE),
        in_array($module_name, array_keys($unvetted_alters_migrations), TRUE),
        in_array($module_name, array_keys($unvetted_stable), TRUE)
      );
      $unvetted_modules_to_be_installed_by_risk[$risk][] = $module_name;
    }

    // Second, generate a message for each observed risk level of unvetted
    // modules to be installed.
    foreach ($unvetted_modules_to_be_installed_by_risk as $risk => $module_names) {
      if (empty($module_names)) {
        continue;
      }
      $human_module_names = array_intersect_key($this->modules['install'], array_combine($module_names, $module_names));
      $args = ['@modules' => Markup::create(implode('</li><li>', $human_module_names))];
      switch ($risk) {
        case 'high':
          $items[] = $this->t('⚠️⚠️⚠️ The following modules have not yet been vetted and have a <strong>high risk</strong> to break <em>Acquia Migrate Accelerate</em> because they alter migrations: <ul><li>@modules</li></ul>', $args);
          break;

        case 'medium':
          $items[] = $this->t('⚠️⚠️ The following modules have not yet been vetted and have a <strong>medium risk</strong> to break <em>Acquia Migrate Accelerate</em> because they have migrations: <ul><li>@modules</li></ul>', $args);
          break;

        case 'low':
          $items[] = $this->t('⚠️ The following modules have not yet been vetted, but have only a <strong>low risk</strong> to break <em>Acquia Migrate Accelerate</em> because they do not have or alter migrations, but they are <strong>unstable</strong>: <ul><li>@modules</li></ul>', $args);
          break;

        default:
          $items[] = $this->t('✅ The following modules have not yet been vetted but are <strong>very unlikely to break</strong> <em>Acquia Migrate Accelerate</em>: <ul><li>@modules</li></ul>.', $args);
          break;
      }
    }

    return $items;
  }

}
