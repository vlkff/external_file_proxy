<?php

namespace Drupal\external_file_proxy;

use Drupal\Core\Url;
use Psr\Log\InvalidArgumentException;
use Drupal\Core\Entity\EntityTypeManager;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeExtensionGuesser;
use Drupal\media_entity\Entity\Media;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service class for external_file_proxy.
 */
class ExternalFileProxy {

  /**
   * Media entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaStorage;

  /**
   * HTTP Client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Media entity bundle name to keep cached files.
   */
  const MEDIA_BUNDLE = 'external_file_proxy';

  /**
   * Directory to keep cached files.
   */
  const FILES_DIR = 'public://external_file_proxy';

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   EntityTypeManager service.
   * @param \GuzzleHttp\Client $http_client
   *   HTTP client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   Logger factory service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache service.
   */
  public function __construct(EntityTypeManager $entity_type_manager, Client $http_client, LoggerChannelFactory $logger_factory, CacheBackendInterface $cache) {
    $this->mediaStorage = $entity_type_manager->getStorage('media');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('external_file_proxy');
    $this->cache = $cache;
  }

  /**
   * For external url return an internal path to proxy it to local cached file.
   *
   * @param \Drupal\Core\Url $url
   *   External url to build a proxy url for.
   *
   * @return \Drupal\Core\Url
   *   Built proxy url.
   */
  public function getProxyUrl(Url $url) {
    if (!$url->isExternal()) {
      throw new InvalidArgumentException('External url expected');
    }

    $encoded = base64_encode($url->toString());
    return Url::fromUri('base://external-file-proxy/proxy/' . $encoded);
  }

  /**
   * Look for a media entity by passed external url.
   *
   * @param \Drupal\Core\Url $url
   *   External file uri.
   *
   * @return \Drupal\media_entity\Entity\Media
   *   Media entity or FALSE.
   */
  public function getMediaByUrl(Url $url) {
    if (!$url->isExternal()) {
      throw new InvalidArgumentException('External url expected');
    }

    $uri = $url->toString();
    $media = $this->mediaStorage->loadByProperties([
      'bundle' => self::MEDIA_BUNDLE,
      'field_efp_origin_url.uri' => $uri
    ]);

    if (!empty($media)) {
      return reset($media);
    }

    return FALSE;
  }

  /**
   * Look for a local cached file uri by passed external url.
   *
   * @param \Drupal\Core\Url $url
   *   External file url.
   *
   * @return \Drupal\Core\Url
   *   Local file uri or FALSE.
   */
  public function getCachedUri(Url $url) {
    if (!$url->isExternal()) {
      throw new InvalidArgumentException('External url expected');
    }

    if ($cache = $this->cache->get($this->cid($url))) {
      return $cache->data;
    }

    $media = $this->getMediaByUrl($url);
    if (!empty($media)) {
      /* @var \Drupal\file\Entity\File $file */
      $file = $media->field_efp_file->entity;
      return $file->getFileUri();
    }
    return FALSE;
  }

  /**
   * Create media entity with attached cached file by external file url.
   *
   * @param \Drupal\Core\Url $url
   *   External file url.
   *
   * @return \Drupal\media_entity\Entity\Media
   *   Media entity or FALSE.
   */
  public function createMediaForUri(Url $url) {

    if (!$url->isExternal()) {
      throw new InvalidArgumentException('External url expected');
    }

    $file = $this->fetchFile($url, self::FILES_DIR);
    if (!$file) {
      return FALSE;
    }

    $data = [
      'name' => $file->label(),
      'bundle' => self::MEDIA_BUNDLE,
      'field_efp_origin_url' => [
        'uri' => $url->toString(),
      ],
      'field_efp_file' => [
        'target_id' => $file->id(),
      ],
      'status' => 1,
    ];
    $media = $this->mediaStorage->create($data);
    $media->save();

    $media->getCacheTags();

    // Set cache.
    $this->cache->set($this->cid($url),
      $file->getFileUri(),
      CacheBackendInterface::CACHE_PERMANENT,
      $media->getCacheTags()
    );

    // Put media to queue for removing in future by cron.
    $this->putMediaToRemoveQueue($media);

    return $media;
  }

  /**
   * Get file uri for media.
   *
   * @param \Drupal\media_entity\Entity\Media $media
   *   Media entity.
   *
   * @return string
   *   File uri.
   */
  public function getMediaCachedFileUri(Media $media) {
    $file = $media->field_efp_file->entity;
    return $file->getFileUri();
  }

  /**
   * Create media entity with attached cached file by external file url in background.
   *
   * @param \Drupal\Core\Url $url
   *   External file url.
   * @param string $on_success
   *   Success callback name.
   * @param string $on_error
   *   Error callback name.
   */
  protected function createMediaForUriInBackground(Url $url, $on_success, $on_error) {
    // ToDo: implement it using GuzzleHttp::sendAsync()
  }

