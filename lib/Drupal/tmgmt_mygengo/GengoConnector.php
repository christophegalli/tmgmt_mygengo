<?php
/**
 * @file
 * Connector class for Gengo service.
 */

namespace Drupal\tmgmt_mygengo;

use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TMGMTException;
use Drupal\Component\Utility\Url;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\RequestException;

class GengoConnector {

  /**
   * Translation service URL.
   */
  const PRODUCTION_URL = 'http://api.gengo.com';

  /**
   * Translation sandbox service URL.
   */
  const SANDBOX_URL = 'http://api.sandbox.gengo.com';

  /**
   * Translation service API version.
   *
   * @var string
   */
  const API_VERSION = 'v2';

  /**
   * Internal mock service URL used by tests.
   *
   * @var string
   */
  public $mockServiceURL = 'tmgmt_mygengo_mock';

  private $useSandbox = FALSE;
  private $pubKey;
  private $privateKey;

  /**
   * Flag to trigger debug watchdog logging of requests.
   *
   * Use variable_set('tmgmt_mygengo_debug', TRUE); to toggle debugging.
   *
   * @var bool
   */
  private $debug = FALSE;

  /**
   * Guzzle HTTP client.
   *
   * @var \Guzzle\Http\ClientInterface
   */
  protected $client;

  /**
   * Construct the connector to gengo service.
   *
   * @param \Drupal\tmgmt\Entity\Translator $translator
   *   Translator which has the connection settings.
   */
  public function __construct(Translator $translator, ClientInterface $client) {
    $this->useSandbox = $translator->getSetting('use_sandbox');
    $this->pubKey = $translator->getSetting('api_public_key');
    $this->privateKey = $translator->getSetting('api_private_key');
    $this->debug = variable_get('tmgmt_mygengo_debug', FALSE);
    $this->client = $client;
  }

  /**
   * Submits gengo jobs for translation.
   *
   * @param array $gengo_jobs
   *   Array of gengo jobs.
   *
   * @return object
   *   Gengo response data.
   */
  public function submitJob(array $gengo_jobs) {
    return $this->post('translate/jobs', array(
      'jobs' => $gengo_jobs,
      'as_group' => (int) (count($gengo_jobs) > 1),
    ));
  }

  /**
   * Gets a quote for provided jobs.
   *
   * @param array $gengo_jobs
   *   List of gengo jobs.
   *
   * @return object
   *   Gengo response data.
   */
  public function getQuote(array $gengo_jobs) {
    return $this->post('translate/service/quote', array(
      'jobs' => $gengo_jobs,
      'as_group' => (int) (count($gengo_jobs) > 1),
    ));
  }

  /**
   * Gets available languages.
   *
   * @param string $remote_source_language
   *   Mapped source lang code.
   *
   * @return object
   *   Gengo response data.
   */
  public function getLanguages($remote_source_language = NULL) {
    $data = array();
    if (!empty($remote_source_language)) {
      $data = array('lc_src' => $remote_source_language);
    }
    return $this->get('translate/service/languages', $data);
  }

  /**
   * Gets language pairs for provided source language.
   *
   * @return object
   *   List of language pairs.
   */
  public function getLanguagePairs() {
    return $this->get('translate/service/language_pairs');
  }

  /**
   * Gets remaining credit info.
   *
   * @return object
   *   Gengo response data.
   */
  public function getRemainingCredit() {
    return $this->get('account/balance');
  }

  /**
   * Post new comment to gengo.
   *
   * @param int $gengo_job_id
   *   Gengo job it to which to post comment.
   * @param string $comment_text
   *   Comment text.
   *
   * @return object
   *   Gengo response data.
   */
  public function postComment($gengo_job_id, $comment_text) {
    return $this->post('translate/job/' . $gengo_job_id . '/comment', array('body' => $comment_text));
  }

  /**
   * Gets comments from gengo.
   *
   * @param int $gengo_job_id
   *   Gengo job it to which to get comments.
   *
   * @return object
   *   Gengo response data.
   */
  public function getComments($gengo_job_id) {
    return $this->get('translate/job/' . $gengo_job_id . '/comments');
  }

  /**
   * Get order from gengo.
   *
   * @param int $gorder_id
   *   Gengo order id.
   *
   * @return object
   *   Gengo response data.
   */
  public function getOrder($gorder_id) {
    return $this->get('translate/order/' . $gorder_id);
  }

  /**
   * Gets gengo jobs.
   *
   * @param array $gengo_job_ids
   *   Gengo job ids.
   *
   * @return object
   *   Gengo response data.
   */
  public function getJobs(array $gengo_job_ids) {
    return $this->get('translate/jobs/' . implode(',', $gengo_job_ids));
  }

  /**
   * Will approve job at gengo side.
   *
   * @param int $gengo_job_id
   *   Gengo job id.
   * @param array $data
   *   Additional data to be sent.
   *
   * @return object
   *   Gengo response data.
   */
  public function approveJob($gengo_job_id, array $data) {
    $data += array('action' => 'approve');
    return $this->put('translate/job/' . $gengo_job_id, $data);
  }

