<?php

namespace Drupal\external_file_proxy\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\external_file_proxy\ExternalFileProxy;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Controller class for external_file_proxy module.
 */
class ExternalFileProxyController implements ContainerInjectionInterface {

  /**
   * ExternalFileProxy service.
   *
   * @var \Drupal\external_file_proxy\ExternalFileProxy
   */
  private $externalFileProxy;

  /**
   * Filesystem service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  private $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('external_file_proxy'),
      $container->get('file_system')
    );
  }

  /**
   * Class constructor.
   */
  public function __construct(ExternalFileProxy $external_file_proxy, FileSystem $filesystem) {
    $this->externalFileProxy = $external_file_proxy;
    $this->fileSystem = $filesystem;
  }

  /**
   * Handle request to download external file.
   *
   * Create response object to download a local version of cached file or redirect
   * to requested url.
   *
   * @param string $uri
   *   Base 64 encoded external file url.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response object.
   */
  public function proxy($uri) {
    $uri = base64_decode($uri);
    $url = Url::fromUri($uri);

    if (!$url->isExternal()) {
      throw new InvalidArgumentException('External url expected');
    }

    // Lookup for existed cached file in media entity.
    $response_file_uri = $this->externalFileProxy->getCachedUri($url);
    if (empty($response_file_uri)) {
      // Create media entity with cached file.
      $media = $this->externalFileProxy->createMediaForUri($url);
      if ($media) {
        // Response with just cached file.
        $response_file_uri = $this->externalFileProxy->getMediaCachedFileUri($media);
      }
      else {
        return $this->redirect($url->toString());
      }
    }

    return $this->download($response_file_uri);
  }

  /**
   * Return response to transfer file.
   *
   * @param string $uri
   *   File uri.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   Response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the requested file does not exist.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the user does not have access to the file.
   */
  protected function download($uri) {
    if (file_exists($uri)) {
      $headers = [
        'Content-Description' => 'File Transfer',
        'Content-Disposition' => 'attachment; filename="' . $this->fileSystem->basename($uri) . '"',
      ];
      return new BinaryFileResponse($uri, 200, $headers);
    }
    else {
      throw new NotFoundHttpException();
    }

  }

  /**
   * Return response to redirect user to external file.
   *
   * @param string $url
   *   Internal or external url to redirect to.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   Response object.
   */
  protected function redirect($url) {
    $real_url = file_create_url($url);
    $response = new TrustedRedirectResponse($real_url);

    // We don't want to cache response to have a chance to cache a file the next time.
    $cachebility = new CacheableMetadata();
    $cachebility->setCacheMaxAge(0);
    $response->addCacheableDependency($cachebility);

    return $response;
  }

}
