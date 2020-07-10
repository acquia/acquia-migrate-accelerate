<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\Logger;

use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\syslog\Logger\SysLog;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Sends syslog data to Acquia's Sumologic when this module is running locally.
 *
 * @internal
 */
final class LocalhostToAcquiaSumologic extends SysLog {

  use RfcLoggerTrait;

  /**
   * The URI to send sumologic logging requests to.
   *
   * @var string
   */
  const URI = 'http://datdemoscvjsgfvcsd.devcloud.acquia-sites.com/localhost-to-acquia-sumologic';

  /**
   * The "system.site" config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $systemSiteConfig;

  /**
   * The HTTP client to send syslog data to Acquia's Sumologic.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $httpClient;

  /**
   * Constructs a LocalhostToAcquiaSumologic object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory object.
   * @param \Drupal\Core\Logger\LogMessageParserInterface $parser
   *   The parser to use when extracting message variables.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client to send syslog data to Acquia's Sumologic.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LogMessageParserInterface $parser, ClientInterface $http_client) {
    parent::__construct($config_factory, $parser);
    $this->systemSiteConfig = $config_factory->get('system.site');
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  protected function syslogWrapper($level, $entry) {
    static $must_send, $site_uuid, $site_name, $env;

    if (!isset($must_send)) {
      $env = AcquiaDrupalEnvironmentDetector::isLocalEnv() ? 'local' : 'cloud';
      $must_send = $env === 'local' && !file_exists(__DIR__ . '/../../.git');
      ['uuid' => $site_uuid, 'name' => $site_name] = $this->systemSiteConfig->get();
    }

    // Prefix the syslog entry with the site UUID, site name and environment.
    $entry = "uuid=$site_uuid|name=$site_name|env=$env|$entry";

    // Send it to syslog, and if it's a local development environment, also send
    // it to Acquia's Sumologic, to gather customer-wide migration metrics to
    // help prioritize what Acquia should contribute to next.
    syslog($level, $entry);
    if ($must_send) {
      $this->httpClient->post(static::URI, [
        RequestOptions::BODY => json_encode([
          'level' => $level,
          'entry' => $entry,
        ]),
      ]);
    }
  }

}
