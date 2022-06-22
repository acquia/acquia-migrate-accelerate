<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\acquia_migrate\Timers;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Generates Server-Timing response headers for all known timers.
 *
 * @internal
 *
 * @see \Drupal\acquia_migrate\Timers
 */
class ServerTimingHeaderForResponseSubscriber implements EventSubscriberInterface {

  /**
   * The query log.
   *
   * Since Kernel tests are executed in the same PHP process, we must be able to
   * empty our log.
   *
   * @var array
   */
  private static $queryLog;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a ServerTimingHeaderForResponseSubscriber object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * Allows tracking DB queries for Server-Timing header purposes.
   *
   * @param string $key
   *   The key to read/write data.
   * @param mixed $value
   *   When writing, a value to store; when reading, NULL.
   *
   * @return mixed|void|null
   *   void if writing, mixed when reading existing data, NULL when reading
   *   missing data.
   */
  public static function trackQueryLog(string $key, $value = NULL) {
    // Write.
    if ($value !== NULL) {
      if (isset(self::$queryLog[$key])) {
        throw new \LogicException('Tracked query logs cannot be overwritten');
      }
      self::$queryLog[$key] = $value;
      return;
    }

    // Read.
    return self::$queryLog[$key] ?? NULL;
  }

  /**
   * Adds Server-Timing response headers for all instrumented code paths.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function addServerTimingHeader(ResponseEvent $event) {
    $response = $event->getResponse();

    $route_name = $this->routeMatch->getRouteName();
    if ($route_name !== NULL && strpos($route_name, 'acquia_migrate') === 0) {
      // Caches.
      $this->generateHeaderIfTimed(Timers::CACHE_MIGRATIONS, $response->headers, Timers::COMPUTE_MIGRATIONS);

      // Computes.
      $this->generateHeaderIfTimed(Timers::COMPUTE_MIGRATIONS, $response->headers);
      $this->generateHeaderIfTimed(Timers::COMPUTE_MIGRATION_PLUGINS_DERIVED, $response->headers);
      $this->generateHeaderIfTimed(Timers::COMPUTE_MIGRATION_PLUGINS_INITIAL, $response->headers);
      $this->generateHeaderIfTimed(Timers::COMPUTE_MIGRATION_PLUGINS_INITIAL_TODO, $response->headers);

      // Counts.
      $this->generateHeaderIfTimed(Timers::COUNT_ID_MAP, $response->headers);

      // Queries.
      $this->generateHeaderIfTimed(Timers::QUERY_LOCK_GC, $response->headers);
      $this->generateHeaderIfTimed(Timers::QUERY_COUNT_ID_MAP, $response->headers);

      // JSON:API resource objects.
      $this->generateHeaderIfTimed(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION, $response->headers);
      $this->generateHeaderIfTimed(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_ATTRIBUTES, $response->headers);
      $this->generateHeaderIfTimed(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_TO_REFACTOR, $response->headers);
      $this->generateHeaderIfTimed(Timers::JSONAPI_RESOURCE_OBJECT_MIGRATION_LINKS, $response->headers);

      // Response-level.
      $this->generateHeaderIfTimed(Timers::RESPONSE_MIGRATIONS_COLLECTION, $response->headers);
      $this->generateHeaderIfTimed(Timers::RESPONSE_JSONAPI_VALIDATION, $response->headers);
      $this->generateHeaderIfTimed(Timers::RESPONSE_ETAG, $response->headers);
    }

    // Response-level timers plus thresholds that should trigger a complete dump
    // of all collected timers to syslog.
    $response_syslog_thresholds = [
      Timers::RESPONSE_MIGRATIONS_COLLECTION => 10000,
      Timers::RESPONSE_MESSAGES_COLLECTION => 1000,
    ];
    foreach ($response_syslog_thresholds as $timer_name => $threshold) {
      $measurements = static::getTimerMeasurements($timer_name);
      if ($measurements === NULL) {
        continue;
      }
      $duration = $measurements['time'];
      if ($duration > $threshold) {
        $all_server_timing_headers = $response->headers->allPreserveCase()['Server-Timing'];
        \Drupal::service('logger.channel.acquia_migrate_profiling_statistics')
          ->info(
            sprintf("stats_type=response|controller=%s|duration=%d|all-server-timing-headers=%s",
              Timers::getDescription($timer_name),
              round($duration),
              json_encode($all_server_timing_headers)
            )
          );
      }
    }
  }

  /**
   * Gets the timer measurements, noticefree.
   *
   * TRICKY: the Timer class does not allow you to check whether a timer was
   * actually used, so override the error handler temporarily to avoid PHP
   * notices getting logged.
   *
   * @param string $timer_name
   *   The name of the timer.
   *
   * @return array|null
   *   The measurements, if any.
   */
  private static function getTimerMeasurements(string $timer_name) : ?array {
    set_error_handler(function () {}, E_WARNING | E_NOTICE);
    $measurements = Timer::stop($timer_name);
    restore_error_handler();
    return $measurements;
  }

