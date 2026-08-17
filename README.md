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

If Cludo rate limits us mid-run, the unpushed URLs are put back in the queue
and retried on the next cron run. URLs that Cludo rejects outright (a 4xx
that isn't `429`) are dropped and logged, so a single bad URL can't block the
queue.

The settings page shows how many URLs are pending, and can push a cron run's
worth of them right away.
