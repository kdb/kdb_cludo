<?php

namespace Drupal\kdb_cludo\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\drupal_typed\DrupalTyped;
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
   * Building the queue without going through our own service definition.
   *
   * Drupal does not rebuild the service container just because a module's
   * services.yml gained a service, and `drush deploy` runs update hooks
   * before it rebuilds caches. On the deployment that introduces
   * kdb_cludo.push_queue, update hooks can therefore save thousands of
   * entities while the container still knows nothing about the service.
   *
   * Everything the queue needs has been in the container for releases, so we
   * can build it by hand until the container catches up.
   *
   * @see _kdb_cludo_push_queue()
   */
  public static function createFromContainer(): self {
    return new self(
      DrupalTyped::service(LoggerInterface::class, 'kdb_cludo.logger'),
      DrupalTyped::service(CludoApiService::class, 'kdb_cludo.cludo_api'),
      DrupalTyped::service(QueueFactory::class, 'queue'),
      DrupalTyped::service(ConfigFactoryInterface::class, 'config.factory'),
    );
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
        // pushUrls() throws on 4xx/5xx - FALSE is the odd case of a response
        // that is not an outright error, but not a success either. Treat it
        // like a 5xx: put the URLs back, and let a later cron retry them.
        if (!$this->apiService->pushUrls($batch->urls, $batch->crawlerId, $batch->delete)) {
          throw new \RuntimeException('Cludo did not accept the pushed URLs.');
        }

        $this->deleteItems($queue, $batch->items);
        $pushed += count($batch->urls);
      }
      catch (\Throwable $e) {
        // Cludo rejected the batch outright - but with several URLs in the
        // same request, the response does not tell us which of them it
        // objects to. Retry them one at a time, so only the URLs Cludo
        // actually rejects get dropped; the rest still go through.
        if ($this->getRejectionStatus($e) !== NULL) {
          try {
            $this->retryIndividually($queue, $batch, $pushed);
            continue;
          }
          catch (\Throwable $e) {
            // The retries were interrupted by rate limiting or a server
            // error. The batch's unhandled items are back in $batch->items,
            // so they are released below, along with the later batches.
          }
        }

        // We are being rate limited, Cludo is having a bad day, or our
        // credentials are not being accepted. Put back everything we have
        // not pushed yet, and try again on the next cron.
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
   * Pushing a rejected batch's URLs one at a time.
   *
   * Used when Cludo rejects a whole batch: its response does not say which
   * URL it objects to, so each URL is retried in its own request. The URLs
   * Cludo rejects individually are dropped and logged; the rest go through.
   *
   * If the retries get rate limited (or hit a server error), the exception
   * bubbles up to stop the run - with the batch's unhandled items left in
   * $batch->items, ready to be released back into the queue.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue the batch's items came from.
   * @param \Drupal\kdb_cludo\CludoPushBatch $batch
   *   The rejected batch.
   * @param int $pushed
   *   The run's tally of pushed URLs. By reference, so the URLs pushed
   *   before an interruption still count.
   */
  private function retryIndividually(QueueInterface $queue, CludoPushBatch $batch, int &$pushed): void {
    $itemsByUrl = [];

    foreach ($batch->items as $item) {
      $itemsByUrl[(string) $item->data['url']][] = $item;
    }

    while (!empty($itemsByUrl)) {
      $url = (string) array_key_first($itemsByUrl);

      try {
        if (!$this->apiService->pushUrls([$url], $batch->crawlerId, $batch->delete)) {
          throw new \RuntimeException('Cludo did not accept the pushed URL.');
        }

        $pushed++;
      }
      catch (\Throwable $e) {
        $status = $this->getRejectionStatus($e);

        if ($status === NULL) {
          $batch->items = array_merge([], ...array_values($itemsByUrl));

          throw $e;
        }

        $this->logger->error('Cludo rejected @url with status @status - dropping it from the queue. Message: @message', [
          '@url' => $url,
          '@status' => $status,
          '@message' => $e->getMessage(),
        ]);
      }

      // Pushed, or rejected and dropped - either way the URL is settled,
      // and its queue items are done.
      $this->deleteItems($queue, $itemsByUrl[$url]);
      unset($itemsByUrl[$url]);
    }
  }

  /**
   * The status code of a Cludo rejection - if that is what the exception is.
   *
   * A rejection is a response telling us Cludo understood the request and
   * turned it down - retrying later would not change its mind. That is the
   * 4xx range, with two exceptions: 429 is explicitly a "try again later",
   * and 401/403 mean our credentials are not accepted, which is a
   * configuration problem rather than a URL problem. Those - like 5xx and
   * network errors - are worth retrying, so they do not count as rejections.
   *
   * @param \Throwable $e
   *   The exception a push attempt threw.
   *
   * @return int|null
   *   The status code, or NULL if the request is worth retrying.
   */
  private function getRejectionStatus(\Throwable $e): ?int {
    if (!($e instanceof BadResponseException)) {
      return NULL;
    }

    $status = $e->getResponse()->getStatusCode();

    if ($status >= 500 || in_array($status, [401, 403, 429], TRUE)) {
      return NULL;
    }

    return $status;
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
   * A URL may also be queued with conflicting operations - unpublished, then
   * republished, say. Only the newest operation is sent to Cludo; the older
   * items just tag along, so they get cleared from the queue with the batch.
   *
   * @param \stdClass[] $items
   *   The claimed queue items, as returned by filterValidItems().
   *
   * @return \Drupal\kdb_cludo\CludoPushBatch[]
   *   The batches, in the order they should be pushed.
   */
  private function buildBatches(array $items): array {
    $urlsPerRequest = $this->getUrlsPerRequest();

    // URL key ("10904:https://...") => whether the URL should be deleted.
    // Items are claimed in the order they were queued, so later operations
    // overwrite earlier ones, and the URL's newest operation wins.
    $finalDelete = [];

    foreach ($items as $item) {
      $finalDelete[$item->data['crawler_id'] . ':' . $item->data['url']] = !empty($item->data['delete']);
    }

    $batches = [];
    // Group key ("push:10904") => index of the batch currently being filled.
    $open = [];
    // URL key => index of the batch that already holds the URL.
    $seen = [];

    foreach ($items as $item) {
      $url = (string) $item->data['url'];
      $crawlerId = (string) $item->data['crawler_id'];
      $urlKey = "$crawlerId:$url";
      $delete = $finalDelete[$urlKey];
      $groupKey = ($delete ? 'delete' : 'push') . ":$crawlerId";

      // The URL is already going out in this run. Attach the item anyway, so
      // it gets cleared from the queue along with the rest of the batch.
      if (isset($seen[$urlKey])) {
        $batches[$seen[$urlKey]]->addDuplicate($item);
        continue;
      }

      $index = $open[$groupKey] ?? NULL;

      if ($index === NULL || !$batches[$index]->hasRoomFor($urlsPerRequest)) {
        $batches[] = new CludoPushBatch($crawlerId, $delete);

        $index = array_key_last($batches);
        $open[$groupKey] = $index;
      }

      $batches[$index]->add($url, $item);
      $seen[$urlKey] = $index;
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
