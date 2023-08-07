<?php

namespace Drupal\quant_tome\EventSubscriber;

use Drupal\Core\File\FileSystemInterface;
use Drupal\tome_static\StaticGeneratorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes to the _redirects file when redirects are generated.
 */
class RedirectSubscriber implements EventSubscriberInterface {

  /**
   * The static generator.
   *
   * @var \Drupal\tome_static\StaticGeneratorInterface
   */
  protected $staticGenerator;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a RedirectSubscriber object.
   *
   * @param \Drupal\tome_static\StaticGeneratorInterface $static_generator
   *   The static generator.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   */
  public function __construct(StaticGeneratorInterface $static_generator, FileSystemInterface $file_system) {
    $this->staticGenerator = $static_generator;
    $this->fileSystem = $file_system;
  }

  /**
   * Reacts to a response event.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event.
   */
  public function onResponse(ResponseEvent $event) {
    $response = $event->getResponse();
    $request = $event->getRequest();
    if ($request->attributes->has(StaticGeneratorInterface::REQUEST_KEY) && $response instanceof RedirectResponse) {
      $base_dir = $this->staticGenerator->getStaticDirectory();
      $this->fileSystem->prepareDirectory($base_dir, FileSystemInterface::CREATE_DIRECTORY);
      file_put_contents("$base_dir/_redirects", $request->getPathInfo() . ' ' . $response->getTargetUrl() . "\n", FILE_APPEND);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onResponse'];
    return $events;
  }

}
