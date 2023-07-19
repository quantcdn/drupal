<?php

/**
 * @file
 * Quant field handler for Quant log static URL.
 */

/**
 * Custom handler class.
 */
class views_handler_field_quant_static_url extends views_handler_field {

  /**
   * {@inheritdoc}
   */
  function query() {
   // This must be empty since this is not a quant_log database field.
  }

  /**
   * {@inheritdoc}
   *
   * Modify any end user views settings here. Debug $options to view the field
   * settings you can change.
   */
  function option_definition() {
    $options = parent::option_definition();
    return $options;
  }
 
  /**
   * {@inheritdoc}
   *
   * Make changes to the field settings form seen by the end user when adding
   * your field.
   */
  function options_form(&$form, &$form_state) {
    parent::options_form($form, $form_state);
  }
 
  /**
   * Render callback handler.
   *
   * Return the markup that will appear in the rendered field.
   */
  function render($values) {
    // If the URL has been set, use it to get the path.
    if (isset($values->quant_log_url)) {
      $static_url = $url = $values->quant_log_url;
      $path = parse_url($url, PHP_URL_PATH);

      // If the hostname has been set, use it.
      $hostname = variable_get('quant_hostname');
      if (!empty($hostname)) {
        $static_url = 'http://' . $hostname . $path;
      }

      // If the text has been overridden, use it.
      $text = !empty($this->options['text']) ? $this->options['text'] : $static_url;

      // Ignore any link settings and always link this.
      return l($text, $static_url);
    }
    else {
      return t('The quant_log.url field is missing from this view.');
    }
  }
}
