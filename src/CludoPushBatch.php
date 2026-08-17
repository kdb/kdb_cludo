<?php

namespace Drupal\kdb_cludo;

/**
 * A set of URLs that goes to Cludo in one single request.
 *
 * @see \Drupal\kdb_cludo\Services\CludoPushQueue
 */
class CludoPushBatch {

  /**
   * The URLs to push. Kept unique - Cludo only needs to hear each one once.
   *
   * @var string[]
   */
  public array $urls = [];

  /**
   * The queue items the URLs came from - duplicates included.
   *
   * These are what we delete from (or put back into) the queue, once we know
   * how Cludo responded.
   *
   * @var \stdClass[]
   */
  public array $items = [];

  public function __construct(
    public string $crawlerId,
    public bool $delete,
  ) {}

  /**
   * Adding a URL, and the queue item it came from, to the batch.
   */
  public function add(string $url, \stdClass $item): void {
    $this->urls[] = $url;
    $this->items[] = $item;
  }

  /**
   * Attaching a queue item whose URL is already in the batch.
   */
  public function addDuplicate(\stdClass $item): void {
    $this->items[] = $item;
  }

  /**
   * Tells if the batch has room for more URLs.
   */
  public function hasRoomFor(int $urlsPerRequest): bool {
    return (count($this->urls) < $urlsPerRequest);
  }

}
