<?php

namespace Drupal\acquia_migrate\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidator as DecoratedCacheTagsInvalidator;

/**
 * Whenever any cache tag is invalidated, invalidate Acquia Migrate migrations.
 *
 * This is a rudimentary way to enable returning cacheable responses to lower
 * the cost of the polling process on the migrations dashboard while postponing
 * the technical overhead of doing caching "the right way".
 */
class AcquiaMigrateCacheTagsInvalidator extends DecoratedCacheTagsInvalidator {

  /**
   * A cache tag added to all cacheable Acquia Migrate responses.
   *
   * @const string
   */
  const ACQUIA_MIGRATE_RESPONSE_CACHE_TAG = 'acquia_migrate_response';

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    parent::invalidateTags(Cache::mergeTags($tags, [static::ACQUIA_MIGRATE_RESPONSE_CACHE_TAG]));
  }

}
