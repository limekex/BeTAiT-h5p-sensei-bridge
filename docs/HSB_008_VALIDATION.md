# HSB-008 — Full-history reporting and measured performance

Implemented 2026-11-03 after HSB-007 (`fadff83`). Status: implemented with targeted local verification; production-copy access extensions and realistic load testing remain open. No release, schema bump, historical regrading or production-attempt deletion is included.

## Report contract

The previous report selected the latest 200 rows, including the entire xAPI statement, then searched/sorted those rows in JavaScript. Searching for an older record could incorrectly appear to return no data.

`FKHS_Report_Query` now filters and sorts the full authorized history in SQL, counts it, and returns one bounded page. The default page size is 50; the maximum is 200. Rows use explicit selected columns, including stored lesson-title snapshots, and never select or return `statement`, `event_id` or `payload_hash`. H5P title/library metadata is fetched once for the page. Lesson objects/metadata are batch-primed. Missing users/activities retain ID labels; administrators can still audit orphaned attempts.

`GET /wp-json/fkhs/v1/report` is intended for the admin UI, with cookie authentication and `X-WP-Nonce`. Its JSON contains escaped row `html`, `total`, `page` and `per_page`. It is not a raw analytics/export API. Successful and callback error responses use `Cache-Control: private, no-store, max-age=0`. Permission checks also run inside the query service.

| Parameter | Values/default |
| --- | --- |
| `page` | Integer 1–1,000,000; default 1; clamped to the last available page |
| `per_page` | Integer 1–200; default 50 |
| `sort` | `date`, `user`, `lesson`, `score`, `passed`, `completed`; default `date` |
| `direction` | `asc` / `desc`; default `desc` |
| `user`, `lesson` | Literal substring, up to 200 bytes; empty means all; database collation determines case matching |
| `dateFrom`, `dateTo` | Valid `YYYY-MM-DD`, inclusive stored date/time boundaries |
| `scoreCmp`, `scoreVal` | `any`, or `ge`/`le` with a numeric 0–100 value |
| `passed`, `completed` | `any`, `yes`, `no`; stored attempt flags |
| `content_id` | Optional exact numeric H5P ID, also scoped; API-only in this iteration |

Sort expressions/directions are allowlisted, values use prepared SQL, and LIKE wildcards are escaped. Every sort has the attempt ID as a deterministic tie-breaker. Invalid parameters return 400; database/threshold-filter failures return 503 without SQL, exception details or partial HTML. Out-of-range pages clamp; empty results show `0 / 0`.

Scores are percentages only when `max_score > 0`; unscored results are not interpreted as percentages. The displayed criterion still uses `FKHS_Thresholds` for that learner/lesson/activity. Since arbitrary PHP filters can change it, the report deliberately offers no global criterion filter/sort; requesting one is rejected. Stored pass/completion flags are not recalculated against today's criterion. Dates use the stored site-local values written by the existing event flow; no historical timezone conversion is attempted.

The former `fkhs_report_limit` filter is retired for this view: it must no longer truncate searchable history. The UI page-size selector controls bounded reads. Existing student REST routes and threshold precedence remain unchanged. Refreshing the page resets report filters. JavaScript is required, with a visible noscript explanation.

## Teacher scope

Administrators with `manage_options` see the full history. Other report viewers must have both `manage_sensei_grades` and `edit_courses`, with Sensei's teacher implementation available. They see only attempts for existing lessons linked through `_lesson_course` to an existing course they currently own (`post_author`). Trashed/auto-draft courses and lessons are excluded. Draft/private courses owned by that teacher remain reportable.

One SQL `EXISTS` scope is reused by counts and page reads. No unbounded course-ID list or per-row teacher lookup is loaded into PHP. User-supplied filters cannot widen this scope. Reassigning a course revokes the former owner's next read. Deleted/orphaned relationships are administrator-only. Edit links still require the corresponding WordPress/H5P permissions.

