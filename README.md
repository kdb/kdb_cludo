# KDB Cludo integration

Add-on module for integrating [Cludo Search](https://www.cludo.com/) on bibliotek.kk.dk

## URL pushing

When *URL pushing* is enabled in the module settings, saving or deleting a
node, event series or event instance tells Cludo to (re)index the URL.

The push does **not** happen on save. Instead the URL is written to the
`kdb_cludo_url_push` queue, and cron pushes the queued URLs to Cludo in
batches. This matters because:

- Cludo answers `429 Too Many Requests` if we push URLs one at a time.
- Mass updates - update hooks, bulk operations - would otherwise block on
  thousands of API calls, and hold up a deployment while doing so.

Duplicate URLs are collapsed per batch, so saving all instances of an event
series only pushes the series once. If a URL is queued with conflicting
operations - unpublished, then republished, say - only the newest operation
is pushed.

Two settings control the pace, both under *URL pushing* on
`/admin/config/kdb-cludo/settings`:

- **URLs per request** - how many URLs go into a single Cludo request.
- **Requests per cron run** - how many requests we allow per cron run. The
  rest of the queue waits for the next run.

The *requests per run* figure is a hard bound: every request counts against
it, including the retries described below, so a run never bursts past it.

If Cludo rate limits us mid-run, the unpushed URLs are put back in the queue
and retried on the next cron run. If Cludo rejects a whole batch (a 4xx other
than `401`, `403` or `429`), its response doesn't say which URL it objects to,
so the batch is retried one URL at a time: the URLs Cludo rejects on their own
are dropped and logged, the rest go through, and a single bad URL can't block
the queue. If the first few retries are *all* rejected before anything gets
through, the problem is taken to be the request itself - a wrong crawler or
customer ID - and the URLs are kept in the queue for when the configuration
has been fixed.

The settings page shows how many URLs are pending, and can push a cron run's
worth of them right away.

URL pushing needs the Cludo customer ID and API key. If pushing is enabled
without them, nothing is queued (the queue would otherwise grow forever), a
warning is logged, and the settings page says so. URLs already in the queue
stay there until the credentials are back.

### Editors don't wait for cron

An editor saving a single page shouldn't have to wait for cron before it
shows up in search. So when a *web request* queues URLs, the module drains a
small slice of the queue (5 requests, see `PushQueueOnTerminate`) after the
response has been sent to the browser. The editor never waits, and the queue
still handles batching and deduplication - saving an event series that
regenerates 200 instances costs a handful of requests, not hundreds.

The queue is first-in-first-out, so right after a deployment an editor's URLs
may sit behind the backlog until cron catches up. Nothing runs on the CLI:
update hooks and `drush cron` are exactly the bulk operations the queue
exists to protect against.
