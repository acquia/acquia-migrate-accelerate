<?php

namespace Drupal\acquia_migrate\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Overrides all interface mail plugin to 'terrible_mail_man'.
 */
class MailPluginOverrider implements ConfigFactoryOverrideInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constucts a MailPluginOverrider instance.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (!in_array('system.mail', $names)) {
      return [];
    }

    $original = $this->configFactory->getEditable('system.mail')->getRawData();
    foreach (array_keys($original['interface'] ?? []) as $key) {
      $overrides['system.mail']['interface'][$key] = 'terrible_mail_man';
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'acquia_migrate';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

}
