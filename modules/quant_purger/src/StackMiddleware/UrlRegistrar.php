<?php

namespace Drupal\quant_purger\StackMiddleware;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\quant_purger\TrafficRegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Collects URLs that Quant has requested.
 */
class UrlRegistrar implements HttpKernelInterface {

  /**
   * The HTTP kernel object.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The traffic registry service.
   *
   * @var \Drupal\quant_purger\TrafficRegistryInterface
   */
  protected $registry;

  /**
   * The configuration object for quant purger.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  public function __construct(HttpKernelInterface $http_kernel, TrafficRegistryInterface $registry, ConfigFactoryInterface $config_factory) {
    $this->httpKernel = $http_kernel;
    $this->registry = $registry;
    $this->config = $config_factory->get('quant_purger.settings');
  }

  /**
   * Determine if we need to track this route.
   *
   * @return bool
   *   If the request can be cached.
   */
  public function determine(Request $request, Response $response) {
    // Don't gather responses that don't have a quant token. As this
    // is a HTTP middleware we need to make sure this is as lean as
    // possible - we don't want to add a huge performance burden to
    // begin tracking pages to cachetags.
    if (!$request->headers->has('quant-token')) {
      return FALSE;
    }

    // Allow paths to be excluded from the traffic repository.
    $blocklist = $this->config->get('path_blocklist');
    if (is_array($blocklist)) {
      $path = $this->generateUrl($request);
      foreach ($blocklist as $needle) {
        if (@strpos($path, $needle) > -1) {
          return FALSE;
        }
      }
    }

    if (!is_a($response, CacheableResponseInterface::class)) {
      return FALSE;
    }

    // Don't gather responses that aren't going to be useful.
    if (!count($response->getCacheableMetadata()->getCacheTags())) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Generates a URL to register.
   *
   * @return string
   *   The URL to register.
   */
  protected function generateUrl(Request $request) {
    if (NULL !== $qs = $request->getQueryString()) {
      $qs = '?' . $qs;
    }
    $path = $request->getBaseUrl() . $request->getPathInfo() . $qs;
    return '/' . ltrim($path, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = TRUE) {
    $response = $this->httpKernel->handle($request, $type, $catch);
    if ($this->determine($request, $response)) {
      $this->registry->add(
        $this->generateUrl($request),
        $response->getCacheableMetadata()->getCacheTags()
      );
    }
    return $response;
  }

}
