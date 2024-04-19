<?php

namespace Drupal\quant_purger\Form;

/**
 * Configuration form for the HTTP Bundled Purger.
 */
class QuantPurgeForm extends QuantPurgeFormBase {

  /**
   * The token group names this purger supports replacing tokens for.
   *
   * @var string[]
   *
   * @see purge_tokens_token_info()
   */
  protected $tokenGroups = ['invalidation'];

}
