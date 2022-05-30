<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Clusterer;

use Drupal\acquia_migrate\Clusterer\Heuristics\DependentHeuristicInterface;
use Drupal\acquia_migrate\Clusterer\Heuristics\DependentHeuristicWithComputedDependentClusterInterface;
use Drupal\acquia_migrate\Clusterer\Heuristics\EntityRelatedHeuristicTrait;
use Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface;
use Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicWithComputedClusterInterface;
use Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicWithSingleClusterInterface;
use Drupal\acquia_migrate\Clusterer\Heuristics\IndependentHeuristicInterface;
use Drupal\acquia_migrate\Clusterer\Heuristics\LiftingHeuristicInterface;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\MigrateBuildDependencyInterface;
use Drupal\migrate\Plugin\Migration as MigrationPlugin;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate\Plugin\RequirementsInterface;

/**
 * Clusters Drupal's "migration (plugin)s" into this module's "migrations".
 *
 * This is the very heart of Acquia Migrate Accelerate, because it "clusters"
 * the numerous migration plugins that exist into "Drupal Site Builder" concepts
 * that make it significantly easier for non-experts to perform migrations from
 * Drupal 7 to Drupal 9.
 *
 * A "cluster" is a list of migration plugins plus an associated label that
 * describes which site builder concept these migration plugins are able to
 * migrate into Drupal 9.
 *
 * This class has migration plugins both as its input and its output. In the
 * output, it is guaranteed that @code getMetadata('cluster') @endcode is
 * defined. This contains the aforementioned label.
 *
 * The clusterer has two processing phases:
 * 1. ::getAvailableMigrationPlugins() gets *all* migration plugins from the
 *    migration plugin manager, and then limits it to those migration plugins
 *    that are actually runnable and those that are not omittable (some
 *    migration plugins only make sense for some source sites). Finally, it is
 *    sorted to respect migration plugin dependencies.
 * 2. ::getClusteredMigrationPlugins() starts from this subset and applies all
 *    heuristics to them, with the strict guarantee that every single migration
 *    plugin will have a cluster assigned. Migration plugins are returned in
 *    per-heuristic chunks, according to heuristic weights.
 *
 * The clusterer hence enables \Drupal\acquia_migrate\MigrationRepository to
 * generate a list of "migrations" (\Drupal\acquia_migrate\Migration) which
 * correspond to a site builder concept. To observe in detail how this mapping
 * works, see MigrationRepository::doGetMigrations().
 *
 * Each \Drupal\acquia_migrate\Migration has a label and a list of migration
 * plugins, i.e. \Drupal\migrate\Plugin\Migration instances. In other words:
 * there is a one-to-many relationship between "Acquia Migrate Accelerate
 * migrations" and "Drupal core migrations". All of the "Drupal core migrations"
 * combined result in a single site builder concept getting migrated.
 *
 * An example: the "Article" migration (\Drupal\acquia_migrate\Migration) for
 * the Drupal 7 core migration test database fixture contains the following
 * "Drupal core migrations", which are to be executed in the listed order :
 *  - d7_node_type:article
 *  - d7_field_instance:node:article
 *  - d7_field_instance_widget_settings:node:article
 *  - d7_field_formatter_settings:node:article
 *  - d7_rdf_mapping:node:article
 *  - d7_node_complete:article
 *  - d7_url_alias:node:article
 *  - d7_menu_links:node:article
 * For details, see HttpApiStandardTest::expectedResourceObjectForArticle() and
 * HttpApiStandardMultilingualTest::expectedResourceObjectForArticle().
 *
 * Heuristics are essential for the clusterer. For details about those, see the
 * docs at \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface.
 *
 * Note: none of this infrastructure is tightly coupled to Drupal 7. It can be
 * used for arbitrary migration plugins, from arbitrary sources. Only the
 * instantiation of migration plugins in this class is explicitly limited to
 * Drupal 7 (which is easily changed). Heuristics are inevitably tightly coupled
 * but that is why they are independent, tightly scoped classes: to make it easy
 * to add more — either for Drupal 7 or for other migration sources.
 *
 * @see \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface
 * @see \Drupal\acquia_migrate\Migration
 * @see \Drupal\acquia_migrate\MigrationRepository
 */