  /**
   * Submits a job for revision.
   *
   * @param int $gengo_job_id
   *   Gengo job id.
   * @param string $comment
   *   Comment to set.
   *
   * @return object
   *   Gengo response data.
   */
  public function reviseJob($gengo_job_id, $comment) {
    return $this->put('translate/job/' . $gengo_job_id, array(
      'action' => 'revise',
      'comment' => $comment,
    ));
  }

  /**
   * GET request to gengo service.
   *
   * @param string $path
   *   Resource path.
   * @param array $data
   *   URL query data.
   *
   * @return object
   *   Gengo response data.
   */
  public function get($path, $data = array()) {
    return $this->request($path, 'GET', $data);
  }

  /**
   * POST request to gengo service.
   *
   * @param string $path
   *   Resource path.
   * @param array $data
   *   Post data.
   *
   * @return object
   *   Gengo response data.
   */
  public function post($path, $data = array()) {
    return $this->request($path, 'POST', $data);
  }

  /**
   * PUT request to gengo service.
   *
   * @param string $path
   *   Resource path.
   * @param array $data
   *   PUT data.
   *
   * @return object
   *   Gengo response data.
   */
  public function put($path, $data = array()) {
    return $this->request($path, 'PUT', $data);
  }

  /**
   * Does a request to gengo services.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   HTTP method (GET, POST...)
   * @param array $data
   *   Data to send to gengo service.
   *
   * @return object
   *   Response object from gengo.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function request($path, $method, $data = array()) {
    $headers = array(
      'User-Agent' => $this->getUserAgent(),
      'Accept' => 'application/json',
    );

    $timestamp = gmdate('U');

    if (variable_get('tmgmt_mygengo_use_mock_service', FALSE)) {
      $url = $GLOBALS['base_url'] . '/tmgmt_mygengo_mock' . '/' . self::API_VERSION . '/' . $path;
    }
    elseif ($this->useSandbox) {
      $url = self::SANDBOX_URL . '/' . self::API_VERSION . '/' . $path;
    }
    else {
      $url = self::PRODUCTION_URL . '/' . self::API_VERSION . '/' . $path;
    }
    try {
      if ($method == 'GET' || $method == 'DELETE') {
        $query = array_merge(array(
          'api_key' => $this->pubKey,
          'api_sig' => hash_hmac('sha1', $timestamp, $this->privateKey),
          'ts' => $timestamp,
        ), $data);

        $url = url($url, array('query' => $query, 'absolute' => TRUE));
        $request = $this->client->createRequest($method, $url, $headers);
      }
      else {
        $data = array(
          'api_key' => $this->pubKey,
          'api_sig' => hash_hmac('sha1', $timestamp, $this->privateKey),
          'ts' => $timestamp,
          'data' => json_encode($data),
        );

        $url = url($url, array('absolute' => TRUE));
        $request = $this->client->createRequest($method, $url, $headers, $data);
      }

      if (variable_get('tmgmt_mygengo_use_mock_service', FALSE) && isset($_COOKIE['XDEBUG_SESSION'])) {
        $request->addCookie('XDEBUG_SESSION', $_COOKIE['XDEBUG_SESSION']);
      }
      $response = $request->send();

      if ($this->debug == TRUE) {
        watchdog('tmgmt_mygengo', "Sending request to gengo at @url method @method with data @data\n\nResponse: @response", array(
          '@url' => $url,
          '@method' => $method,
          '@data' => var_export($response->getRequest(), TRUE),
          '@response' => var_export($response, TRUE),
        ), WATCHDOG_DEBUG);
      }
    }
    catch (RequestException $e) {
      $status_codes = Response::$statusTexts;
      throw new TMGMTException('Unable to connect to Gengo service due to following error: @error at @url',
        array('@error' => $status_codes[$response->getStatusCode()], '@url' => $url));
    }

    $results = $response->json();

    if ($results['opstat'] == 'ok' && isset($results['response'])) {
      return $results['response'];
    }

    // Find if we have only one error or multiple.
    if (isset($results['err']['code'])) {
      $gengo_err = $results['err'];
    }
    // In case of multiple, take only the first one - they are usually the same.
    // @todo Handle multiple errors received from gengo.
    else {
      $gengo_err = reset($results['err']);
      $gengo_err = array_shift($gengo_err);
    }

    throw new TMGMTException(t('Gengo service returned error #@code @error'), array('@error' => $gengo_err['msg'], '@code' => $gengo_err['code']));
  }


  /**
   * Builds user agent info.
   *
   * @return string
   *   The user agent being.
   */
  public function getUserAgent() {
    global $base_url;

    $info = system_get_info('module', 'tmgmt');
    $tmgmt_version = !empty($info['version']) ? $info['version'] : $info['core'] . '-1.x-dev';

    $info = system_get_info('module', 'tmgmt_mygengo');
    $gengo_version = !empty($info['version']) ? $info['version'] : $info['core'] . '-1.x-dev';

    return 'Drupal TMGMT/' . $tmgmt_version . '; Gengo/' . $gengo_version . '; ' . $base_url;
  }
}