  /**
   * Fetch a remote file to local filesystem and create a file entity.
   *
   * @param \Drupal\Core\Url $remote_file_url
   *   File url to fetch.
   * @param string $dest_dir
   *   Destination directory uri.
   * @param string $dest_name
   *   Destination file name.
   *
   * @return \Drupal\file\FileInterface
   *   A file entity, or FALSE on error.
   */
  protected function fetchFile(Url $remote_file_url, $dest_dir = 'temporary://', $dest_name = NULL) {
    // Prepare local target directory.
    if (!file_prepare_directory($dest_dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
      $this->logger->error('Unable to prepare local directory @path.', ['@path' => $dest_dir]);
      return FALSE;
    }

    // Ensure $dest_dir trailing slash is in place.
    if (substr($dest_dir, '-2') !== '//') {
      if (substr($dest_dir, '-1') !== '/') {
        $dest_dir .= '/';
      }
    }

    try {
      $uri = $remote_file_url->toString();
      $headers = [
        'Accept' => 'text/plain',
        'Connection' => 'close',
      ];
      $response = $this->httpClient->get($uri, ['headers' => $headers]);
      if ($response->getStatusCode() != 200) {
        $this->logger->error('HTTP error @errorcode occurred when trying to fetch @remote.', [
          '@errorcode' => $response->getStatusCode(),
          '@remote' => $uri,
        ]);
        return FALSE;
      }

      if ($response->getHeaderLine('Content-Type') == 'text/html') {
        $this->logger->error('text/html returned instead of file when trying to fetch @remote.', [
          '@remote' => $uri,
        ]);
        return FALSE;
      }

      $data = (string) $response->getBody();
      if (empty($data)) {
        $this->logger->error('Empty body returned instead of file when trying to fetch @remote.', [
          '@remote' => $uri,
        ]);
        return FALSE;
      }

      // If passed destination filename is empty we try to get it from headers at list the extension.
      if (empty($dest_name)) {
        // Guess extens ion.
        $file_ext = '';
        if ($content_type = $response->getHeaderLine('Content-Type')) {
          $mime_type_extension_guesser = new MimeTypeExtensionGuesser();
          foreach ($this->parseHeaderLine($content_type) as $value) {
            if ($file_ext = $mime_type_extension_guesser->guess($value)) {
              break;
            }
          }
        }

        // Guess filename.
        $file_name = '';
        if ($content_disposition = $response->getHeaderLine('Content-Disposition')) {
          foreach ($this->parseHeaderLine($content_disposition) as $key => $value) {
            if ($key === 'filename' && !empty($value)) {
              $file_name = $value;
              break;
            }
          }
        }

        // Build filename.
        if (!empty($file_name)) {
          $dest_name = $file_name;
        }
        else {
          $dest_name = md5($data);
          if (!empty($file_ext)) {
            $dest_name .= $file_ext;
          }
        }

      }
      // END file name building.
    }
    catch (Exception $e) {
      $this->logger->error('Error @error occurred when trying to fetch @remote.', [
        '@error' => $e->getMessage(),
        '@remote' => $uri,
      ]);
      return FALSE;
    }

    // Now when we pulled the file data and know it's name create it.
    $file = file_save_data($data, $dest_dir . $dest_name, FILE_EXISTS_RENAME);

    return $file;
  }

  /**
   * Helper to parse HTTP response header.
   *
   * For example it would parse following:
   *   Content-Disposition: form-data; name="AttachedFile1"; filename="photo-1.jpg"
   * To:
   *  [
   *    'Content-Disposition' => 'form-data',
   *    'name' => 'AttachedFile1',
   *    'filename' => 'photo-1.jpg"
   *  ]
   *
   * @param string $val
   *   Header line to parse.
   *
   * @return array
   *   Parsed values.
   */
  protected function parseHeaderLine($val) {
    $parsed = [];
    $lines = explode(';', $val);
    foreach ($lines as $line) {
      $line = str_replace('"', '', $line);
      $line = str_replace("'", "'", $line);
      if (strpos($line, '=')) {
        $exploded = explode('=', $line);
        $parsed[trim($exploded[0])] = trim($exploded[1]);
      }
      elseif (strpos($line, ':')) {
        $exploded = explode(':', $line);
        $parsed[trim($exploded[0])] = trim($exploded[1]);
      }
      else {
        $parsed[] = trim($line);
      }
    }
    return $parsed;
  }

  /**
   * Create cache id for external url.
   *
   * @param string $url
   *   External url.
   *
   * @return string
   *   Cache id
   */
  protected function cid($url) {
    if ($url instanceof Url) {
      $url = $url->toString();
    }
    return 'exterlal_file_proxy:' . md5($url);
  }

  /**
   * Put media entity to a queue for removing in future.
   *
   * @param \Drupal\media_entity\Entity\Media $media
   *   Media entity.
   */
  protected function putMediaToRemoveQueue(Media $media) {
    $queue = \Drupal::queue('external_file_proxy_remove_expired');
    $item = new \stdClass();
    $item->mid = $media->id();
    $item->expire = new \DateTime('+1 day');
    $queue->createItem($item);
  }

}