final class MigrationClusterer {

  use EntityRelatedHeuristicTrait;

  /**
   * The migration plugins with this tag will be made available.
   *
   * @see \Drupal\acquia_migrate\Clusterer\MigrationClusterer::getAvailableMigrationPlugins()
   *
   * @var string
   */
  const MIGRATION_PLUGIN_TAG = 'Drupal 7';

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationPluginManager;

  /**
   * The class resolver.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

  /**
   * Constructs a new MigrationClusterer.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_manager
   *   A migration plugin manager.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver.
   */
  public function __construct(MigrationPluginManagerInterface $migration_manager, ClassResolverInterface $class_resolver) {
    $this->migrationPluginManager = $migration_manager;
    $this->classResolver = $class_resolver;
  }

  /**
   * Gets the migration plugins that should be available for the user to run.
   *
   * @return \Drupal\migrate\Plugin\Migration[]
   *   The available migration plugins, keyed by ID, sorted in optimal order.
   */
  private function getAvailableMigrationPlugins() : array {
    $migration_plugins = $this->migrationPluginManager->createInstancesByTag(static::MIGRATION_PLUGIN_TAG);

    $migration_plugins = array_filter($migration_plugins, function (MigrationPlugin $migration_plugin) {
      // Ignore any and all overridden in-code migration plugins. We can
      // retrieve them when we need them.
      if (strpos($migration_plugin->id(), 'original___') === 0) {
        return FALSE;
      }
      return static::migrationPluginIsRunnable($migration_plugin)
        && !static::migrationPluginIsOmittable($migration_plugin);
    });

    // Resort the filtered subset of migration plugins.
    assert($this->migrationPluginManager instanceof MigrateBuildDependencyInterface);
    $migration_plugins = $this->migrationPluginManager->buildDependencyMigration($migration_plugins, []);

    // @codingStandardsIgnoreStart
    // Assert shape of the returned array:
    // - they must be Migration plugin instances
    assert(Inspector::assertAllObjects($migration_plugins, MigrationPlugin::class));
    // - with each migration plugin containing the complete dependency metadata
    assert(Inspector::assertAll(function (MigrationPlugin $migration_plugin) { return $migration_plugin->getMetadata('after') !== NULL && $migration_plugin->getMetadata('before') !== NULL; }, $migration_plugins));
    // @codingStandardsIgnoreEnd

    return $migration_plugins;
  }

