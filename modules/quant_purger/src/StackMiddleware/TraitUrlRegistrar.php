<?php

namespace Drupal\quant_purger\StackMiddleware;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\quant\Utility;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Methods for the URL registrar classes.
 */
trait TraitUrlRegistrar {

  /**
   * Determine if we need to track this route.
   *
   * @return bool
   *   If the request can be cached.
   */
  public function determine(Request $request, Response $response) {
    // Don't gather responses that don't have a quant token. As this
    // is a HTTP middleware we need to make sure this is as lean as
    // possible - we don't want to add a huge performance burden to
    // begin tracking pages to cachetags.
    if (!$request->headers->has('quant-token')) {
      return FALSE;
    }

    // Allow paths to be excluded from the traffic repository.
    $path = $this->generateUrl($request);
    $blocklist = $this->config->get('path_blocklist');
    $blocked = Utility::inList($path, $blocklist);
    if ($blocked) {
      $allowlist = $this->config->get('path_allowlist');
      $allowed = Utility::inList($path, $allowlist);
      if (!$allowed) {
        return FALSE;
      }
    }

    if (!is_a($response, CacheableResponseInterface::class)) {
      return FALSE;
    }

    // Don't gather responses that aren't going to be useful.
    $tag_list = $this->getAcceptedCacheTags($response->getCacheableMetadata()->getCacheTags());
    if (empty($tag_list)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Generates a URL to register.
   *
   * @return string
   *   The URL to register.
   */
  protected function generateUrl(Request $request) {
    if (NULL !== $qs = $request->getQueryString()) {
      $qs = '?' . $qs;
    }
    $path = $request->getBaseUrl() . $request->getPathInfo() . $qs;
    return '/' . ltrim($path, '/');
  }

  /**
   * Generate the cache tag list to be stored with this route.
   *
   * @param array $tag_list
   *   A list of tags from the cacheable response.
   *
   * @return array
   *   A list of cache tags for the URL.
   */
  protected function getAcceptedCacheTags(array $tag_list) {
    // Remove tags from blocklist.
    $tags1 = [];
    $blocklist = $this->config->get('tag_blocklist');
    $blocklist = is_array($blocklist) ? array_filter($blocklist) : [];
    if (!empty($blocklist)) {
      $tags1 = preg_grep('/^(' . implode('|', $blocklist) . ')/', $tag_list, PREG_GREP_INVERT);
    }

    // Add tags from allowlist. This must be done after the blocklist.
    $tags2 = [];
    $allowlist = $this->config->get('tag_allowlist');
    $allowlist = is_array($allowlist) ? array_filter($allowlist) : [];
    if (!empty($allowlist)) {
      $tags2 = preg_grep('/^(' . implode('|', $allowlist) . ')/', $tag_list);
    }

    $tags = array_unique(array_merge($tags1, $tags2));

    return array_filter($tags);
  }

}
