<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate;

use Drupal\Core\Extension\InfoParserInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Class ModuleAuditor.
 *
 * Provides information about a source site's modules and provides
 * recommendations for Drupal 9 modules and packages required to migrate the
 * source site.
 *
 * @internal
 */
final class ModuleAuditor {

  use StringTranslationTrait;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The module handler service.
   *
   * See static::__construct() for more information on the source of this data.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The info parser.
   *
   * @var \Drupal\Core\Extension\InfoParserInterface
   */
  protected $infoParser;

  /**
   * The recommendations.
   *
   * @var \Drupal\acquia_migrate\Recommendations
   */
  protected $recommendations;

  /**
   * ModuleAuditor constructor.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Extension\InfoParserInterface $info_parser
   *   The info parser.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\acquia_migrate\Recommendations $recommendations
   *   The recommendations.
   */
  public function __construct(ModuleExtensionList $module_extension_list, ModuleHandlerInterface $module_handler, InfoParserInterface $info_parser, TranslationInterface $string_translation, Recommendations $recommendations) {
    $this->moduleExtensionList = $module_extension_list;
    $this->moduleHandler = $module_handler;
    $this->infoParser = $info_parser;
    $this->stringTranslation = $string_translation;
    $this->recommendations = $recommendations;
  }

  /**
   * Get source site module information.
   *
   * @return array[]
   *   A list of resource objects representing source site modules.
   */
  public function getSourceModules() {
    $recognized_modules = static::getRecognizedModules($this->getRecommendations());
    return array_values(array_reduce($this->recommendations->getSourceModules(), function (array $acc, array $module) use ($recognized_modules) {
      // The state and its label for the current module. Modules with a
      // corresponding recommendation are "Found" and modules without a
      // recommendation are "Unknown".
      $recognition_state = in_array($module['name'], $recognized_modules)
        ? $this->t('Found')
        : $this->t('Unknown');
      return array_merge($acc, [
        $module['name'] => [
          'type' => 'sourceModule',
          'id' => $module['name'],
          'attributes' => [
            'humanName' => $module['humanName'],
            'version' => $module['version'],
            'recognitionState' => $recognition_state,
          ],
        ],
      ]);
    }, []));
  }

  /**
   * Get recommendations made for the source site.
   *
   * @return array[]
   *   A list of resource objects representing recommendations based on a source
   *   site.
   */
  public function getRecommendations() {
    // Map the recommendations into resource objects suitable for the response.
    return array_map([$this, 'buildRecommendationResourceObject'], $this->recommendations->getRaw());
  }

  /**
   * Transforms a recommendation into a resource object.
   *
   * Notably, this decorate the recommendation with a more precise `type`, an
   * `about` link, more module info and "fixes" the `recommendedFor`
   * relationship to use the correct type ('module' => 'sourceModule').
   *
   * @param array $recommendation
   *   A recommendation representation from the site's initial info.
   *
   * @return array
   *   A resource object representing a recommendation.
   */
  protected function buildRecommendationResourceObject(array $recommendation) : array {
    $type = $recommendation['type'];
    $id = $recommendation['id'];
    $attributes = [
      'vetted' => $recommendation['attributes']['vetted'] ?? FALSE,
    ];
    $relationships = [];
    $links = [];

    if (isset($recommendation['attributes']['note'])) {
      $attributes['note'] = $recommendation['attributes']['note'];
    }

    $recognized_modules = static::getRecognizedModules([$recommendation]);

    $relationships['recommendedFor']['data'] = array_map(function ($module_name) {
      return ['type' => 'sourceModule', 'id' => $module_name];
    }, $recognized_modules);

    if ($package_info = ($recommendation['attributes']['requirePackage'] ?? FALSE)) {
      $attributes['requirePackage']['packageName'] = $package_info['name'];
      $attributes['requirePackage']['versionConstraint'] = $package_info['versionConstraint'];
      // If the package name begins with `drupal/` but not `drupal/core` then
      // assume it is a project on d.o and link to it.
      $package_name = $package_info['name'];
      if (static::isLikelyDrupalProjectPackage($package_name)) {
        $project_name = substr($package_name, strlen('drupal/'));
        $links['about'] = [
          'href' => 'https://www.drupal.org/project/' . $project_name,
          'type' => 'text/html',
        ];
        $attributes['modules'] = [$this->getModuleInformation($project_name)];
      }
    }

    if (!empty($recommendation['attributes']['installModules'])) {
      $attributes['modules'] = array_map([$this, 'getModuleInformation'], $recommendation['attributes']['installModules']);
    }

    // Sorting makes fields deterministic for testing.
    ksort($attributes);
    ksort($relationships);

    // This prevents duplicates. The same package, i.e. `drupal/core` can
    // be recommended for many different source modules. This ensures that
    // every recommendation has a unique ID based on which source
    // module it applies to.
    sort($recognized_modules);
    $id_prefix = empty($recognized_modules) ? ['universal'] : $recognized_modules;

    $resource_object = [
      'type' => $type,
      'id' => implode(':', $id_prefix) . ":{$id}",
      'attributes' => $attributes,
      'relationships' => $relationships,
    ];

    if (!empty($links)) {
      $resource_object['links'] = $links;
    }

    return $resource_object;
  }

  /**
   * Get labels and installation status for a given module machine name.
   *
   * @param string $module_machine_name
   *   The module machine name for which to get human-readable labels and
   *   installation state.
   *
   * @return array
   *   An associative array of labels and installation state.
   */
  protected function getModuleInformation(string $module_machine_name) : array {
    $module_in_codebase = $this->moduleExtensionList->exists($module_machine_name);
    $info = $module_in_codebase
      ? $this->infoParser->parse($this->moduleExtensionList->get($module_machine_name)->getPathname())
      : [];
    $version = $info['version'] ?? NULL;

    // Using the machine name and a human name, create a "display" name to be
    // used by the client UI.
    $display_name = $module_in_codebase
      ? trim($this->moduleExtensionList->getName($module_machine_name))
      : $module_machine_name;
    return [
      'displayName' => $display_name,
      'machineName' => $module_machine_name,
      'version' => $version,
      'availableToInstall' => $module_in_codebase,
      'installed' => $module_in_codebase && $this->moduleHandler->moduleExists($module_machine_name),
    ];
  }

  /**
   * Get the list of all source modules recognized in a list of recommendations.
   *
   * @param array $recommendations
   *   A list of recommendations.
   *
   * @return string[]
   *   A list of source module machine names.
   */
  protected static function getRecognizedModules(array $recommendations) : array {
    return array_unique(array_reduce($recommendations, function (array $recognized, array $recommendation) {
      return array_merge($recognized, array_column($recommendation['relationships']['recommendedFor']['data'] ?? [], 'id'));
    }, []));
  }

  /**
   * Whether it is likely that the given package is a Drupal.org project.
   *
   * @param string $package_name
   *   The composer package name.
   *
   * @return bool
   *   TRUE if the package name begins with 'drupal/' and is not 'drupal/core';
   *   FALSE otherwise.
   */
  protected static function isLikelyDrupalProjectPackage(string $package_name) : bool {
    return strpos($package_name, 'drupal/') === 0 && strpos($package_name, 'drupal/core') !== 0;
  }

}
