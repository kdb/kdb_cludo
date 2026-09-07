<?php

namespace Drupal\kdb_cludo\EventSubscriber;

use Drupal\kdb_cludo\Services\CludoPushQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pushing an editor's changes to Cludo, without making the editor wait.
 *
 * URLs are queued on save, and cron pushes them in batches - that protects
 * deployments and other bulk updates from firing thousands of requests at
 * Cludo. But an editor who saves a single page should not have to wait for
 * cron before it shows up in the search results.
 *
 * So when a web request has queued something, we drain part of the queue on
 * kernel.terminate. By then the response has already been sent (PHP-FPM
 * finishes the request before terminate runs), so the editor is not held up,
 * and the queue takes care of batching and deduplication - an editor saving
 * an event series with 200 instances still only costs a handful of requests.
 *
 * The queue is first-in-first-out, so right after a deployment the editor's
 * URLs may sit behind a backlog until cron catches up. That is no worse than
 * waiting for cron was, and the backlog clears within a few cron runs.
 *
 * Nothing happens on the CLI: there, update hooks and drush cron are the
 * bulk operations the queue exists to protect against in the first place.
 */
class PushQueueOnTerminate implements EventSubscriberInterface {

  /**
   * How many Cludo requests we allow ourselves after a single web request.
   *
   * Deliberately lower than the cron cap. Terminate work holds on to a PHP
   * worker, and an editor's own changes fit in one or two requests - the
   * rest is backlog, which is cron's job.
   */
  public const REQUESTS_PER_TERMINATE = 5;

  public function __construct(
    protected CludoPushQueue $pushQueue,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'pushQueuedUrls'];
  }

  /**
   * Draining part of the push queue, if this request added to it.
   */
  public function pushQueuedUrls(TerminateEvent $event): void {
    if (PHP_SAPI === 'cli' || !$this->pushQueue->hasQueuedThisRequest()) {
      return;
    }

    try {
      $this->pushQueue->processQueue(self::REQUESTS_PER_TERMINATE);
    }
    catch (\Throwable $e) {
      // The queue handles Cludo's own errors itself - this is for anything
      // else, such as the database going away. The URLs are still queued, so
      // cron will get to them; the editor's response is long gone, so there
      // is no one to show the error to but the log.
      $this->logger->error('Could not push queued URLs to Cludo after the request: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
