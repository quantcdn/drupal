<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\quant\Exception\ExpiredTokenException;
use Drupal\quant\Exception\InvalidTokenException;
use Drupal\quant\Exception\StrictTokenException;
use Drupal\quant\Exception\TokenValidationDisabledException;
use Drupal\quant\TokenManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class TokenAccessSubscriber implements EventSubscriberInterface {

  /**
   * The token manager.
   *
   * @var \Drupal\quant\TokenManager
   */
  protected $tokenManager;

  protected $config;

  /**
   * {@inheritdoc}
   */
  public function __construct(TokenManager $token_manager, ConfigFactoryInterface $config_factory) {
    $this->tokenManager = $token_manager;
    $this->config = $config_factory->get('quant.token_settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['validateToken'];
    return $events;
  }

  /**
   * Validate the token on the incoming request.
   */
  public function validateToken(GetResponseEvent $event) {
    /* @var $request Symfony\Component\HttpFoundation\Request */
    $request = $event->getRequest();

    if (!$request->headers->has('quant-token')) {
      return;
    }

    try {
      \Drupal::service('quant.token_manager')->validate($request->getPathInfo(), $this->config->get('strict'));
    }
    catch (TokenValidationDisabledException $e) {
      // Allow access when token validation is disabled.
    }
    catch (ExpiredTokenException $e) {
      throw new ServiceUnavailableHttpException(NULL, t('Token request: time mismatch. Received [:token_time] expected [:server_time]', [
        ':token_time' => $e->getTime(),
        ':server_time' => $e->getServerTime(),
      ]));
    }
    catch (StrictTokenException $e) {
      throw new ServiceUnavailableHttpException(NULL, t('Token request: route mismatch. Received [:route] expected [:expected]', [
        ':route' => $e->getTokenRoute(),
        ':expected' => $e->getExpectedRoute(),
      ]));
    }
    catch (InvalidTokenException $e) {
      throw new ServiceUnavailableHttpException(NULL, t('Token request: Invalid token'));
    }
  }

}
