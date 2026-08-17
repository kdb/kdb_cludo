<?php

namespace Drupal\kdb_cludo\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\kdb_cludo\CludoPushBatch;
use Drupal\kdb_cludo\Form\CludoSettingsForm;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;

/**
 * Queueing URL pushes to Cludo, instead of pushing on every entity save.
 *
 * Pushing one URL per save does not scale. A mass update of event instances -
 * such as the ones dpl_update does in its update hooks - fires thousands of
 * individual requests at Cludo, who answer 429 Too Many Requests, while the
 * deployment sits and waits for every single one of them.
 *
 * So instead we write the URLs to a queue as content is saved, which is
 * cheap, and let cron push them to Cludo in batches afterwards.
 */
class CludoPushQueue {

  /**
   * The name of the queue holding URLs waiting to be pushed.
   */
  public const QUEUE_NAME = 'kdb_cludo_url_push';

  /**
   * How many URLs we put into a single Cludo request, unless configured.
   */
  public const DEFAULT_URLS_PER_REQUEST = 100;

  /**
   * How many requests we allow ourselves per cron run, unless configured.
   */
  public const DEFAULT_REQUESTS_PER_CRON = 25;

  /**
   * How long a claimed queue item is ours, before it can be claimed again.
   *
   * This only matters if a cron run dies half way through - the items it had
   * claimed become available again once the lease runs out.
   */
  public const LEASE_TIME = 300;

