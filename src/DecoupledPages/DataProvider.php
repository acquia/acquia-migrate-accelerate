<?php

namespace Drupal\acquia_migrate\DecoupledPages;

use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\decoupled_pages\DataProviderInterface;
use Drupal\decoupled_pages\Dataset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Dynamically sets the base path data attribute for the loaded decoupled page.
 */
class DataProvider implements DataProviderInterface {

  /**
   * The tracking API key.
   *
   * @var string
   */
  protected $key;

  /**
   * Path to the installed module.
   *
   * @var string
   */
  protected $modulePath;

  /**
   * The config.factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * DataProvider constructor.
   *
   * @param string $tracking_api_key
   *   The tracking API key.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config.factory service.
   */
  public function __construct(string $tracking_api_key, ModuleHandlerInterface $module_handler, ConfigFactoryInterface $config_factory) {
    $this->key = $tracking_api_key !== '' ? $tracking_api_key : getenv('AH_AMPLITUDE_MIGRATE_KEY');
    if (!$this->key && !file_exists(__DIR__ . '/../../.git')) {
      // Assign the beta API key.
      $this->key = '668f7215cb6cea0ebae1883963ebbbc7';
    }
    $this->modulePath = $module_handler->getModule('acquia_migrate')->getPath();
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(Route $route, Request $request): Dataset {
    $cacheability = new CacheableMetadata();
    return Dataset::cacheVariable($cacheability->setCacheMaxAge(86400), [
      'tracking-api-key' => $this->key,
      'module-path' => $this->modulePath,
      'drupal-app-id' => Crypt::hashBase64($this->configFactory->get('system.site')->get('uuid')),
      'ah-app-uuid' => AcquiaDrupalEnvironmentDetector::getAhApplicationUuid(),
      'ah-realm' => AcquiaDrupalEnvironmentDetector::getAhRealm(),
      'ah-non-production' => AcquiaDrupalEnvironmentDetector::getAhNonProduction(),
      'ah-env' => AcquiaDrupalEnvironmentDetector::getAhEnv(),
      'ah-group' => AcquiaDrupalEnvironmentDetector::getAhGroup(),
    ]);
  }

}