This is intentionally narrower than custom co-teacher, group-manager, delegated grading or third-party Sensei access extensions. Such grants are not inferred from being able to edit another lesson. Validate the site's intended teacher model before enabling broader grants; there is no new bypass filter in this candidate. Multisite uses the active site's table prefix, but a real multisite access matrix has not been run.

## Browser behavior

Only the current page is inserted into the DOM. A new request aborts the previous one and uses a request sequence guard; stale successes/failures cannot replace newer results. Requests time out after 20 seconds. Old rows are cleared while loading or on error so a changed filter cannot leave stale results presented as current. Errors expose retry and explain that an expired session may require signing in again.

The existing labelled keyboard dialogs are retained. Paging moves focus to the live status while a focused paging/retry control is disabled or hidden; it returns to an enabled paging control after success, or retry after failure. On the last page it stays on the status instead of falling onto `body`. Sort/filter controls remain usable during requests. The current criterion has no nonfunctional controls.

Actual Chrome/WordPress admin checks on `http://localhost:8887/` used WordPress 7.1.4, PHP 8.5.7, Sensei 4.26.4, H5P 1.17.10 and SQLite Integration 3.1.2:

- A dedicated teacher and learner, draft course 118/lesson 119, and 251 synthetic attempts were created. Existing course histories were not rewritten. Fixture identity is stored in `fkhs_hsb008_fixture`; the test teacher is `fkhs-report-teacher-20261103`.
- Norwegian report showed `1–50 / 251`. Keyboard lesson search found the 2020 attempt that was outside the former 200-row window, showed `1–1 / 1`, and returned focus to the active filter button.
- Page size 200 followed by keyboard Next showed `201–251 / 251`, including the oldest attempt. The initially observed focus loss was fixed and retested: final focus is `#fkhs-page-info`.
- At 320×740, document client/scroll widths were both 320; the wide table scrolls inside its own region. Dialogs, wrapped controls and explanatory text remain within the viewport. The temporary viewport override was reset.
- Switching only the test teacher's locale to en_US produced English controls. The stored Passed=No server filter returned the single older failed attempt.
- Expiring only that test teacher's sessions caused the real REST request to fail visibly, removed stale rows, disabled paging and exposed Retry. Retrying did not fabricate an empty successful report.

The synthetic local fixture remains available for follow-up, with its browser sessions expired. No theme source files or site-wide language settings were changed. VoiceOver/NVDA speech, confirmed 200% zoom, Safari/Firefox and physical touch devices remain outside this check.

## Automated verification

- `npm test`: **25 JavaScript tests**, including server pagination, nonce/no-store requests, stale-response rejection, HTTP failure/retry, keyboard focus and the existing gate/transport/accessibility cases.
- `bash tests/php-checks.sh`: **477 assertions** (59 progress, 373 access/payload, 45 durability) plus PHP syntax checks. These use test doubles, not a real database.
- `bash tests/integration/run.sh wp71-php82`: **694 assertions** (existing 377 plus 317 report assertions), actual concurrent delivery, CLI/HTTP version checks and the nine-case performance matrix. The report tests include 251 explicit fixture insert checks. They cover older search hits, deterministic paging, literal wildcard escaping, malformed parameters, teacher separation/reassignment/trashed courses, administrator access, cookie/nonces, escaped markup, omitted private payloads, failed reads/throwing filters, closed output buffers, metadata batches and deleted H5P fallback.
- Warm report reads used **3 queries for both 10 and 200 rows**. The separate benchmark clears the WordPress object cache and measured **6 queries per report read**.
- JS/PHP/shell syntax, gettext catalog compilation and whitespace checks passed. English and Bokmål report strings are included in the shipped catalogs.

The integration runner executes the new report assertions in all three existing CI profiles. Only `wp71-php82` runs the larger synthetic benchmark and retains `report-benchmark.log` with the integration artifacts. Timing values are evidence, not brittle CI pass/fail thresholds. GitHub results must be tied to the pushed candidate revision; prior HSB-007 CI results do not cover these changes.