  /**
   * The config, saved through CludoSettingsForm.
   */
  private ImmutableConfig $config;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected LoggerInterface $logger,
    protected CludoApiService $apiService,
    protected QueueFactory $queueFactory,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->config = $configFactory->get(CludoSettingsForm::CONFIG_SETTINGS_KEY);
  }

  /**
   * The queue holding the URLs waiting to be pushed.
   */
  public function getQueue(): QueueInterface {
    return $this->queueFactory->get(self::QUEUE_NAME);
  }

  /**
   * How many URLs are currently waiting to be pushed to Cludo.
   */
  public function getPendingCount(): int {
    return $this->getQueue()->numberOfItems();
  }

  /**
   * Queueing an entity URL, to be pushed to Cludo on one of the next crons.
   *
   * The URL and crawler are resolved now, rather than when the queue is
   * processed - by then, the entity may have been deleted.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose canonical URL should be pushed.
   * @param bool $delete
   *   Whether the URL should be un-indexed, rather than indexed.
   *
   * @return bool
   *   Whether the URL was queued.
   */
  public function addEntity(FieldableEntityInterface $entity, bool $delete = FALSE): bool {
    if (!$this->apiService->isUrlPushingEnabled()) {
      return FALSE;
    }

    $crawlerId = $this->apiService->getCrawlerId($entity);

    if (!$crawlerId) {
      return FALSE;
    }

    try {
      $url = $entity->toUrl()->setAbsolute()->toString();
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not queue @type @uuid for Cludo pushing: @message', [
        '@type' => $entity->getEntityTypeId(),
        '@uuid' => $entity->uuid(),
        '@message' => $e->getMessage(),
      ]);

      return FALSE;
    }

    $this->getQueue()->createItem([
      'url' => $url,
      'crawler_id' => $crawlerId,
      'delete' => $delete,
    ]);

    return TRUE;
  }

  /**
   * Pushing queued URLs to Cludo, in as few requests as we can get away with.
   *
   * We deliberately do not empty the queue in one go - we stop after a set
   * number of requests, and leave the rest for the next cron run. That keeps
   * a large backlog from turning into a burst that Cludo rate limits.
   *
   * @return int
   *   The number of URLs pushed.
   */
  public function processQueue(): int {
    if (!$this->apiService->isAvailable() || !$this->apiService->isUrlPushingEnabled()) {
      return 0;
    }

    $queue = $this->getQueue();
    [$items, $malformed] = $this->filterValidItems($this->claimItems($queue));

    // Nothing we can do with these, and leaving them would mean claiming them
    // again on every single cron run.
    if (!empty($malformed)) {
      $this->logger->warning('Dropping @count malformed item(s) from the Cludo push queue.', [
        '@count' => count($malformed),
      ]);

      $this->deleteItems($queue, $malformed);
    }

    $batches = $this->buildBatches($items);
    $pushed = 0;

    foreach ($batches as $index => $batch) {
      try {
        $this->apiService->pushUrls($batch->urls, $batch->crawlerId, $batch->delete);

        $this->deleteItems($queue, $batch->items);
        $pushed += count($batch->urls);
      }
      catch (\Throwable $e) {
        $status = ($e instanceof BadResponseException) ?
          $e->getResponse()->getStatusCode() :
          NULL;

        // Anything but rate limiting in the 4xx range means Cludo is never
        // going to accept these URLs. Keeping them would block the rest of
        // the queue forever, so we drop them and move on.
        if ($status && $status < 500 && $status !== 429) {
          $this->logger->error('Cludo rejected @count URL(s) with status @status - dropping them from the queue. Message: @message', [
            '@count' => count($batch->urls),
            '@status' => $status,
            '@message' => $e->getMessage(),
          ]);

          $this->deleteItems($queue, $batch->items);
          continue;
        }

        // We are being rate limited, or Cludo is having a bad day. Put back
        // everything we have not pushed yet, and try again on the next cron.
        $released = $this->releaseBatches($queue, array_slice($batches, $index));

        $this->logger->warning('Cludo URL pushing paused after @pushed URL(s) - @released URL(s) put back in the queue. Message: @message', [
          '@pushed' => $pushed,
          '@released' => $released,
          '@message' => $e->getMessage(),
        ]);

        return $pushed;
      }
    }

    return $pushed;
  }

  /**
   * Claiming as many queue items as we are allowed to push in one run.
   *
   * @return \stdClass[]
   *   The claimed queue items.
   */
  private function claimItems(QueueInterface $queue): array {
    $limit = $this->getUrlsPerRequest() * $this->getRequestsPerCron();
    $items = [];

    while (count($items) < $limit) {
      $item = $queue->claimItem(self::LEASE_TIME);

      // Queue items are plain data objects - the interface just types them
      // loosely, as the storage backend decides what they look like.
      if (!($item instanceof \stdClass)) {
        break;
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * Splitting claimed items into the usable ones, and the rest.
   *
   * @param \stdClass[] $items
   *   The claimed queue items.
   *
   * @return array{0: \stdClass[], 1: \stdClass[]}
   *   The valid items, and the malformed ones.
   */
  private function filterValidItems(array $items): array {
    $valid = [];
    $malformed = [];

    foreach ($items as $item) {
      $data = $item->data ?? NULL;

      if (is_array($data) && !empty($data['url']) && !empty($data['crawler_id'])) {
        $valid[] = $item;
      }
      else {
        $malformed[] = $item;
      }
    }

    return [$valid, $malformed];
  }

  /**
   * Grouping queue items into the requests we are going to make.
   *
   * Each batch becomes exactly one Cludo request, so items can be deleted -
   * or put back - as a unit. Items are grouped by crawler and operation, as
   * those decide which endpoint they go to, and duplicate URLs are collapsed:
   * saving 200 instances of the same event series should push the series once,
   * not 200 times.
   *
   * @param \stdClass[] $items
   *   The claimed queue items, as returned by filterValidItems().
   *
   * @return \Drupal\kdb_cludo\CludoPushBatch[]
   *   The batches, in the order they should be pushed.
   */
  private function buildBatches(array $items): array {
    $urlsPerRequest = $this->getUrlsPerRequest();
    $batches = [];
    // Group key ("push:10904") => index of the batch currently being filled.
    $open = [];
    // Group key + URL => index of the batch that already holds the URL.
    $seen = [];

    foreach ($items as $item) {
      $url = (string) $item->data['url'];
      $crawlerId = (string) $item->data['crawler_id'];
      $delete = !empty($item->data['delete']);
      $groupKey = ($delete ? 'delete' : 'push') . ":$crawlerId";

      // The URL is already going out in this run. Attach the item anyway, so
      // it gets cleared from the queue along with the rest of the batch.
      if (isset($seen["$groupKey:$url"])) {
        $batches[$seen["$groupKey:$url"]]->addDuplicate($item);
        continue;
      }

      $index = $open[$groupKey] ?? NULL;

      if ($index === NULL || !$batches[$index]->hasRoomFor($urlsPerRequest)) {
        $batches[] = new CludoPushBatch($crawlerId, $delete);

        $index = array_key_last($batches);
        $open[$groupKey] = $index;
      }

      $batches[$index]->add($url, $item);
      $seen["$groupKey:$url"] = $index;
    }

    return $batches;
  }

  /**
   * Removing handled items from the queue.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue to remove from.
   * @param \stdClass[] $items
   *   The queue items to remove.
   */
  private function deleteItems(QueueInterface $queue, array $items): void {
    foreach ($items as $item) {
      $queue->deleteItem($item);
    }
  }

  /**
   * Putting unhandled items back in the queue, for the next cron run.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue to put the items back into.
   * @param \Drupal\kdb_cludo\CludoPushBatch[] $batches
   *   The batches that were not pushed.
   *
   * @return int
   *   The number of items put back.
   */
  private function releaseBatches(QueueInterface $queue, array $batches): int {
    $released = 0;

    foreach ($batches as $batch) {
      foreach ($batch->items as $item) {
        $queue->releaseItem($item);
        $released++;
      }
    }

    return $released;
  }

  /**
   * How many URLs we are allowed to put into a single Cludo request.
   */
  private function getUrlsPerRequest(): int {
    $value = (int) $this->config->get('push_urls_per_request');

    return ($value > 0) ? $value : self::DEFAULT_URLS_PER_REQUEST;
  }

  /**
   * How many Cludo requests we are allowed to make in a single cron run.
   */
  private function getRequestsPerCron(): int {
    $value = (int) $this->config->get('push_requests_per_cron');

    return ($value > 0) ? $value : self::DEFAULT_REQUESTS_PER_CRON;
  }

}
