<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate;

use Composer\Semver\VersionParser;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\State\StateInterface;

/**
 * Provides information about recommendations.
 *
 * @internal
 */
final class Recommendations {

  /**
   * State key for storing the initial info that generated this Drupal 9 site.
   *
   * @const string
   */
  const KEY_INITIAL_INFO = 'acquia_migrate.initial_info';

  /**
   * State key for storing the most recent info.
   *
   * @const string
   */
  const KEY_RECENT_INFO = 'acquia_migrate.recent_info';

  /**
   * Initial module information.
   *
   * @var array
   */
  protected $initialInfo;

  /**
   * Recent module information.
   *
   * @var array
   */
  protected $recentInfo;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * Recommendations constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   */
  public function __construct(StateInterface $state, ModuleExtensionList $module_extension_list) {
    // This key should be set during the initial installation of the site using
    // this module. The value should be the output of the ah-migrate-info
    // command.
    $this->initialInfo = $state->get(static::KEY_INITIAL_INFO, []);
    // Same as the above. Updated for every refresh.
    $this->recentInfo = $state->get(static::KEY_RECENT_INFO, []);

    $this->moduleExtensionList = $module_extension_list;
  }

  /**
   * Gets the time the recent info was last updated.
   *
   * @return string|null
   *   An RFC3339 timestamp.
   */
  public function getRecentInfoTime() : ?string {
    return $this->recentInfo['generated'] ?? $this->initialInfo['generated'] ?? NULL;
  }

  /**
   * Gets recommendations made for the source site.
   *
   * @return array[]
   *   A list of recommendations.
   */
  public function getRaw() : array {
    return array_values($this->initialInfo['recommendations'] ?? []);
  }

  /**
   * Gets the list of modules installed on the source site.
   *
   * @return string[]
   *   A list of source (Drupal 7) module names.
   */
  public function getSourceModules() : array {
    return array_values($this->initialInfo['sourceModules'] ?? []);
  }

  /**
   * Gets vetted recommendations only.
   *
   * @return array
   *   A list of vetted recommendations.
   */
  public function getVetted() : array {
    return array_values(array_filter($this->getRaw(), function (array $recommendation) {
      return $recommendation['type'] === 'packageRecommendation'
        && $recommendation['attributes']['vetted'] === TRUE;
    }));
  }

  /**
   * Gets unvetted recommendations only.
   *
   * @return array
   *   A list of unvetted recommendations.
   */
  private function getUnvetted() : array {
    return array_values(array_filter($this->getRaw(), function (array $recommendation) {
      return $recommendation['type'] === 'packageRecommendation'
        && $recommendation['attributes']['vetted'] === FALSE;
    }));
  }

  /**
   * Gets abandonment recommendations only.
   *
   * @return array
   *   A list of abandonment recommendations.
   */
  private function getAbandoned() : array {
    return array_values(array_filter($this->getRaw(), function (array $recommendation) {
      return $recommendation['type'] === 'abandonmentRecommendation';
    }));
  }

  /**
   * Gets source modules that the vetted recommendations install.
   *
   * @return string[]
   *   A list of source (Drupal 7) module names.
   */
  private function getVettedSourceModules() : array {
    $recommendations = $this->getVetted();
    return array_unique(array_reduce($recommendations, function (array $result, array $recommendation) {
      return array_merge($result, array_column($recommendation['relationships']['recommendedFor']['data'] ?? [], 'id'));
    }, []));
  }

  /**
   * Gets source modules that the unvetted recommendations preinstall.
   *
   * @return string[]
   *   A list of source (Drupal 7) module names.
   */
  private function getUnvettedSourceModules() : array {
    $recommendations = $this->getUnvetted();
    return array_unique(array_reduce($recommendations, function (array $result, array $recommendation) {
      return array_merge($result, array_column($recommendation['relationships']['recommendedFor']['data'] ?? [], 'id'));
    }, []));
  }

  /**
   * Gets source modules that the recommendations say are abandoned.
   *
   * @return string[]
   *   A list of source (Drupal 7) module names.
   */
  private function getAbandonedSourceModules() : array {
    $recommendations = $this->getAbandoned();
    return array_unique(array_reduce($recommendations, function (array $result, array $recommendation) {
      return array_merge($result, array_column($recommendation['relationships']['recommendedFor']['data'] ?? [], 'id'));
    }, []));
  }

