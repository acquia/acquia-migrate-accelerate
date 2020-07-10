<?php

namespace Drupal\acquia_migrate\EventSubscriber;

use Drupal\acquia_migrate\Cache\AcquiaMigrateCacheTagsInvalidator;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Manages response caching and validation for Acquia Migrate responses.
 *
 * @internal
 */
class CacheableAcquiaMigrateResponseSubscriber implements EventSubscriberInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The cache tags invalidator service.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * An etag cache.
   *
   * Keys are response objects, values are calculated etags. Using a static
   * cache prevents the need to recalculate an etag twice during the same
   * request.
   *
   * @var \SplObjectStorage
   */
  protected $etagCache;

  /**
   * Constructs a CacheableAcquiaMigrateResponseSubscriber object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   */
  public function __construct(RouteMatchInterface $route_match, CacheTagsInvalidatorInterface $cache_tags_invalidator) {
    $this->routeMatch = $route_match;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->etagCache = new \SplObjectStorage();
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber
   * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber
   */
  public static function getSubscribedEvents() {
    $events = [];

    // Run before DynamicPageCacheSubscriber::onRespond().
    $events[KernelEvents::RESPONSE][] = ['onResponseBeforeDynamicPageCacheSubscriber', 101];
    // Run after FinishResponseSubscriber::onRespond().
    $events[KernelEvents::RESPONSE][] = ['onResponseAfterFinishResponseSubscriber', -1];
    $events[KernelEvents::RESPONSE][] = ['invalidateAcquiaMigrateResponsesOnMutate'];

    return $events;
  }

  /**
   * Calculates and etag for an Acquia Migrate response.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   */
  public function onResponseBeforeDynamicPageCacheSubscriber(FilterResponseEvent $event) {
    if (!$this->shouldAddEtag($event)) {
      return;
    }
    $response = $event->getResponse();
    $etag = $this->calculateEtag($response);
    $response->headers->set('Etag', $etag);
    // Add the Acquia Migrate response cache tag to all responses.
    if ($response instanceof CacheableResponseInterface) {
      $response->addCacheableDependency((new CacheableMetadata())->addCacheTags([AcquiaMigrateCacheTagsInvalidator::ACQUIA_MIGRATE_RESPONSE_CACHE_TAG]));
    }
  }

  /**
   * Adds an Etag to  Acquia Migrate responses and returns 304, if possible.
   *
   * It's necessary to add the Etag header a second time because Drupal core's
   * FinishResponseSubscriber removes it. It does so because it considers an
   * Etag header unnecessary because the response is uncacheable by proxies and
   * browsers. However, Etag *can* be used, even with `Cache-Control:
   * must-revalidate, no-cache, private` responses, to prevent the browser from
   * having to download the same data twice.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   *
   * @see https://developers.google.com/web/fundamentals/performance/optimizing-content-efficiency/http-caching
   */
  public function onResponseAfterFinishResponseSubscriber(FilterResponseEvent $event) {
    $request = $event->getRequest();
    if (!$this->shouldAddEtag($event)) {
      return;
    }
    $response = $event->getResponse();
    $etag = $this->calculateEtag($response);
    $response->headers->set('Etag', $etag);
    if (!$request->headers->has('If-None-Match')) {
      return;
    }
    $match = $request->headers->get('If-None-Match');
    if ($response->getStatusCode() !== 304 && $match === $etag) {
      $not_modified_response = new CacheableResponse(NULL, 304);
      $response_cacheability = $response instanceof CacheableResponseInterface
        ? $response->getCacheableMetadata()
        : CacheableMetadata::createFromObject($response);
      $response_cacheability->addCacheContexts([
        'headers:If-None-Match',
      ]);
      $not_modified_response->addCacheableDependency($response_cacheability);
      $event->setResponse($not_modified_response);
    }
  }

  /**
   * Conditionally invalidates all Acquia Migrate responses.
   *
   * If the requested route is an Acquia Migrate route and the request method
   * is not cacheable (f.e. 'POST', 'PATCH', or 'DELETE'), invalidate all
   * Acquia Migrate responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   */
  public function invalidateAcquiaMigrateResponsesOnMutate(FilterResponseEvent $event) {
    if (strpos($this->routeMatch->getRouteName(), 'acquia_migrate') === 0 && !$event->getRequest()->isMethodCacheable()) {
      // The cache tags array is intentionally left empty.
      // @see \Drupal\acquia_migrate\Cache\AcquiaMigrateCacheTagsInvalidator::invalidateTags()
      $this->cacheTagsInvalidator->invalidateTags([]);
    }
  }

  /**
   * Whether this subscriber applies to the current request.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to check.
   *
   * @return bool
   *   TRUE if the current request is for an Acquia Migrate controller route and
   *   if the request method is cacheable, FALSE otherwise.
   */
  private function shouldAddEtag(FilterResponseEvent $event) {
    return strpos($this->routeMatch->getRouteName(), 'acquia_migrate') === 0 && $event->getRequest()->isMethodCacheable();
  }

  /**
   * Gets an etag for the given response.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   *
   * @return string
   *   The etag.
   */
  private function calculateEtag(Response $response) {
    if (!isset($this->etagCache[$response])) {
      $etag = $response->headers->has('Etag')
        ? $response->headers->get('Etag')
        : md5($response->getContent());
      $this->etagCache[$response] = $etag;
    }
    return $this->etagCache[$response];
  }

}
