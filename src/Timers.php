<?php

declare(strict_types=1);

namespace Drupal\acquia_migrate;

/**
 * The timers that Acquia Migrate Accelerate uses to instrument.
 *
 * Note that the timer names are used both for the Server-Timing metric name as
 * well as the PHP runtime timer.
 *
 * @see \Drupal\Component\Utility\Timer
 * @see https://w3c.github.io/server-timing/#the-server-timing-header-field
 */
final class Timers {

  /**
   * An array with the timer names as keys and descriptions as values.
   *
   * @var array
   */
  protected static $descriptions;

  /**
   * Compute-level timers.
   */
  const COMPUTE_MIGRATION_PLUGINS_DERIVED = 'ama-compute-plugins-derived';
  const COMPUTE_MIGRATION_PLUGINS_INITIAL = 'ama-compute-plugins-initial';
  const COMPUTE_MIGRATION_PLUGINS_INITIAL_TODO = 'ama-compute-plugins-initial-todo';
  const COMPUTE_MIGRATIONS = 'ama-cache-migrations-miss';

  /**
   * Cache retrieval timers.
   */
  const CACHE_MIGRATIONS = 'ama-cache-migrations';

  /**
   * Count-level timer: source and ID map plugin count.
   *
   * Mix of compute, cache and query. These are heavily used by the dashboard.
   * Hence it's important to monitor these counts.
   *
   * @todo Also actually monitor the source count, but there is no central place where to add this logging: very difficult to measure.
   *
   * @see \Drupal\acquia_migrate\Plugin\migrate\id_map\SqlWithCentralizedMessageStorage::countHelper()
   */
  const COUNT_ID_MAP = 'ama-count-id-map';

  /**
   * DB query-level timers.
   *
   * @see \Drupal\acquia_migrate\Controller\HttpApi::migrationsCollection
   */
  const QUERY_LOCK_GC = 'ama-query-lock-gc';
  const QUERY_COUNT_ID_MAP = 'ama-query-id-map-count';

  /**
   * JSON:API resource object-level timers: migration resource objects parts.
   *
   * @see \Drupal\acquia_migrate\Migration::toResourceObject()
   */
  const JSONAPI_RESOURCE_OBJECT_MIGRATION = 'ama-jsonapi-migration';
  const JSONAPI_RESOURCE_OBJECT_MIGRATION_ATTRIBUTES = 'ama-jsonapi-migration-attrs';
  const JSONAPI_RESOURCE_OBJECT_MIGRATION_TO_REFACTOR = 'ama-jsonapi-migration-TODO';
  const JSONAPI_RESOURCE_OBJECT_MIGRATION_LINKS = 'ama-jsonapi-migration-links';

  /**
   * Response-level timers.
   *
   * @see \Drupal\acquia_migrate\Controller\HttpApi::migrationsCollection
   */
  const RESPONSE_MIGRATIONS_COLLECTION = 'ama-response-migrations-collection';
  const RESPONSE_MESSAGES_COLLECTION = 'ama-response-messages-collection';

  /**
   * Response-level timer: JSON:API schema validation.
   *
   * @see \Drupal\acquia_migrate\EventSubscriber\HttpApiResponseValidator::doValidateResponse
   */
  const RESPONSE_JSONAPI_VALIDATION = 'ama-response-jsonapi-validation';

  const RESPONSE_ETAG = 'ama-response-etag';

  /**
   * Gets all timer descriptions.
   *
   * @return string[]
   *   An array with the timer names as keys and descriptions as values.
   *
   * @see \Drupal\Core\Logger\RfcLogLevel::getLevels
   */
  public static function getDescriptions() : array {
    if (!static::$descriptions) {
      static::$descriptions = [
        // Cache retrievals.
        static::CACHE_MIGRATIONS => 'Get migrations: HIT_OR_MISS (%d)',
        // Computations.
        static::COMPUTE_MIGRATIONS => 'Compute migrations (%d source DB queries, %d destination DB queries)',
        static::COMPUTE_MIGRATION_PLUGINS_DERIVED => 'Compute derived migration plugins',
        static::COMPUTE_MIGRATION_PLUGINS_INITIAL => 'Compute initial migration plugins',
        static::COMPUTE_MIGRATION_PLUGINS_INITIAL_TODO => 'Compute TODO initial migration plugins',
        // Counts.
        static::COUNT_ID_MAP => 'Counts (%d): mapping',
        // DB queries.
        static::QUERY_LOCK_GC => 'Query: GC lock',
        static::QUERY_COUNT_ID_MAP => 'Queries (%d): mapping counts',
        // JSON:API resource objects.
        static::JSONAPI_RESOURCE_OBJECT_MIGRATION => 'JSON:API migration resource objects (%d)',
        static::JSONAPI_RESOURCE_OBJECT_MIGRATION_ATTRIBUTES => 'JSON:API migration resource objects: attributes (%d)',
        static::JSONAPI_RESOURCE_OBJECT_MIGRATION_TO_REFACTOR => 'JSON:API migration resource objects: refactor (%d)',
        static::JSONAPI_RESOURCE_OBJECT_MIGRATION_LINKS => 'JSON:API migration resource objects: links (%d)',
        // Response-level.
        static::RESPONSE_MIGRATIONS_COLLECTION => "Migrations collection (%d source DB queries, %d destination DB queries)",
        static::RESPONSE_MESSAGES_COLLECTION => "Messages collection",
        static::RESPONSE_JSONAPI_VALIDATION => 'JSON:API validation',
        static::RESPONSE_ETAG => 'ETags (%d)',
      ];
    }

    return static::$descriptions;
  }

  /**
   * Gets a timer description.
   *
   * @param string $timer_name
   *   A timer name.
   *
   * @return string
   *   The corresponding description.
   */
  public static function getDescription(string $timer_name) : string {
    return static::getDescriptions()[$timer_name];
  }

}
