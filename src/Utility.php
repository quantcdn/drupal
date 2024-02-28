<?php

namespace Drupal\quant;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;

/**
 * Quant utility class for helper functions.
 */
class Utility {

  /**
   * Determine if URL path prefix language negotiation is being used.
   *
   * @return bool
   *   TRUE if language path prefixes are configured and FALSE otherwise.
   */
  public static function usesLanguagePathPrefixes() : bool {
    // Only works if there is more than one language.
    $langcodes = \Drupal::languageManager()->getLanguages();
    if (count($langcodes) === 1) {
      return FALSE;
    }

    $usesPrefixes = FALSE;
    $languageInterfaceEnabled = \Drupal::config('language.types')->get('negotiation.language_interface.enabled') ?: [];
    if (isset($languageInterfaceEnabled['language-url'])) {
      $languageUrl = \Drupal::config('language.negotiation')->get('url');
      $usesPrefixes = $languageUrl && $languageUrl['source'] === LanguageNegotiationUrl::CONFIG_PATH_PREFIX;
    }
    return $usesPrefixes;
  }

  /**
   * Get URL based on site settings.
   *
   * @param string $url
   *   The URL.
   * @param string $langcode
   *   The language code for the URL.
   *
   * @return string
   *   The URL adjusted for multilingual settings. Defaults to current url.
   */
  public static function getUrl(string $url = NULL, string $langcode = NULL) : string {

    // Default to current URL.
    if (!$url) {
      $url = \Drupal::request()->getRequestUri();
    }

    // Always start with a slash.
    if (!str_starts_with($url, '/')) {
      $url = '/' . $url;
    }

    // Handle multilingual paths.
    $prefix = self::getPathPrefix($langcode);

    // Only add the language prefix if it's not there.
    if (!str_starts_with($url, $prefix)) {
      $url = $prefix . $url;
    }

    return $url;
  }

  /**
   * Get path prefix based on site settings.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The path prefix based on multilingual settings. Defaults to '/'.
   */
  public static function getPathPrefix(string $langcode = NULL) : string {

    // Always start with a slash.
    $prefix = '/';

    // Handle multilingual paths.
    if (self::usesLanguagePathPrefixes()) {
      // Use the current language if none is provided.
      if (!$langcode) {
        $langcode = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
      }
      // @todo Handle when prefix is different than the langcode.
      $prefix = '/' . $langcode;
    }

    return $prefix;
  }

  /**
   * Get the canonical URL.
   *
   * @param string $type
   *   The entity type.
   * @param int $id
   *   The entity id.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The relative canonical URL.
   */
  public static function getCanonicalUrl($type, $id, $langcode) {
    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    // The "canonical" URL is the alias, if it exists, or the internal path.
    return Url::fromRoute('entity.' . $type . '.canonical', [$type => $id], $options)->toString();
  }

  /**
   * Checks if it's an external URL.
   *
   * @param string $url
   *   The URL.
   *
   * @return bool
   *   TRUE if external and FALSE otherwise.
   */
  public static function isExternalUrl(string $url) : bool {
    $config = \Drupal::config('quant.settings');
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $check_url = parse_url($url);
    if (isset($check_url['host']) && $check_url['host'] !== $hostname) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Checks if an item is in a list which may use regular expressions.
   *
   * @param string $item
   *   The item to check for.
   * @param array $list
   *   The list to check. Items in the list can use regex.
   *
   * @return bool
   *   TRUE if the item is in the list and FALSE otherwise.
   */
  public static function inList($item, array $list) {
    $found = FALSE;
    foreach (array_filter($list) as $needle) {
      $pattern = preg_quote($needle, '/');
      $pattern = str_replace('\*', '.*', $pattern);
      preg_match('/^(' . $pattern . ')/', $item, $is_match);
      if (!empty($is_match)) {
        $found = TRUE;
      }
    }

    return $found;
  }

  /**
   * Get special pages.
   *
   * @return array
   *   An array of special pages to process.
   */
  public static function getSpecialPages() {
    $system = \Drupal::config('system.site');
    $pages = [
      $system->get('page.front'),
      $system->get('page.404'),
      $system->get('page.403'),
      '/',
      '/_quant404',
      '/_quant403',
    ];

    $validator = \Drupal::service('path.validator');
    foreach ($pages as $index => $page) {
      // Remove any pages that don't exist.
      if (empty($page) || !$validator->getUrlIfValid($page)) {
        unset($pages[$index]);
      }
    }

    return $pages;
  }

  /**
   * Get Quant page info for the given URLs.
   *
   * @param array $urls
   *   The URLs for the page info. Defaults to the current page.
   *
   * @return string
   *   The markup with the page info.
   */
  public static function getPageInfo(array $urls = NULL) : string {
    try {
      // Only allow administrators and content editors access.
      $roles = ['administrator', 'content_editor', 'editor'];
      if (empty(array_intersect($roles, \Drupal::currentUser()->getRoles()))) {
        return '';
      }

      // Default to the current page.
      if (!$urls) {
        $urls = [self::getUrl()];
      }

      $client = \Drupal::service('quant_api.client');
      $response = $client->getUrlMeta($urls);

      if (isset($response['global_meta']['records'])) {
        // Show meta information for the pages in Quant.
        $found_urls = [];
        $output = '<div class="quant-page-info messages messages--warning">';
        $output .= '<h2>' . t('Quant Page Info') . '</h2>';
        foreach ($response['global_meta']['records'] as $record) {
          $found_urls[] = $url = ($record['meta']['url'] ?? 'Url unknown');
          $output .= '<div class="quant-page-info">';
          $output .= '<strong>Page info for ' . $url . '</strong>';
          $output .= '<ul>';
          // @todo Fix underlying data per issue #3412934.
          $output .= '<li><strong>Published</strong>: ' . (($record['meta']['published'] ?? FALSE) ? t('Yes') : t('No')) . '</li>';
          $output .= '<li><strong>Revisions</strong>: ' . ($record['meta']['revision_count'] ?? 0) . '</li>';
          $date = DrupalDateTime::createFromTimestamp($record['meta']['content_timestamp'] ?? 0)->format('Y-m-d H:i:s');
          $output .= '<li><strong>Updated</strong>: ' . $date . '</li>';
          $date = DrupalDateTime::createFromTimestamp($record['meta']['date_timestamp'] ?? 0)->format('Y-m-d H:i:s');
          $output .= '<li><strong>Synced</strong>: ' . $date . '</li>';
          $output .= '</ul>';
          $output .= '</div>';
        }

        // Note any URLs that were not in Quant.
        if (count($urls) != count($found_urls)) {
          if (count($urls) === 1) {
            $output .= '<strong>' . t('Page info could not be found for this URL:') . '</strong>';
          }
          else {
            $output .= '<strong>' . t('Page info could not be found for the following URLs:') . '</strong>';
          }
          $output .= '<ul>';
        }
        foreach ($urls as $url) {
          if (!in_array($url, $found_urls)) {
            $output .= '<li>' . $url . '</li>';
          }
          $output .= '</ul>';
        }

        $output .= '</div>';
      }

      return $output;
    }
    catch (\Exception $e) {
      \Drupal::logger('quant')->error($e->getMessage());
      return '';
    }

  }

}
