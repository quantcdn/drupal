<?php

namespace Drupal\quant_purger\Plugin\Purge\TagsHeader;

/**
 * Provides simple value object for cache tag headers.
 */
class QuantPurgerTagsHeaderValue {

  /**
   * String: separation character used.
   */
  const SEPARATOR = ' ';

  /**
   * List of original cache tags.
   *
   * @var string[]
   */
  protected $tags = [];

  /**
   * List of hashed cache tags.
   *
   * @var string[]
   */
  protected $tagsHashed = [];

  /**
   * Constructs a TagsHeaderValue object.
   *
   * @param string[] $tags
   *   Non-associative array cache tags.
   * @param string[] $tags_hashed
   *   Non-associative array with hashed cache tags.
   *
   * @throws \LogicException
   *   Thrown when both tags arrays aren't of equal length.
   */
  public function __construct(array $tags, array $tags_hashed) {
    if (count($tags) !== count($tags_hashed)) {
      throw new \LogicException("TagsHeaderValue received unequal tag sets!");
    }
    $this->tags = array_unique($tags);
    $this->tagsHashed = array_unique($tags_hashed);
  }

  /**
   * Generate the header value for a cache tags header.
   *
   * @return string
   *   String representation of the cache tags for use on headers.
   */
  public function __toString() {
    return implode(self::SEPARATOR, $this->tagsHashed);
  }

  /**
   * Get an associative array mapping keys.
   *
   * @return array
   *   Associative mapping original and hashed cache tags.
   */
  public function getTagsMap() {
    return array_combine($this->tags, $this->tagsHashed);
  }

}
