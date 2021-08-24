<?php

namespace Drupal\acquia_migrate\Plugin\Discovery;

use Drupal\acquia_migrate\Timers;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Plugin\Discovery\ContainerDerivativeDiscoveryDecorator;
use Drupal\migrate\Plugin\Migration;

/**
 * Profiles deriving of migration plugins. Logs to acquia_migrate_statistics.
 *
 * @see \Drupal\Core\Plugin\Discovery\ContainerDerivativeDiscoveryDecorator
 */
class ProfiledContainerDerivativeDiscoveryDecorator extends ContainerDerivativeDiscoveryDecorator {

  /**
   * The possible profiling classifications.
   *
   * Keys are classification labels, values are the corresponding max durations
   * in milliseconds.
   *
   * There can only be one classification with the max duration -1, which is the
   * fallback classification.
   */
  const CLASSIFICATION = [
    'FAST' => 10,
    'SLOW' => 100,
    'VERYSLOW' => -1,
  ];

  /**
   * Converts shorthand memory notation value to bytes.
   *
   * @param string $val
   *   Memory size shorthand notation string. E.g., 128M.
   *
   * @see http://php.net/manual/en/function.ini-get.php
   */
  private static function getBytes(string $val) : int {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $val = substr($val, 0, -1);
    switch ($last) {
      // Fall through (absence of break statements) is intentional.
      case 'g':
        $val *= 1024;
      case 'm':
        $val *= 1024;
      case 'k':
        $val *= 1024;
    }
    return (int) $val;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDerivatives(array $base_plugin_definitions) {
    $minimum_memory_limit_mb = 512;
    $current_memory_limit = static::getBytes(ini_get('memory_limit'));
    if ($current_memory_limit < ($minimum_memory_limit_mb * 1024 * 1024)) {
      // Computing all derivatives is very resource-intensive. For complex
      // source sites, the number of derivatives to generate can be very high.
      // Increase the memory limit for the remainder of this request.
      ini_set('memory_limit', $minimum_memory_limit_mb . 'M');
    }

    Timer::start(Timers::COMPUTE_MIGRATION_PLUGINS_DERIVED);

    $plugin_definitions = [];
    foreach ($base_plugin_definitions as $base_plugin_id => $plugin_definition) {
      Timer::start("acquia_migrate:migration_plugin:$base_plugin_id");
      $deriver = $this->getDeriver($base_plugin_id, $plugin_definition);
      if ($deriver) {
        $derivative_definitions = $deriver->getDerivativeDefinitions($plugin_definition);
        foreach ($derivative_definitions as $derivative_id => $derivative_definition) {
          $plugin_id = $this->encodePluginId($base_plugin_id, $derivative_id);
          // Use this definition as defaults if a plugin already defined
          // itself as this derivative.
          if ($derivative_id && isset($base_plugin_definitions[$plugin_id])) {
            $derivative_definition = $this->mergeDerivativeDefinition($base_plugin_definitions[$plugin_id], $derivative_definition);
          }
          $plugin_definitions[$plugin_id] = $derivative_definition;
        }
      }
      // If a plugin already defined itself as a derivative it might already
      // be merged into the definitions.
      elseif (!isset($plugin_definitions[$base_plugin_id])) {
        $plugin_definitions[$base_plugin_id] = $plugin_definition;
      }
      $duration = Timer::stop("acquia_migrate:migration_plugin:$base_plugin_id")['time'];
      foreach (self::CLASSIFICATION as $label => $max) {
        if ($duration < $max || $max === -1) {
          $classification = $label;
          break;
        }
      }
      $source_row_count = $classification !== array_keys(self::CLASSIFICATION)[0] ? self::getSourceRowCount($plugin_definition) : -1;

      // Send to logger for aggregate analysis.
      \Drupal::service('logger.channel.acquia_migrate_profiling_statistics')->info(
        sprintf("stats_type=plugin_derivative|migration_plugin_id=%s|definition_count=%d|duration=%d|classification=%s|source_row_count=%d",
          $base_plugin_id,
          isset($deriver) ? count($derivative_definitions) : 1,
          round($duration),
          $classification,
          $source_row_count
        )
      );
    }

    $duration = Timer::stop(Timers::COMPUTE_MIGRATION_PLUGINS_DERIVED)['time'];
    \Drupal::service('logger.channel.acquia_migrate_profiling_statistics')->info(
      sprintf("stats_type=plugin_derivatives|original_definition_count=%d|derived_definition_count=%d|duration=%d",
        count($base_plugin_definitions),
        count($plugin_definitions),
        round($duration)
      )
    );

    return $plugin_definitions;
  }

  /**
   * Gets the source row count for the given migration plugin definition.
   *
   * @param array $migration_plugin_definition
   *   A migration plugin definition.
   *
   * @return int
   *   The number of rows in the given migration plugin's source, or -1 if it
   *   could not be determined.
   */
  private static function getSourceRowCount(array $migration_plugin_definition) : int {
    $source_row_count = -1;
    try {
      // Note: suppress notices, because some source plugins have required
      // configuration which is not present on the base plugin definition.
      $source_row_count = @\Drupal::service('plugin.manager.migrate.source')
        ->createInstance($migration_plugin_definition['source']['plugin'], $migration_plugin_definition['source'], new StubMigration())
        ->count();
    }
    catch (\Throwable $e) {
    }
    return $source_row_count;
  }

}

/**
 * Migration plugin instance class stub, to avoid doing costly code loads.
 *
 * @see \Drupal\acquia_migrate\Plugin\Discovery\ProfiledContainerDerivativeDiscoveryDecorator::getSourceRowCount()
 */
final class StubMigration extends Migration {

  /**
   * {@inheritdoc}
   */
  public function __construct() {
    // This is needed to avoid crashing SourcePluginBase.
    // @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::__construct()
    $this->idMapPlugin = FALSE;
  }

}
