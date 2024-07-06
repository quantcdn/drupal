<?php

namespace Drupal\quant_purger\Plugin\Purge\TagsHeader;

use Drupal\purge\Plugin\Purge\TagsHeader\TagsHeaderBase;
use Drupal\purge\Plugin\Purge\TagsHeader\TagsHeaderInterface;
use Drupal\quant_purger\Entity\Hash;

/**
 * Sets and formats the default response header with cache tags.
 *
 * @PurgeTagsHeader(
 *   id = "quant_purger_tags_header",
 *   header_name = "Cache-Keys",
 * )
 */
class QuantPurgerTagsHeader extends TagsHeaderBase implements TagsHeaderInterface {

  /**
   * {@inheritdoc}
   */
  public function getValue(array $tags) {
    return new CacheTagsHeaderValue($tags, Hash::cacheTags($tags));
  }

}
