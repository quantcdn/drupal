<?php

namespace Drupal\quant_purger;

/**
 * Describes the traffic registry for URLS and tags.
 */
interface TrafficRegistryInterface {

  /**
   * Register a new URL or path with its associated cache tags.
   *
   * @param string $url
   *   The URL to register.
   * @param string[] $tags
   *   List of tags to associate with the URL.
   *
   * @throws \LogicException
   *   Thrown when $tags is empty.
   */
  public function add($url, array $tags);

  /**
   * Remove a URL from the registry.
   *
   * @param string $url
   *   The url to remove from the registry.
   */
  public function remove($url);

  /**
   * Clear the registry.
   */
  public function clear();

  /**
   * Return a list of paths that match the given tags.
   *
   * @param string[] $tags
   *   List of tags that are associated with URLs.
   *
   * @return string[]
   *   List of paths that match tags.
   */
  public function getPaths(array $tags);

  /**
   * Gets the paths matching the given cache tags, grouped by domain.
   *
   * A site serving several domains from one Drupal instance shows the same
   * page on more than one of them, and each domain publishes to its own
   * Quant project. Invalidating a tag therefore has to purge the page on
   * every domain that serves it, addressed to that domain's project.
   *
   * @param array $tags
   *   The cache tags being invalidated.
   *
   * @return array
   *   Lists of paths keyed by domain id. Sites without the Domain module
   *   return everything under an empty-string key.
   */
  public function getPathsByDomain(array $tags);

}