  /**
   * Checks whether the given migration plugin can be run.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin to check.
   *
   * @return bool
   *   TRUE when the migration plugin is runnable, FALSE otherwise.
   */
  private static function migrationPluginIsRunnable(MigrationPlugin $migration_plugin) : bool {
    try {
      if ($migration_plugin->getSourcePlugin() instanceof RequirementsInterface) {
        $migration_plugin->getSourcePlugin()->checkRequirements();
      }
      if ($migration_plugin->getDestinationPlugin() instanceof RequirementsInterface) {
        $migration_plugin->getDestinationPlugin()->checkRequirements();
      }
    }
    catch (RequirementsException $e) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks whether the given migration plugin is safe to omit.
   *
   * We consider dataless migrations (source count of zero) safe to omit, except
   * those that target a content entity type: we do not want to omit those
   * because they are more likely to still receive data in the future. Perhaps
   * more importantly, they still are part of the source site's data model and
   * hence we should migrate that data model (the supporting configuration).
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The migration plugin to check.
   *
   * @return bool
   *   TRUE when the migration plugin is safe to omit, FALSE otherwise.
   */
  private static function migrationPluginIsOmittable(MigrationPlugin $migration_plugin) : bool {
    return !self::isContentEntityDestination($migration_plugin)
      && $migration_plugin->allRowsProcessed() === TRUE
      // Count can be lower than zero.
      // @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::count()
      && $migration_plugin->getSourcePlugin()->count() === 0;
  }

  /**
   * Gets all heuristics in the order to execute them.
   *
   * @return \Drupal\acquia_migrate\Clusterer\HeuristicInterface[]
   *   An array with heuristics classes as keys and weights as values. They are
   *   listed in execution order, with the weights indicating in what order the
   *   selected migration plugins for each heuristic should be appended.
   */
  private static function getHeuristics() : array {
    // @codingStandardsIgnoreStart
    return [
      Heuristics\SharedLanguageConfig::class => 0,
      Heuristics\ConfigNeedingHuman::class => 0,
      Heuristics\BlockPlacements::class => 500,
      Heuristics\BeanBlockPlacements::class => 500,
      // ModerationFlow should be computed before ContentEntityBundles and its
      // dependencies in ContentEntityBundlesDependencies, and it weight should
      // be higher than Heuristics\PushedToModerationFlow's weight.
      Heuristics\ModerationFlow::class => 50,
      // SharedVoteTypeConfig migrates vote types. Fivestar field storage config
      // requires vote types to exist. Its migration plugins should hence be
      // executed before fields are migrated by SharedEntityStructure.
      Heuristics\SharedVoteTypeConfig::class => 0,
      // SharedColorapi enables an additional field type and its migration
      // plugin should hence be executed before fields are migrated by
      // SharedEntityStructure.
      Heuristics\SharedColorapi::class => 0,
      Heuristics\SharedEntityStructure::class => 0,
      Heuristics\SharedEntityData::class => 0,
      Heuristics\SharedBookData::class => 500,
      Heuristics\SiteConfiguration::class => 500,
      // Should be computed before Heuristics\ContentEntityBundlesDependencies
      // and Heuristics\ConfigEntity, weight should be lower than
      // ModerationFlow's weight.
      Heuristics\PushedToModerationFlow::class => 40,
      // First the key data migration plugin for the entity type + bundle
      // cluster must be identified.
      // For example: d7_node_complete:article, d7_taxonomy_term:tags.
      Heuristics\ContentEntityBundles::class => 100,
      // Then its dependencies can be lifted.
      // For example: d7_node_type:article, d7_taxonomy_vocabulary:tags.
      Heuristics\ContentEntityBundlesDependencies::class => 70,
      // Followed by its translations.
      // For example: d7_taxonomy_term_translation:tags.
      Heuristics\ContentEntityTranslations::class => 200,
      // Depending configuration.
      // For example: d7_rdf_mapping:node:article.
      Heuristics\ContentEntityDependingConfig::class => 80,
      // Depending content.
      // For example: d7_url_alias:node:article.
      Heuristics\ContentEntityDependingContent::class => 110,
      // Independent but related configuration.
      // For example: d7_field_group, d7_entity_translation_settings.
      Heuristics\ContentEntityIndependentButRelatedConfig::class => 90,
      // Independent but related content.
      // For example: d7_menu_links:node:article.
      Heuristics\ContentEntityIndependentButRelatedContent::class => 120,
      // The remaining unclustered migrations: attempt to push them to the site
      // configuration cluster, or if they have a config entity type as the
      // destination, generate their own clusters. Finally, if all else fails,
      // put them in a catch-all "Other" cluster.
      Heuristics\PushedToSiteConfiguration::class => 500,
      Heuristics\ConfigEntity::class => 500,
      Heuristics\Other::class => 1000,
    ];
    // @codingStandardsIgnoreEnd
  }

  /**
   * Gets available (executable) migration plugins, sorted by cluster.
   *
   * Assigns a "cluster" metadata property to every migration plugin, and
   * changes the order of migration plugins to maximize the size of each
   * cluster.
   *
   * @return \Drupal\migrate\Plugin\Migration[]
   *   A list of executable migration plugins in the order they should be
   *   executed, optimized to run per cluster. Each migration plugin now has a
   *   `cluster` metadata property assigned.
   */
  public function getClusteredMigrationPlugins() : array {
    // Computing all clusters is very resource-intensive. For complex source
    // sites, the number of dependencies between migration plugins to analyze to
    // compute the appropriate clusters can be very high. Increase the memory
    // limit for the remainder of this request.
    ini_set('memory_limit', '512M');

    $all_migration_plugins = $this->getAvailableMigrationPlugins();

    $matched_migration_plugins_per_heuristic = [];
    foreach (static::getHeuristics() as $heuristic_class => $weight) {
      $heuristic = $this->classResolver->getInstanceFromDefinition($heuristic_class);
      assert($heuristic instanceof HeuristicInterface);
      $weights[$weight][] = $heuristic_class::id();
      // Apply the heuristic: find matches, then assign a cluster to each match.
      static::matchHeuristic($heuristic, $matched_migration_plugins_per_heuristic, $all_migration_plugins);
      foreach ($matched_migration_plugins_per_heuristic[$heuristic::id()] as $migration_plugin) {
        static::assignCluster($migration_plugin, $heuristic, $matched_migration_plugins_per_heuristic, $all_migration_plugins);
      }
    }

    // Generate ordered list of migration plugins, respecting heuristic weight.
    $clustered_migration_plugins = [];
    ksort($weights);
    foreach ($weights as $weight => $heuristic_ids) {
      foreach ($heuristic_ids as $heuristic_id) {
        $clustered_migration_plugins += $matched_migration_plugins_per_heuristic[$heuristic_id];
      }
    }

    // @codingStandardsIgnoreStart
    // Assert shape of the returned array:
    // - they must be Migration plugin instances:
    assert(Inspector::assertAllObjects($clustered_migration_plugins, MigrationPlugin::class));
    // - with each migration plugin having a cluster assigned:
    assert(Inspector::assertAll(function (MigrationPlugin $migration_plugin) { return !empty($migration_plugin->getMetadata('cluster')); }, $clustered_migration_plugins));
    // @codingStandardsIgnoreEnd

    return $clustered_migration_plugins;
  }

  /**
   * Matches a heuristic: attempt to match all migration plugins..
   *
   * @param \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface $heuristic
   *   The heuristic to apply.
   * @param \Drupal\migrate\Plugin\Migration[] &$matched_migration_plugins_per_heuristic
   *   Matched migration plugins per heuristic: heuristic IDs as top-level keys,
   *   with arrays of migration plugins (keyed by plugin ID) as values.
   * @param \Drupal\migrate\Plugin\Migration[] $all_migration_plugins
   *   All migration plugins.
   */
  private static function matchHeuristic(HeuristicInterface $heuristic, array &$matched_migration_plugins_per_heuristic, array $all_migration_plugins) : void {
    $heuristic_id = $heuristic::id();

    if ($heuristic instanceof IndependentHeuristicInterface) {
      $matched_migration_plugins_per_heuristic[$heuristic_id] = array_filter($all_migration_plugins, [
        $heuristic,
        'matches',
      ]);
    }
    elseif ($heuristic instanceof DependentHeuristicInterface) {
      if (!empty(array_diff($heuristic->getDependencies(), array_keys($matched_migration_plugins_per_heuristic)))) {
        throw new \LogicException(sprintf('The order in ::getHeuristics() is wrong, or one of the heuristics was modified incorrectly. The heuristics dependency requirements for %s are not fulfilled.', get_class($heuristic)));
      }
      // Lifting heuristics are a special case of dependent heuristics: they
      // need to not only know which migration plugin IDs were assigned a
      // cluster by a certain heuristic, they also need to be able to inspect
      // the dependency tree down. To allow for this, inject all migration
      // plugins.
      if ($heuristic instanceof LiftingHeuristicInterface) {
        $heuristic->provideAllMigrationPlugins($all_migration_plugins);
      }
      $unclustered_migrations = array_filter($all_migration_plugins, function (MigrationPlugin $migration) : bool {
        return empty($migration->getMetadata('cluster'));
      });
      // Note: array_filter() cannot be used here because each call needs to
      // be aware of the state change that the previous call may have caused.
      $matched_migration_plugins_per_heuristic[$heuristic_id] = [];
      $dependent_heuristic_matches = static::getDependentHeuristicMatches($heuristic, $matched_migration_plugins_per_heuristic);
      foreach ($unclustered_migrations as $plugin_id => $migration) {
        if ($heuristic->matches($migration, $dependent_heuristic_matches)) {
          $matched_migration_plugins_per_heuristic[$heuristic_id][$plugin_id] = $migration;
          $dependent_heuristic_matches[$heuristic_id][] = $plugin_id;
        }
      }
    }
    else {
      throw new \LogicException('Unknown heuristic matching type.');
    }
  }

  /**
   * Runs a heuristic's cluster computing logic and assigns the result.
   *
   * @param \Drupal\migrate\Plugin\Migration $migration_plugin
   *   The matched migration plugin to run the heuristic's cluster computing
   *   logic for and assign the resulting cluster.
   * @param \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface $heuristic
   *   The heuristic to apply.
   * @param \Drupal\migrate\Plugin\Migration[] $matched_migration_plugins_per_heuristic
   *   Matched migration plugins per heuristic: heuristic IDs as top-level keys,
   *   with arrays of migration plugins (keyed by plugin ID) as values.
   * @param \Drupal\migrate\Plugin\Migration[] $all_migration_plugins
   *   All migration plugins.
   */
  private static function assignCluster(MigrationPlugin $migration_plugin, HeuristicInterface $heuristic, array $matched_migration_plugins_per_heuristic, array $all_migration_plugins) : void {
    $existing_cluster = $migration_plugin->getMetadata('cluster');
    if (!empty($existing_cluster)) {
      throw new \InvalidArgumentException(sprintf('This migration plugin (%s) already has a cluster (%s) assigned!', $migration_plugin->id(), $existing_cluster));
    }
    if ($heuristic instanceof HeuristicWithSingleClusterInterface) {
      $cluster = $heuristic::cluster();
    }
    elseif ($heuristic instanceof HeuristicWithComputedClusterInterface) {
      $cluster = $heuristic->computeCluster($migration_plugin);
    }
    elseif ($heuristic instanceof DependentHeuristicWithComputedDependentClusterInterface) {
      $dependent_heuristic_matches = static::getDependentHeuristicMatches($heuristic, $matched_migration_plugins_per_heuristic);
      $cluster = $heuristic->computeCluster($migration_plugin, $dependent_heuristic_matches, $all_migration_plugins);
    }
    else {
      throw new \LogicException('Unknown heuristic cluster computing type.');
    }
    $migration_plugin->setMetadata('cluster', $cluster);
  }

  /**
   * Gets the assignment information that the given heuristic needs.
   *
   * @param \Drupal\acquia_migrate\Clusterer\Heuristics\HeuristicInterface $heuristic
   *   The heuristic to get the dependent heuristic matches for.
   * @param \Drupal\migrate\Plugin\Migration[] $matched_migration_plugins_per_heuristic
   *   The migration plugins that have already matched a heuristic, keyed by
   *   heuristic ID.
   *
   * @return string[]
   *   The relevant subset of $matched_migration_plugins_per_heuristic, but with
   *   migration plugins replaced with corresponding migration plugin IDs. This
   *   is then a safe value to pass to heuristics' logic: they cannot
   *   (accidentally) alter the migration plugins.
   */
  private static function getDependentHeuristicMatches(HeuristicInterface $heuristic, array $matched_migration_plugins_per_heuristic) : array {
    if ($heuristic instanceof IndependentHeuristicInterface) {
      return [];
    }

    $assignments = [];
    foreach ($heuristic->getDependencies() as $heuristic_dependency_id) {
      $assignments[$heuristic_dependency_id] = array_keys($matched_migration_plugins_per_heuristic[$heuristic_dependency_id]);
    }
    // Always include the heuristic's own prior matches too.
    $assignments[$heuristic::id()] = array_keys($matched_migration_plugins_per_heuristic[$heuristic::id()]);
    return $assignments;
  }

}
