<?php

namespace Drupal\kdb_cludo;

/**
 * The tally of a single queue-processing run.
 *
 * A run is allowed a fixed number of Cludo requests, and every request - a
 * whole batch, or a single URL retried on its own - counts against the same
 * budget. That is what keeps a run bounded no matter which path it takes.
 *
 * @see \Drupal\kdb_cludo\Services\CludoPushQueue::processQueue()
 */
class CludoPushRun {

  /**
   * How many URLs the run has pushed to Cludo so far.
   */
  public int $pushed = 0;

  /**
   * How many requests the run has made to Cludo so far.
   */
  public int $requests = 0;

  public function __construct(
    public readonly int $budget,
  ) {}

  /**
   * Tells if the run may make another request.
   */
  public function hasBudget(): bool {
    return ($this->requests < $this->budget);
  }

}
