<?php

namespace Drupal\quant_purger\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\quant_purger\TrafficRegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$reflection = new \ReflectionClass(HttpKernelInterface::class);
$method = $reflection->getMethod('handle');

// In Drupal10+ HttpKernelInterface has been updated with
// Symfony to have a different signature.
//
// @see https://github.com/symfony/symfony/blob/6.4/src/Symfony/Component/HttpKernel/HttpKernelInterface.php.
if (empty($method->getReturnType())) {

  /**
   * Collects URLs that Quant has requested.
   */
  class UrlRegistrar implements HttpKernelInterface {

    use TraitUrlRegistrar;

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
     * {@inheritdoc}
     */
    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = TRUE) {
      $response = $this->httpKernel->handle($request, $type, $catch);
      if ($this->determine($request, $response)) {
        $this->registry->add(
          $this->generateUrl($request),
          $this->getAcceptedCacheTags($response->getCacheableMetadata()->getCacheTags()),
        );
      }
      return $response;
    }

  }

}
else {
  /**
   * Collects URLs that Quant has requested.
   */
  class UrlRegistrar implements HttpKernelInterface {

    use TraitUrlRegistrar;

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
     * {@inheritdoc}
     */
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
      $response = $this->httpKernel->handle($request, $type, $catch);
      if ($this->determine($request, $response)) {
        $this->registry->add(
          $this->generateUrl($request),
          $this->getAcceptedCacheTags($response->getCacheableMetadata()->getCacheTags()),
        );
      }
      return $response;
    }

  }
}