  /**
   * Gets destination modules that the vetted recommendations install.
   *
   * @return string[]
   *   A list of destination (Drupal 9) module names.
   */
  public function getVettedDestinationModules() : array {
    $recommendations = $this->getVetted();
    $modules = array_unique(array_reduce($recommendations, function (array $result, array $recommendation) {
      return array_merge($result, $recommendation['attributes']['installModules']);
    }, []));

    // Explicitly mark modules that the Standard install profile installs by
    // default as vetted. The destination site will get these by default even
    // when the source site does not have them installed (which causes them to
    // not end up in the stored recommendations).
    // Last update: drupal/core:9.4.5.
    $modules[] = 'automated_cron';
    $modules[] = 'big_pipe';
    $modules[] = 'block';
    $modules[] = 'block_content';
    $modules[] = 'breakpoint';
    $modules[] = 'ckeditor';
    $modules[] = 'comment';
    $modules[] = 'config';
    $modules[] = 'contact';
    $modules[] = 'contextual';
    $modules[] = 'datetime';
    $modules[] = 'dblog';
    $modules[] = 'dynamic_page_cache';
    $modules[] = 'editor';
    $modules[] = 'field_ui';
    $modules[] = 'file';
    $modules[] = 'help';
    $modules[] = 'history';
    $modules[] = 'image';
    $modules[] = 'menu_link_content';
    $modules[] = 'menu_ui';
    $modules[] = 'node';
    $modules[] = 'options';
    $modules[] = 'page_cache';
    $modules[] = 'path';
    $modules[] = 'rdf';
    $modules[] = 'search';
    $modules[] = 'shortcut';
    $modules[] = 'taxonomy';
    $modules[] = 'toolbar';
    $modules[] = 'tour';
    $modules[] = 'views';
    $modules[] = 'views_ui';

    // media_migration ensures many things are migrated to the Media module, and
    // that is also automatically installed. Special case this too.
    $modules[] = 'media';

    // Analogously, explicitly mark this module and its dependencies as vetted.
    $modules[] = 'acquia_migrate';
    $modules[] = 'decoupled_pages';
    $modules[] = 'migrate_drupal_ui';
    $modules[] = 'migrate_drupal';
    $modules[] = 'migrate';
    $modules[] = 'migrate_plus';

    return $modules;
  }

  /**
   * Whether the given Drupal module extension is stable.
   *
   * @param \Drupal\Core\Extension\Extension $module
   *   A Drupal module extension.
   *
   * @return bool
   *   Whether the Drupal module extension is unstable.
   */
  public static function moduleIsStable(Extension $module) : bool {
    // Always consider core modules as stable.
    if (substr($module->getPath(), 0, 5) === 'core/') {
      return TRUE;
    }

    // Always consider Acquia Migrate itself stable.
    if ($module->getName() === 'acquia_migrate') {
      return TRUE;
    }

    $version = $module->info['version'];
    return $version !== NULL && VersionParser::parseStability($version) === 'stable';
  }

  /**
   * Whether the given Drupal module extension has migrations.
   *
   * @param \Drupal\Core\Extension\Extension $module
   *   A Drupal module extension.
   *
   * @return bool
   *   Whether the Drupal module extension has migrations.
   */
  public static function moduleHasMigrations(Extension $module) : bool {
    $path = $module->getPath();
    return !empty(glob("$path/migrations/*.yml")) || !empty(glob("$path/src/Plugin/migrate"));
  }

  /**
   * Whether the given Drupal module extension alters migrations.
   *
   * @param \Drupal\Core\Extension\Extension $module
   *   A Drupal module extension.
   *
   * @return bool
   *   Whether the Drupal module alters migrations.
   */
  public static function moduleAltersMigrations(Extension $module) : bool {
    $name = $module->getName();
    return module_load_include('module', $name) &&
      (
        function_exists($name . '_migration_migration_plugins_alter')
        || function_exists($name . '_migrate_source_info_alter')
        || function_exists($name . '_migrate_destination_info_alter')
        || function_exists($name . '_migrate_id_map_info_alter')
        || function_exists($name . '_migrate_process_info_alter')
        || function_exists($name . '_migrate_field_info_alter')
        || function_exists($name . '_migrate_field_info_alter')
        // This should also detect hook_migrate_MIGRATION_ID_prepare_row() being
        // defined, but that'd require iterating over all currently existing
        // migration plugins, since PHP does not allow wildcard function exists.
        // The probability of such a specific alter implementation breaking a
        // migration is very low though, so we accept the imperfection here — at
        // least for now.
        || function_exists($name . '_migrate_prepare_row')
      );
  }

  /**
   * Assesses the risk of a module installation breaking migrations.
   *
   * @param bool $is_vetted
   *   Whether the given module is in a vetted migration path.
   * @param bool $has_migrations
   *   Whether the module has migrations.
   * @param bool $alters_migrations
   *   Whether the module alters migrations.
   * @param bool $is_stable
   *   Whether the module is stable.
   *
   * @return string|null
   *   The assessed risk (low, medium or high) or NULL if non-risky.
   */
  public static function assessModuleInstallationMigrationBreakingRisk(bool $is_vetted, bool $has_migrations, bool $alters_migrations, bool $is_stable) : ?string {
    return $is_vetted
      ? NULL
      : (
        !($has_migrations || $alters_migrations)
          ? ($is_stable ? NULL : 'low')
          : ($alters_migrations
            ? 'high'
            : 'medium'
          )
        );
  }

  /**
   * Assesses the recommendation type for a source (Drupal 7) module.
   *
   * @param string $module_name
   *   A Drupal 7 module name.
   *
   * @return string
   *   The recommendation type: 'vetted', 'unvetted', 'abandoned', or 'none'.
   */
  public function getSourceModuleRecommendationType(string $module_name) : string {
    static $all, $vetted, $unvetted, $abandoned;

    if (!isset($all)) {
      $all = array_column($this->getSourceModules(), 'name');
      $vetted = array_intersect($all, $this->getVettedSourceModules());
      $unvetted = array_intersect($all, $this->getUnvettedSourceModules());
      $abandoned = array_intersect($all, $this->getAbandonedSourceModules());
    }

    $recommendation_type = in_array($module_name, $vetted, TRUE)
      ? 'vetted'
      : (in_array($module_name, $abandoned, TRUE)
        ? 'abandoned'
        : (in_array($module_name, $unvetted, TRUE)
          ? 'unvetted'
          : 'none'
        )
      );

    return $recommendation_type;
  }

}
