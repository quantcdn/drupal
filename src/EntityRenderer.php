<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

// @todo: Remove superfluous services.
// @todo: Remove unused use statements.

/**
 * The entity renderer service for Quant.
 */
class EntityRenderer implements EntityRendererInterface {

  use StringTranslationTrait;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The entity manager object.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Build the entity renderer service.
   *
   * @param Drupal\Core\Config\ConfigFactory $config_factory
   *   The configuration factory.
   */
  public function __construct(ConfigFactory $config_factory, EntityManagerInterface $entity_manager) {
    $this->configFactory = $config_factory;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function render(EntityInterface $entity) : string {
    // Note this is node only.
    // @todo: Rename to something node-specific (extensible to other entities?)
    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;
    $url = $entity->toUrl()->toString();

    // Build internal request.
    $config = $this->configFactory->get('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host . $url;

    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] : [];

    $response = \Drupal::httpClient()->get($url, [
      'http_errors' => FALSE,
      'query' => ['quant_revision' => $rid],
      'headers' => [
        'Host' => $hostname,
      ],
      'auth' => $auth,
    ]);

    if ($response->getStatusCode() == 200) {
      return $response->getBody();
    }

    $messenger = \Drupal::messenger();
    $messenger->addMessage('Quant error: ' . $response->getStatusCode(), $messenger::TYPE_WARNING);
    $messenger->addMessage('Quant error: ' . $response->getBody(), $messenger::TYPE_WARNING);

    return '';
  }

}
