<?php

namespace Drupal\external_file_proxy\Plugin\QueueWorker;

/**
 * A RemoveExpired worker that remove expired media on cron run.
 *
 * @QueueWorker(
 *   id = "external_file_proxy_remove_expired",
 *   title = @Translation("External file proxy: remove expired medias on cron"),
 *   cron = {"time" = 10}
 * )
 */
class RemoveExpiredWorker extends RemoveExpiredWorkerBase {

}
