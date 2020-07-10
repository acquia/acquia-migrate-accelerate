<?php

namespace Drupal\acquia_migrate\Controller;

use Drupal\acquia_migrate\Exception\MultipleClientErrorsException;
use Drupal\acquia_migrate\Exception\NotAcceptableException;
use Drupal\acquia_migrate\Exception\UnsupportedMediaTypeException;
use Drupal\Component\Utility\NestedArray;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP API basics.
 *
 * @internal
 */
trait HttpApiTrait {

  /**
   * Response headers written to all responses.
   *
   * @var array
   */
  protected static $defaultResponseHeaders = [
    'Content-Type' => 'application/vnd.api+json',
  ];

  /**
   * Ensures that the incoming request has valid JSON:API request headers.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   A request object.
   * @param array $valid_extensions
   *   (optional) An array that may contain the key: 'required'. The values of
   *   the array must be an indexed array of extension URIs. Support for
   *   additional keys, like 'optional', may be added later.
   *
   * @throws \Drupal\acquia_migrate\Exception\NotAcceptableException
   *   Thrown if the request does not accept a JSON:API media type response.
   * @throws \Drupal\acquia_migrate\Exception\UnsupportedMediaTypeException
   *   Thrown if the request's content does not use the JSON:API media type.
   */
  protected function validateRequestHeaders(Request $request, array $valid_extensions = NULL) {
    $valid_extensions = NestedArray::mergeDeep([
      'required' => [],
    ], ['required' => $valid_extensions['required'] ?: []]);
    $supported_extensions = $valid_extensions['required'];
    $accept_header_value = $request->headers->get('Accept');
    if ($accept_header_value) {
      foreach (array_map('trim', explode(',', $accept_header_value)) as $media_type) {
        if (preg_match('/application\/vnd\.api\+json.+/', $media_type)) {
          throw new NotAcceptableException();
        };
      }
    }
    $content_type_header_value = $request->headers->get('Content-Type');
    if ($content_type_header_value) {
      if (strpos($content_type_header_value, 'application/vnd.api+json') !== 0) {
        throw new UnsupportedMediaTypeException();
      };
      if (strlen($content_type_header_value) > strlen('application/vnd.api+json')) {
        $ext_pos = strpos($content_type_header_value, '; ext="');
        if ($ext_pos === FALSE || $ext_pos > strlen('application/vnd.api+json')) {
          throw new UnsupportedMediaTypeException();
        }
        $ext_string = explode('"', substr($content_type_header_value, $ext_pos), 3)[1];
        $extensions = explode(' ', $ext_string);
        $unsupported_extensions = array_diff($extensions, $supported_extensions);
        if (!empty($unsupported_extensions)) {
          throw new UnsupportedMediaTypeException(sprintf('The %s JSON:API media type extension is not supported', current($unsupported_extensions)));
        }
      }
    }
    if (!empty($valid_extensions['required'])) {
      $missing_extensions = array_diff($valid_extensions['required'], $extensions ?? []);
      if (count($missing_extensions) > 1) {
        throw new MultipleClientErrorsException(array_map(function ($extension) {
          return new UnsupportedMediaTypeException(sprintf('Missing JSON:API media type extension. The %s extension is required.', $extension));
        }, $missing_extensions));
      }
      elseif (count($missing_extensions) === 1) {
        throw new UnsupportedMediaTypeException(sprintf('Missing JSON:API media type extension. The %s extension is required.', array_pop($missing_extensions)));
      }
    }
  }

}
