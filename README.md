# KDB Cludo integration

Add-on module for integrating [Cludo Search](https://www.cludo.com/) on bibliotek.kk.dk

## URL pushing

When *URL pushing* is enabled in the module settings, saving or deleting a
node, event series or event instance tells Cludo to (re)index the URL.

How that happens depends on where the save came from:

- **In a web request** - an editor pressing save - the URL is pushed to Cludo
  right away, so the change shows up in search without waiting for cron.
- **On the CLI** - update hooks, migrations, `drush cron` - the URL is written
  to the `kdb_cludo_url_push` queue instead, and cron pushes the queued URLs
  to Cludo in batches. Bulk operations would otherwise fire thousands of
  requests, which Cludo answers with `429 Too Many Requests`, while holding up
  the deployment that triggered them.

A direct push that Cludo doesn't accept - rate limiting, an outage - falls
back to the queue as well, so the URL is retried on cron rather than lost, and
the editor's save goes through regardless.

### The queue

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
without them, nothing is pushed or queued (the queue would otherwise grow
forever), a warning is logged, and the settings page says so. URLs already in
the queue stay there until the credentials are back.
