<?php

namespace Drupal\quant_purger\Plugin\Purge\TagsHeader;

use Drupal\quant_purger\Entity\Hash;
use Drupal\purge\Plugin\Purge\TagsHeader\TagsHeaderInterface;
use Drupal\purge\Plugin\Purge\TagsHeader\TagsHeaderBase;

/**
 * Sets and formats the default response header with cache tags.
 *
 * @PurgeTagsHeader(
 *   id = "quant_tagsheader",
 *   header_name = "Cache-Tags",
 * )
 */
class QuantCacheTagsHeader extends TagsHeaderBase implements TagsHeaderInterface {

  /**
   * {@inheritdoc}
   */
  public function getValue(array $tags) {
    return new CacheTagsHeaderValue($tags, Hash::cacheTags($tags));
  }

}
