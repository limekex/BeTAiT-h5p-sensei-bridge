# HSB-004 — durable events and explicit completion

Implemented locally on 2026-11-02 after HSB-001, on `fix/hsb-001-access-validation`. No release or production deployment is included. The owner explicitly selected actual completion signals as the default; content-type exceptions require tests first.

## Completion contract

- Explicit `result.completion` takes precedence, including false. Otherwise a supported completed extension or root `/completed` verb can establish completion.
- Opening, interacting, `/answered`, inactivity and an unqualified progress number do not establish completion. The 900 ms synthetic completion timer is removed.
- Nested subactivity statements identified by H5P subContentId are excluded from both score and completion processing. A child result must not pass the containing activity.
- Numeric progress is ignored by default. The `fkhs_completion_progress_scale` filter receives `(null, key, statement, lesson_id, content_id)`; a tested library adapter may select `percent` or `ratio`, requiring exactly 100 or 1. No library-specific adapter is enabled in this change.
- Existing completion/pass rows are not rewritten. Zero-threshold activities remain optional before a stored attempt; incomplete events are skipped, not inserted as new incomplete attempts. This does not make every zero-threshold activity mandatory.

H5P types do not universally emit completion on opening or reaching the last page. Real deployed library payloads still need verification. See the upstream [H5P xAPI documentation](https://h5p.org/documentation/x-api) and [event implementation](https://github.com/h5p/h5p-php-library/blob/master/js/h5p-x-api-event.js). Client statements remain formative evidence, not independent proof of assessment integrity.

## Persistence and recovery contract

- The frontend assigns each queued event a UUID and freezes the request body. Manual retries reuse both. It accepts explicit stored receipts containing an attempt ID, or explicit skip responses; a bare `ok: true` is unconfirmed.
- Stored identity is scoped to authenticated user, lesson, content and UUID. A canonical statement hash detects UUID reuse with a changed payload (409). Repeated or concurrent deliveries return the same attempt ID without changing the stored timestamp or policy snapshot. New events use new UUIDs.
- The database unique index resolves concurrent insert races. Failed inserts return 500; failed receipt/progress reads return 503. SQL errors are not rendered into REST responses. Sensei's shared guard fails closed on progress read failures.
- Legacy callers may omit the UUID and still receive a stored receipt, but such requests cannot be safely deduplicated. Skips are not persisted receipts and are not retried by the frontend.
- Requests are serialized, with a 15-second timeout and at most 100 pending events. There is no automatic retry. Network/server errors offer manual retry; authentication failures offer sign-in recovery. Pending writes/read failures hold the student gate until fresh status is available; existing teacher/admin exemptions remain.
- Pending events exist only in the current page's memory. Leaving warns while events remain queued. Reloading or closing loses unsaved events; signing in and reloading requires repeating unsaved work. This is not offline storage or cross-session replay. Queue overflow reports unrecorded input instead of silently evicting older events.
- Status refresh occurs after the write queue drains. Generation checks discard stale GET results and errors, including reads invalidated by a newer write.
- Content mapping accepts explicit event/instance IDs, H5P's local-content-id extension, or an exact registered H5PIntegration URL match. Conflicting/missing identity is visible; arbitrary URL numbers and the first DOM activity are no longer fallback identities.

## Additive schema upgrade and rollback

Schema version **0.3.0** adds nullable `event_id CHAR(36)` and `payload_hash CHAR(64)` columns and unique `event_identity (user_id, lesson_id, content_id, event_id)`. Old rows retain their values and NULL identity; no backfill, deletion or regrading runs. The plugin release version is unchanged.

Activation or the existing admin upgrade hook runs dbDelta. The schema option advances only after verifying the columns and unique index. Before student use, back up the staging database, activate/load an admin page and verify `fkhs_db_ver = 0.3.0` and the index. Failed upgrades must be resolved before rollout; an old schema must not be treated as ready merely because the PHP files loaded.

File rollback can retain the additive nullable columns/index: legacy inserts omitting them remain valid. Do not drop them or restore an older database over newer learner activity. Old code loses the new acknowledgement/deduplication guarantees. Clear PHP/asset caches and verify the restored package on staging; Sensei state changes during testing require separate fixture restoration. Automated coverage verifies legacy inserts after upgrade, not a full browser/package rollback.

## Reproducible evidence — 2026-11-02

```sh
php tests/progress.php
php tests/rest.php
php tests/durability.php
node --test tests/*.test.cjs
bash tests/integration/run.sh
git diff --check
```

| Check | Environment | Result |
| --- | --- | --- |
| Shared policy / access / durability regressions | Host PHP 8.5.7, WordPress/SQL doubles | 52 + 373 + 45 = 470 assertions passed |
| Gate and actual frontend closure with DOM/fetch doubles | Node 22.22.2 | 11 tests passed, including timeout, queue bounds, stale responses, retry identity, authentication and no synthetic completion |
| REST access, nonce, enrollment and SQL | WordPress 7.1.4 / PHP 8.2.32 / Sensei 4.26.4 / H5P 1.17.10 / MariaDB 10.11.18 | 71 assertions passed |
| Populated 0.2.0 upgrade, failed DDL, retained history, receipts, injected SQL failures, legacy insert | Same disposable stack | 23 assertions passed |
| Concurrent same-event delivery | Two WP-CLI processes synchronized after initial dedup lookup | Same attempt ID; exactly one row; one original and one duplicate acknowledgement |
| PHP/JS/shell syntax and whitespace | Local tools | Passed |

The disposable fixture exposes no host ports and removes its own containers, network and volume. CI configuration includes these tests; GitHub Actions has not been run for this local work.

## FagKlar local browser smoke — 2026-11-02

The owner restored `http://localhost:8887/`, now served from `FagKlarTheme/.local/wordpress`. Observed WordPress 7.1.4, PHP 8.5.7, Sensei 4.26.4, WooCommerce 11.2.1 and SQLite Database Integration 3.1.2. Installed H5P 1.17.10 and symlinked this bridge working copy; activation verified schema option 0.3.0. No theme source was changed.

A consistent SQLite snapshot is at `/private/tmp/fkhs-staging-before-bridge-20261102.sqlite` (taken after H5P installation began, before bridge activation/test content). An earlier sandboxed backup attempt failed; it is not a usable backup. Existing lessons/settings were retained. Added only the dedicated HSB test course 105, lessons 106 (70%) and 107 (0), subscriber 14, H5P activity 1 and fixture option `fkhs_browser_fixture_20261102`. The local mail guard was already present.

Imported the official [H5P True/False example](https://h5p.org/true-false), H5P.TrueFalse 1.8, with its package attribution. Logged into Chrome through the ordinary WordPress login as the new subscriber, enrolled through Sensei's manual provider. Observed in FagKlar Learning Mode:

- Correct answer at 70% produced a real `/answered` event with `result.completion: true`, score 1/1 and a UUID. The row persisted, the UI confirmed saving and Sensei's Complete Lesson changed course progress to 1/2.
- Opening the zero-threshold lesson and leaving it idle created no attempt/completion row. The UI said not completed and optional, preserving the existing zero-threshold contract.
- An incorrect submitted answer at zero produced explicit completion, score 0/1 and success false. The bridge correctly stored `completed=1, passed=1`; the requirement is completion, not a score threshold.
- A temporary MU filter injected a failed INSERT only for test user 14's bridge REST writes. The new attempt was absent from SQL, a visible error and Retry appeared, and the visible continuation button was disabled. Removing the filter and clicking Retry saved the pending event, refreshed status and restored continuation. No automatic retry or reload was needed.
- The error test revealed stale "You can continue" copy beside the failure notice. The progress panel is now hidden while transport is unresolved, with a regression assertion added to the frontend suite. A second real failed-write run verified that the stale message was absent.

These are targeted browser observations for one library. The SQLite smoke supplements, rather than replaces, the MariaDB migration/concurrency tests. The fixture content and plugin symlink remain for follow-up; the temporary fault-injection filter is removed after testing. PHP 8.5 emits WP-CLI dependency deprecations during CLI operations; no claim of a warning-free full compatibility matrix is made.

## Remaining verification

True/False 1.8 has targeted real-browser coverage above; other deployed libraries, nested/multiple embeds, lost responses, expired browser sessions, direct quiz/REST completion paths and accessibility/language remain unverified. The subsequent [HSB-002 follow-up](HSB_002_VALIDATION.md) identified the second control as Sensei's intentional screen-reader navigation, aligned both gates and corrected Completed-state handling. Broader HSB-002 lifecycle work, HSB-005 full Sensei integration and HSB-007 UI/language remain open. The Gutenberg picker remains planned under HSB-013.
