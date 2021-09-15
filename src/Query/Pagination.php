<?php

namespace Drupal\acquia_migrate\Query;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * This class is copied from JSON:API module's OffsetPage class.
 *
 * @see \Drupal\jsonapi\Query\OffsetPage
 *
 * Also, three new methods are added to extend the pagination support.
 * These methods are copied from JSON:API module's EntityResource class.
 * @see \Drupal\jsonapi\Controller\EntityResource::getPagerLinks()
 * @see \Drupal\jsonapi\Controller\EntityResource::getRequestLink()
 * @see \Drupal\jsonapi\Controller\EntityResource::getPagerQueries()
 */
class Pagination {

  /**
   * The JSON:API pagination key name.
   *
   * @var string
   */
  const KEY_NAME = 'page';

  /**
   * The offset key in the page parameter: page[offset].
   *
   * @var string
   */
  const OFFSET_KEY = 'offset';

  /**
   * The size key in the page parameter: page[limit].
   *
   * @var string
   */
  const SIZE_KEY = 'limit';

  /**
   * Default offset.
   *
   * @var int
   */
  const DEFAULT_OFFSET = 0;

  /**
   * Max size.
   *
   * @var int
   */
  const SIZE_MAX = 1000;

  /**
   * The offset for the query.
   *
   * @var int
   */
  protected $offset;

  /**
   * The size of the query.
   *
   * @var int
   */
  protected $size;

  /**
   * Instantiates a Pagination object.
   *
   * @param int $offset
   *   The query offset.
   * @param int $size
   *   The query size limit.
   */
  public function __construct($offset, $size) {
    $this->offset = $offset;
    $this->size = $size;
  }

  /**
   * Returns the current offset.
   *
   * @return int
   *   The query offset.
   */
  public function getOffset() {
    return $this->offset;
  }

  /**
   * Returns the page size.
   *
   * @return int
   *   The requested size of the query result.
   */
  public function getSize() {
    return $this->size;
  }

  /**
   * Get the pager links for a given request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param Pagination $page_param
   *   The current pagination parameter for the requested collection.
   * @param array $link_context
   *   An associative array with extra data to build the links.
   *
   * @return array
   *   An array, with:
   *   - a 'next' key if it is not the last page;
   *   - 'prev' and 'first' keys if it's not the first page.
   */
  public static function getPagerLinks(Request $request, Pagination $page_param, array $link_context = []) {
    $pager_links = [];
    $total = (int) $link_context['total_count'];
    $offset = $page_param->getOffset();
    $size = $page_param->getSize();
    $query = (array) $request->query->getIterator();
    $pager_links['self'] = ['href' => $request->getUri()];
    // Check if this is not the last page.
    if ($link_context['has_next_page']) {
      $next_url = static::getRequestLink($request, static::getPagerQueries('next', $offset, $size, $query))->toUriString();
      $pager_links['next'] = ['href' => $next_url];
    }
    if (!empty($total)) {
      $last_url = static::getRequestLink($request, static::getPagerQueries('last', $offset, $size, $query, $total))->toUriString();
      $pager_links['last'] = ['href' => $last_url];
    }

    // Check if this is not the first page.
    if ($offset > 0) {
      $first_url = static::getRequestLink($request, static::getPagerQueries('first', $offset, $size, $query))->toUriString();
      $pager_links['first'] = ['href' => $first_url];
      $prev_url = static::getRequestLink($request, static::getPagerQueries('prev', $offset, $size, $query))->toUriString();
      $pager_links['prev'] = ['href' => $prev_url];
    }
    return $pager_links;
  }

  /**
   * Get the full URL for a given request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param array|null $query
   *   The query parameters to use. Leave it empty to get the query from the
   *   request object.
   *
   * @return \Drupal\Core\Url
   *   The full URL.
   */
  protected static function getRequestLink(Request $request, $query = NULL) {
    if ($query === NULL) {
      return Url::fromUri($request->getUri());
    }

    $uri_without_query_string = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo();
    return Url::fromUri($uri_without_query_string)->setOption('query', $query);
  }

  /**
   * Get the query param array.
   *
   * @param string $link_id
   *   The name of the pagination link requested.
   * @param int $offset
   *   The starting index.
   * @param int $size
   *   The pagination page size.
   * @param array $query
   *   The query parameters.
   * @param int $total
   *   The total size of the collection.
   *
   * @return array
   *   The pagination query param array.
   */
  protected static function getPagerQueries($link_id, $offset, $size, array $query = [], $total = 0) {
    $extra_query = [];
    switch ($link_id) {
      case 'next':
        $extra_query = [
          'page' => [
            'offset' => $offset + $size,
            'limit' => $size,
          ],
        ];
        break;

      case 'first':
        $extra_query = [
          'page' => [
            'offset' => 0,
            'limit' => $size,
          ],
        ];
        break;

      case 'last':
        if ($total) {
          $extra_query = [
            'page' => [
              'offset' => (ceil($total / $size) - 1) * $size,
              'limit' => $size,
            ],
          ];
        }
        break;

      case 'prev':
        $extra_query = [
          'page' => [
            'offset' => max($offset - $size, 0),
            'limit' => $size,
          ],
        ];
        break;
    }
    return array_merge($query, $extra_query);
  }

  /**
   * Creates a Pagination object from a query parameter.
   *
   * @param mixed $parameter
   *   The `page` query parameter from the Symfony request object.
   *
   * @return static
   *   A Pagination object with defaults.
   */
  public static function createFromQueryParameter($parameter) {
    if (!is_array($parameter)) {
      $cacheability = (new CacheableMetadata())->addCacheContexts(['url.query_args:page']);
      throw new CacheableBadRequestHttpException($cacheability, 'The page parameter needs to be an array.');
    }

    $expanded = $parameter + [
      static::OFFSET_KEY => static::DEFAULT_OFFSET,
      static::SIZE_KEY => static::SIZE_MAX,
    ];

    if ($expanded[static::SIZE_KEY] > static::SIZE_MAX) {
      $expanded[static::SIZE_KEY] = static::SIZE_MAX;
    }

    return new static($expanded[static::OFFSET_KEY], $expanded[static::SIZE_KEY]);
  }

}
