<?php

namespace Drupal\quant_api\Client;

/**
 * Describe what the Quant client looks like.
 */
interface QuantClientInterface {

  /**
   * Test the connection to the API controller.
   *
   * @return mixed
   *   Contains project machine name if successful
   */
  public function ping();

  /**
   * Returns the Quant project this client currently targets.
   *
   * Resolved at call time, because a multi-domain site changes the target
   * project once the active domain is negotiated.
   *
   * @return string|null
   *   The project machine name, or NULL when none is configured.
   */
  public function getProject() : ?string;

  /**
   * Retrieves project data.
   *
   * @return mixed
   *   Contains object containing project information.
   */
  public function project();

  /**
   * Retrieves project search configuration.
   *
   * @return mixed
   *   Contains object containing search information.
   */
  public function search();

  /**
   * Send a payload to the API.
   *
   * @param array $data
   *   The data array to send.
   *
   * @return array
   *   Return array of response data.
   *
   * @todo Error handlers :D
   *
   * @throws \Drupal\quant_api\Exception\InvalidPayload
   * @throws \Drupal\quant_api\Exception\InvalidResposne
   */
  public function send(array $data) : array;

  /**
   * Send a file to the API.
   *
   * @param string $file
   *   The path of a file on disk.
   * @param string $url
   *   The relative URL of the file.
   * @param int $rid
   *   The optional revision id.
   *
   * @return array
   *   Return array of response data.
   *
   * @todo Error handlers :D
   *
   * @throws \Drupal\quant_api\Exception\InvalidPayload
   * @throws \Drupal\quant_api\Exception\InvalidResposne
   */
  public function sendFile(string $file, string $url, ?int $rid = NULL) : array;

  /**
   * Send a redirect to the API.
   *
   * @param array $data
   *   The data array to send.
   *
   * @throws \Drupal\quant_api\Exception\InvalidPayload
   * @throws \Drupal\quant_api\Exception\InvalidResposne
   */
  public function sendRedirect(array $data) : array;

  /**
   * Send an unpublish request to Quant.
   *
   * @param string $url
   *   The url to unpublish.
   *
   * @return array
   *   The API response.
   */
  public function unpublish(string $url) : array;

  /**
   * Send a search record payload to Quant.
   *
   * @param array $records
   *   The array of search records to submit.
   *
   * @return array
   *   The API response.
   */
  public function sendSearchRecords(array $records) : array;

  /**
   * Whether the last ping() failed because Quant could not be reached.
   *
   * The client has then already shown the editor why.
   *
   * @return bool
   *   TRUE when the endpoint could not be reached.
   */
  public function lastCallUnreachable() : bool;

  /**
   * Clear a search index.
   *
   * @return array
   *   The API response.
   */
  public function clearSearchIndex() : array;

  /**
   * Ensure facets are appropriated enabled in Quant Search.
   *
   * @param array $facets
   *   The array of facet keys to ensure are enabled.
   *
   * @return array
   *   The API response.
   */
  public function addFacets(array $facets) : array;

  /**
   * Enables, updates or disables Quant Forms on a published page.
   *
   * Quant Forms is a switch on a page, not a form object. A content push can
   * carry the same keys inline; this call is for a page that is not pushed,
   * for example to disable the form of a deleted Webform.
   *
   * @param string $url
   *   The page path, for example /form/contact.
   * @param bool $enabled
   *   Whether the page takes submissions.
   * @param array $config
   *   The form_config, when enabled.
   *
   * @return bool
   *   TRUE when Quant accepted the change.
   */
  public function sendFormConfig(string $url, bool $enabled, array $config = []) : bool;

}
