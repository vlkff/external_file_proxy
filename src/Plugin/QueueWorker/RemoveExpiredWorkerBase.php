<?php

namespace Drupal\external_file_proxy\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\media_entity\Entity\Media;
use Drupal\Core\Entity\EntityTypeManager;

/**
 * Provides functionality for the external_file_proxy_remove_expired queue worker.
 *
 * @see \Drupal\external_file_proxy\ExternalFileProxy::putMediaToRemoveQueue()
 */
abstract class RemoveExpiredWorkerBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Media entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaStorage;

  /**
   * ReportWorkerBase constructor.
   *
   * @param array $configuration
   *   The configuration of the instance.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManager $manager
   *   The state service the instance should use.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManager $manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->mediaStorage = $manager->getStorage('media');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * Check is item is expired.
   *
   * @param object $item
   *   The $item which was stored in the cron queue.
   *
   * @return bool
   *   TRUE if the item cache lifetime is expired.
   */
  protected function isItemExpired($item) {
    $now = new \DateTime();
    return $item->expired < $now;
  }

  /**
   * Delete media entity.
   *
   * @param \Drupal\media_entity\Entity\Media $media
   *   Media to remove.
   */
  protected function removeMedia(Media $media) {
    $media->field_efp_file->entity->delete();
    $media->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    if ($this->isItemExpired($item)) {
      $media = $this->mediaStorage->load($item->mid);
      if ($media) {
        $this->removeMedia($media);
      }
    }
  }

}