## Measurements and index decision

The benchmark uses 1,000/10,000/100,000 synthetic attempts for one learner/lesson distributed across 1/10/50 activities, in addition to the small existing integration fixtures. This intentionally puts a heavy history under one progress scope. Each case takes 15 sequential in-process samples with cold WordPress object/threshold caches and a warm database. p50 is the median and p95 the nearest-rank maximum of those 15 samples. These are PHP query/render timings, not HTTP latency, a concurrent load test or production capacity guarantees.

MariaDB 10.11.18, PHP 8.2.32, WordPress 7.1.4, Sensei 4.26.4 and H5P 1.17.10. Report reads request 50 rows. Full data and EXPLAIN evidence: [before progress metadata batching](benchmarks/hsb008-before-metadata-batch.json) and [after batching](benchmarks/hsb008-metadata-batched.json).

| Attempts | Activities | Admin p50/p95 ms | Teacher p50/p95 ms | Progress p50/p95 ms | Progress queries |
| --- | --- | --- | --- | --- | --- |
| 1k | 1 | 3.61 / 4.36 | 6.15 / 6.44 | 1.95 / 2.30 | 6 |
| 1k | 10 | 3.58 / 3.74 | 6.23 / 6.52 | 7.10 / 9.18 | 33 |
| 1k | 50 | 3.74 / 3.93 | 6.67 / 6.95 | 22.39 / 38.51 | 153 |
| 10k | 1 | 6.49 / 6.92 | 21.17 / 196.57 | 11.30 / 13.06 | 6 |
| 10k | 10 | 7.03 / 7.20 | 22.13 / 32.64 | 15.93 / 45.72 | 33 |
| 10k | 50 | 6.99 / 7.08 | 21.66 / 26.57 | 43.41 / 63.00 | 153 |
| 100k | 1 | 33.18 / 40.10 | 169.90 / 206.43 | 107.92 / 113.75 | 6 |
| 100k | 10 | 14.68 / 15.60 | 137.76 / 152.94 | 120.59 / 125.47 | 33 |
| 100k | 50 | 15.62 / 27.59 | 138.43 / 148.81 | 136.31 / 145.76 | 153 |

Progress metadata batching reduces the 50-activity read from 202 to 153 queries (10 activities: 42→33). The remaining latest/best/ever-completed reads still run per activity; combining them is a measured follow-up, requiring preservation of historical pass/sticky-completion/tie-breaking semantics. The timing variance above is why query-count reduction, rather than a universal speedup claim, is the firm result.

Candidate indexes were added only in disposable benchmark databases after the nine baseline cases. In the first run, `lesson_created (lesson_id, created_at, id)` changed the report plan from the existing date index to a lesson-driven temporary/filesort plan and worsened teacher p50 from about 140 ms to 256 ms. `progress_latest (user_id, lesson_id, content_id, created_at, id)` improved that sample's p50 slightly (145→133 ms) but not p95 (171→189 ms). The candidates were added cumulatively, so the progress comparison is not an isolated measurement of that index alone. The follow-up evidence is retained too. **No new index is shipped on this evidence**; existing date, user/lesson and event-identity indexes remain, and schema stays 0.3.0.

Remaining performance work is to test realistic distributions across many teachers/courses/learners, selective filters, deep offsets, persistent caches and concurrent writes. Exact counts and arbitrary text/score sorts can still scan substantial history. Stable ID ties prevent ambiguity on unchanged data; offset pages are not a frozen snapshot, so new attempts between page requests can move rows. Consider cursor/snapshot semantics for a future export workflow rather than treating this UI as an auditable bulk export.


Follow-up: all seven [GitHub jobs for HSB-008](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41670061205) passed on `b1cb4d2`, including the report integration checks and the benchmark profile.
