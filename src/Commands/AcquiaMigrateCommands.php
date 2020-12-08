<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\acquia_migrate\MigrationRepository;
use Drupal\acquia_migrate\Recommendations;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Acquia Migrate drush commands.
 */
final class AcquiaMigrateCommands extends DrushCommands {

  /**
   * The migration repository.
   *
   * @var \Drupal\acquia_migrate\MigrationRepository
   */
  protected $migrationRepository;

  /**
   * Key-value store service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The Acquia Migrate: Accelerate recommendations.
   *
   * @var \Drupal\acquia_migrate\Recommendations
   */
  protected $recommendations;

  /**
   * AcquiaMigrateCommands constructor.
   *
   * @param \Drupal\acquia_migrate\MigrationRepository $migration_repository
   *   The migration repository.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value
   *   Key-value store service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\acquia_migrate\Recommendations $recommendations
   *   The Acquia Migrate: Accelerate recommendations.
   */
  public function __construct(MigrationRepository $migration_repository, KeyValueFactoryInterface $key_value, ModuleExtensionList $module_extension_list, Recommendations $recommendations) {
    parent::__construct();
    $this->migrationRepository = $migration_repository;
    $this->keyValue = $key_value;
    $this->moduleExtensionList = $module_extension_list;
    $this->recommendations = $recommendations;
  }

  /**
   * Audits installed modules.
   *
   * @command ama:module-audit
   * @filter-output
   *
   * @option installed Installed modules only. Default.
   * @option risky
   *
   * @default $options []
   *
   * @usage ama:module-audit
   *   Audits all installed modules.
   * @usage ama:module-audit --risky-only
   *   Returns only the risky modules.
   * @usage ama:module-audit --filter="risk!="
   *   Alias of --risky-only
   * @usage ama:module-audit --filter="risk~=/medium|high/"
   *   Returns only medium and high risk modules.
   * @usage ama:module-audit --installed=0
   *   Returns only modules that are not installed.
   * @usage ama:module-audit --filter="installed!="
   *   Alias of --installed=0.
   *
   * @validate-module-enabled acquia_migrate
   *
   * @aliases amama, ama-module-audit
   *
   * @field-labels
   *   module: Module
   *   vetted: Vetted
   *   stable: Stable
   *   has_migrations: Has Migrations
   *   alters_migrations: Alters Migrations
   *   risk: Risk
   *   installed: Installed
   *   installation_time: Installation time
   * @default-fields module,vetted,stable,has_migrations,alters_migrations,risk,installed,installation_time
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Migrations status formatted as table.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function auditModules($options = [
    'installed' => TRUE,
    'risky' => self::OPT,
  ]) : RowsOfFields {
    $table = [];

    $vetted = $this->recommendations->getVettedDestinationModules();

    foreach ($this->moduleExtensionList->getList() as $module) {
      // Always ignore test-only modules.
      if ($module->info['package'] === 'Testing') {
        continue;
      }

      $installed = $module->status ? 'yes' : FALSE;
      if ($options['installed'] && !$installed) {
        continue;
      }

      // This assumes the recommended version of the vetted module is used.
      $is_vetted = in_array($module->getName(), $vetted);
      $has_migrations = $this->recommendations::moduleHasMigrations($module);
      $alters_migrations = $this->recommendations::moduleAltersMigrations($module);
      $is_stable = $this->recommendations::moduleIsStable($module);
      $risk = $this->recommendations::assessModuleInstallationMigrationBreakingRisk($is_vetted, $has_migrations, $alters_migrations, $is_stable);

      if ($options['risky'] && $risk === NULL) {
        continue;
      }

      $key = "module_install_timestamp:" . $module->getName();
      $installation_time = $this->keyValue->get('acquia_migrate')->get($key, NULL);
      $row = [
        'module' => $module->getName(),
        'installed' => $installed,
        'vetted' => $is_vetted ? 'yes' : FALSE,
        'stable' => $is_stable ? 'yes' : FALSE,
        'has_migrations' => $has_migrations ? 'yes' : FALSE,
        'alters_migrations' => $alters_migrations ? 'yes' : FALSE,
        'risk' => $risk,
        'installation_time' => $installation_time === NULL ? FALSE : (new \DateTime())->setTimestamp($installation_time)->format(\DateTime::RFC3339),
      ];

      $table[] = $row;
    }
    return new RowsOfFields($table);
  }

}
