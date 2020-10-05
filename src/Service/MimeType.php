<?php

namespace Drupal\quant\Service;

/**
 * Attempt to get a mime type for an asset.
 */
class MimeType {

  /**
   * Return a mime type string.
   *
   * @param string $url
   *   The URL to lookup.
   *
   * @return string
   *   The mime type.
   */
  public function get($url) {
    if (file_exists($url)) {
      return mime_content_type($url);
    }

    // Pathinfo supports URLs and paths - this can be used to quickly
    // identify known extensions and expected content-types.
    $parts = pathinfo($url);

    if (isset($parts['extension'])) {
      switch ($parts['extension']) {
        case 'txt':
          return 'text/plain';

        case 'xml':
          return 'text/xml; charset=utf-8';

        case 'json':
          return 'application/json';

      }
    }

    // Make a HEAD request to the location if we cannot determine the
    // extension.
    $handler = curl_init($url);

    curl_setopt($handler, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($handler, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($handler, CURLOPT_HEADER, 1);
    curl_setopt($handler, CURLOPT_NOBODY, 1);
    curl_exec($handler);

    return curl_getinfo($handler, CURLINFO_CONTENT_TYPE);
  }

}