  /**
   * Adds a Server-Timing header for the given timer, if timed.
   *
   * @param string $timer_name
   *   The timer name to use (one of the constants on the Timers class).
   * @param \Symfony\Component\HttpFoundation\HeaderBag $response_headers
   *   The response header bag to add to.
   * @param string|null $cache_miss_timer_name
   *   (optional) For a HIT-or-MISS cache timer: expose whether it was a HIT.
   *
   * @see \Drupal\acquia_migrate\Timers
   */
  private function generateHeaderIfTimed(string $timer_name, HeaderBag $response_headers, ?string $cache_miss_timer_name = NULL)  : void {
    $measurements = static::getTimerMeasurements($timer_name);
    if ($measurements !== NULL) {
      $duration = $measurements['time'];
      $description = Timers::getDescription($timer_name);

      // Inject "HIT" or "MISS" into the "%s" of the description.
      if ($cache_miss_timer_name) {
        if (strpos($description, 'HIT_OR_MISS') === FALSE) {
          throw new \LogicException("Cache miss headers should allow injecting HIT/MISS, not the case for $timer_name.");
        }
        $cache_miss_measurements = static::getTimerMeasurements($cache_miss_timer_name);
        $hit_or_miss = $cache_miss_measurements === NULL ? 'HIT' : 'MISS';
        $description = str_replace('HIT_OR_MISS', $hit_or_miss, $description);
      }

      // Inject timer count into description if it contains a count.
      switch (substr_count($description, '%d')) {
        case 1:
          // Only timer's count.
          $count = $measurements['count'];
          $description = sprintf($description, $count);
          break;

        case 2:
          // Only source + destination DB query count.
          $src_query_count = static::trackQueryLog("$timer_name-src");
          $dst_query_count = static::trackQueryLog("$timer_name-dst");
          $description = sprintf($description, $src_query_count, $dst_query_count);
          break;

        case 3:
          // Both. Then the timer count must come first.
          $count = $measurements['count'];
          $src_query_count = static::trackQueryLog("$timer_name-src");
          $dst_query_count = static::trackQueryLog("$timer_name-dst");
          $description = sprintf($description, $count, $src_query_count, $dst_query_count);
          break;

        case 0:
          // Neither.
          // Prevent mismatches: complain loudly.
          if (strpos($description, '%d') === FALSE) {
            $count = Timer::stop($timer_name)['count'];
            if ($count > 1) {
              var_dump($timer_name);
              var_dump($count);
            }
          }
          break;

        default:
          // Anything else is wrong.
          throw new \LogicException();
      }

      $response_headers->set('Server-Timing', sprintf('%s;dur=%.1F;desc="%s"', $timer_name, $duration, $description), FALSE);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber
   * @see \Drupal\acquia_migrate\EventSubscriber\CacheableAcquiaMigrateResponseSubscriber
   */
  public static function getSubscribedEvents() {
    $events = [];

    // Run as the last possible subscriber.
    $events[KernelEvents::RESPONSE][] = ['addServerTimingHeader', -100];

    return $events;
  }

  /**
   * Drops the query log.
   *
   * This should be used only in Kernel tests.
   *
   * @see \Drupal\KernelTests\KernelTestBase::bootKernel
   */
  public static function dropQueryLog(): void {
    $container = \Drupal::getContainer();
    assert($container instanceof ContainerInterface);
    if (
      PHP_SAPI !== 'cli' && (
      !$container->hasParameter('kernel.environment') ||
      $container->getParameter('kernel.environment') !== 'testing'
    )) {
      throw new \LogicException('Tracked query logs can be dropped only during tests');
    }
    self::$queryLog = NULL;
  }

}
