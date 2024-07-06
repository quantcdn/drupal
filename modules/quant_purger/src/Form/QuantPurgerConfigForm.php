<?php

namespace Drupal\quant_purger\Form;

/**
 * Configuration form for the Quant Purger.
 */
class QuantPurgerConfigForm extends QuantPurgerConfigFormBase {

  /**
   * The token group names this purger supports replacing tokens for.
   *
   * @var string[]
   *
   * @see purge_tokens_token_info()
   */
  protected $tokenGroups = ['invalidation'];

}
