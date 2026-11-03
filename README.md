# H5P Sensei Bridge

> **Development status (2026-11-03):** Reconciliation is merged. Follow-up access validation and durable event handling are implemented locally with regression, REST/database and targeted FagKlar browser tests. Read the [engineering plan](docs/ENGINEERING_PLAN.md) and [review/staging gate](docs/DEVELOP_REVIEW.md) before deployment; broader verification remains open.

A WordPress plugin that bridges **H5P** task results with **Sensei LMS** lessons and quizzes.  
It tracks xAPI events, stores attempts, and conditionally unlocks lesson/quiz CTAs until tasks are passed.

---

## Features

- Place the **H5P progress** block or `[fkhs_progress]` in a lesson to show the current learner’s requirements and results; see the [progress UI/API contract](docs/PROGRESS_API.md).
- Search and insert existing H5P activities with the **H5P activity** Gutenberg block in Sensei lessons.
- Set thresholds during placement; the block and lesson metabox share unsaved values.
- Listens to H5P xAPI events in-lesson.
- Logs attempts (latest + best score) per content & lesson.
- Resolves thresholds per H5P activity, falling back to legacy activity/lesson settings and then 70%.
- A threshold of 0 tracks completion; before a stored attempt it is optional under the develop behavior.
- Shared progress reads supply REST status and server-side Sensei guards; see the compatibility contract in the engineering plan.
- REST endpoints for frontend:
  - `POST /wp-json/fkhs/v1/h5p-xapi`
  - `GET  /wp-json/fkhs/v1/h5p-status?lesson_id=123`
- Frontend script (`assets/js/h5p-sensei-bridge.js`):
  - Adds status badges and inline result feedback below H5P activities.
  - Locks quiz/lesson CTAs until passed.
  - Shows status panel (tasks remaining, clickable links to scroll).
- Admin report (`admin.php?page=fkhs-report`):
  - Server-side search, sorting and pagination across the full attempt history.
  - Administrators see all attempts; teachers see attempts in their own courses.
- i18n support: PHP + JS strings translatable.

---

## Requirements

- The integration matrix targets WordPress 6.9/PHP 8.2 and WordPress 7.1.4/PHP 8.2 or 8.4, with Sensei LMS 4.26.4 and H5P 1.17.10.
- Dependency-free PHP checks cover 8.0, 8.2, 8.4 and 8.5.
- The candidate header requires WordPress 6.9/PHP 8.2, matching the lowest full integration profile, and declares `sensei-lms` and `h5p` dependencies. PHP 8.0 regression coverage is retained but is not an installation support claim. See [HSB-006 results](docs/HSB_006_VALIDATION.md) and [HSB-010 package evidence](docs/HSB_010_VALIDATION.md); versions between sampled combinations are not automatically certified.

---

## Installation

1. Install and activate the supported Sensei LMS and H5P versions.
2. Build the candidate with `python3 scripts/build-plugin.py`. The output is `dist/h5p-sensei-bridge-1.2.0-dev.zip`, with a SHA-256 sidecar and file manifest.
3. On an isolated test site, upload the ZIP through Plugins → Add New → Upload Plugin and activate **BeTAiT H5P → Sensei Bridge**. The archive contains one `h5p-sensei-bridge/` root.
4. Follow the [upgrade, backup/restore and rollback procedure](docs/HSB_010_VALIDATION.md) before any production installation. `1.2.0-dev` is an unreleased candidate, not a deployment approval.

For source development, copy or symlink the nested `h5p-sensei-bridge/` directory into `wp-content/plugins/`. No Composer or Node build is needed at runtime. The integration suite installs the real ZIP rather than mounting source code as the installed plugin.

---

For development checks, run `npm ci --ignore-scripts`, `npm test` and `bash tests/php-checks.sh`. jsdom is used only in tests; the plugin needs no Node runtime or build. Bokmål translations and keyboard/mobile coverage are documented in [HSB-007](docs/HSB_007_VALIDATION.md).

## Lesson authoring

Insert **H5P activity** in a Sensei lesson, search by title or ID, and select an existing activity. The block saves `[h5p id="ID"]` at that position. Choose **Inherit threshold** or enter an explicit value from 0 to 100. The lesson metabox shows all activities, including unsaved blocks and manually entered shortcodes, and updates immediately with the block controls.

Repeated uses of the same activity share one lesson threshold. Replacing an activity uses the replacement's existing override or inherited value. Copying a block to another lesson carries the activity ID only; the destination lesson controls its threshold. Removing a block preserves historical attempts and any unused override. Inherit removes the current override and uses the legacy activity setting, then the lesson setting, then 70; runtime filters can change the learner's effective requirement.

WordPress owns normal saves, undo/redo, autosaves and revisions. Activity search follows H5P visibility permissions and requires edit access to the specific lesson. Detach synced patterns containing these blocks before using them in a lesson; references hide activity IDs from lesson discovery. Other post types are outside the feature's scope. See [HSB-013 behavior, tests and limits](docs/HSB_013_VALIDATION.md).

## Frontend Flow

1. Student opens lesson with H5P tasks.
2. JS listens to `H5P.externalDispatcher.on('xAPI')`.
3. Root activity events with an unambiguous content ID enter a bounded queue. Each event gets a stable UUID for manual retries; nested subactivity events are excluded.
4. Server evaluates pass and confirms the saved row. Zero-threshold incomplete events are skipped explicitly. Opening an activity or waiting does not manufacture completion.
5. After the queue drains, JS fetches `/h5p-status?lesson_id=123`:
   - Adds overlay/badge on each H5P task.
   - Updates status panel under lesson buttons.
   - Enables/disables quiz/lesson CTAs accordingly.

Saving, failures, retry and sign-in recovery are visible. Pending events stay in memory only while the page is open; refresh is not offline recovery. See [HSB-004 validation and limits](docs/HSB_004_VALIDATION.md).

Learning Mode's visible and screen-reader actions share the same H5P gate. Sensei retains ownership of native disabled/completion state; bridge feedback sits outside its action layout. See [HSB-002 browser validation](docs/HSB_002_VALIDATION.md).

H5P tracking waits up to 15 seconds for its event dispatcher, then shows an explicit retry action and retains the student gate. Lessons without H5P skip this wait. Binding after a delay refreshes progress; it cannot recover events emitted before the listener existed. See [Sensei integration validation](docs/HSB_005_VALIDATION.md) for the tested Sensei flow and remaining backend paths.

---

Native lesson completion requests are checked before Sensei writes progress. The quiz gate also rejects direct submit, save, page-change and reset requests before its form handlers run. Tested REST editing/reset routes remain restricted by Sensei permissions. Programmatic completion emitting Sensei’s completion action is corrected by reopening status while preserving answers, grades and feedback. It returns without redirecting or exiting. Missing APIs or failed correction raise an exception. Earlier/third-party hook effects and direct repository writes remain outside that guarantee; see [HSB-005 scope](docs/HSB_005_VALIDATION.md).

## Admin Report

- JS: `assets/js/h5p-sensei-admin-report.js`
- Loaded only on `admin.php?page=fkhs-report`.
- Supports:
  - Server-side sorting and filters (user, lesson, stored date, score percentage, stored passed/completed flags).
  - Pagination (10/25/50/100/200), including records older than the former 200-row cap.
  - Current effective criterion displayed per learner/activity; global criterion sorting/filtering is unavailable because runtime filters may change it.
  - Visible load failures and retry, bounded requests, stale-response rejection and keyboard focus preservation.
  - Minimal CSS injected from JS.

The report uses `GET /wp-json/fkhs/v1/report` with cookie authentication and a REST nonce. Teacher access requires Sensei grading/edit-course capabilities and current ownership of the lesson’s course; counts and rows share the same scope. No raw xAPI statements are selected or returned. The old `fkhs_report_limit` filter no longer caps history. See [HSB-008 validation, API parameters and measurements](docs/HSB_008_VALIDATION.md).

---

## Data retention and personal-data tools

Attempts and raw xAPI are retained by default, including after uninstall. WordPress's personal-data tools now export bridge attempts in bounded pages; full attempt erasure remains disabled until an explicit site policy opts in. `wp fkhs cleanup-statements` previews a bounded batch by default and can explicitly clear older raw statements while preserving scores, completion and duplicate-delivery receipts. Future raw storage can also be disabled without losing progress.

See [HSB-009's retention contract, operator commands and rollback limits](docs/HSB_009_VALIDATION.md) before enabling cleanup or erasure. No automatic expiry, account-deletion purge or table drop is enabled.

## REST API

### `POST /wp-json/fkhs/v1/h5p-xapi`

- **Auth:** logged-in user + valid `X-WP-Nonce` for browser cookie authentication. Students must be enrolled in the owning course and allowed to view the published lesson. Administrators and users who can edit this specific lesson may preview without enrollment.
- **Membership:** a positive `contentId` is required, must occur in an actual H5P shortcode in the lesson, and must exist in the H5P registry.
- **Body:**
  ```json
  {
    "lesson_id": 123,
    "contentId": 456,
    "event_id": "97bc5552-4e30-4569-b497-a509af8e3be9",
    "threshold": 70,
    "statement": {
      "verb": { "id": "http://adlnet.gov/expapi/verbs/completed" },
      "result": { "score": { "raw": 7, "max": 10 }, "success": true, "completion": true }
    }
  }
  ```
- **Server calculates** `passed` using the effective per-activity threshold.
  - Positive threshold: primarily `(raw/max)*100 >= threshold`, with success fallback.
  - Zero threshold: actual root completion signals only. Explicit false overrides a completed verb. Answering, opening and unknown-scale progress do not imply completion. Historical completed rows remain valid.
  - The client threshold field is a hint, not the policy source.
- **Stores** attempt (including lesson title).
- **Stored response:** `{ "ok": true, "stored": true, "duplicate": false, "attempt_id": 123 }`. Repeating the same UUID and statement within the authenticated user/lesson/activity scope returns the original receipt with `duplicate: true`. A changed statement under the same UUID returns 409. Legacy clients may omit the UUID, but cannot safely deduplicate retries.
- **Skipped response:** `{ "ok": true, "stored": false, "skipped": true, "reason": "completion_pending" }`; other skip reasons are `filtered` and `nested_activity`. A skip is not a saved attempt.
- **Validation:** `verb.id` must be a nonempty string. Consumed result/context/object containers must be objects, boolean flags must be JSON booleans, and supplied scores/progress must be finite JSON numbers within valid bounds. Strings such as `"false"` or `"8"` are rejected rather than coerced. Full LRS statement validation is outside this endpoint's scope.
- **Failures:** invalid IDs/payload/activity membership return 400, denied lesson access returns 403, and missing required Sensei/H5P runtime returns 503. WordPress handles authentication/nonce errors. Handler errors include `ok: false` and a stable `reason` code. Failed inserts return 500; unavailable receipt/status reads return 503, without a false saved confirmation.

### `GET /wp-json/fkhs/v1/h5p-status?lesson_id=123`

- **Contract:** [Lesson progress UI and external API](docs/PROGRESS_API.md), including response schema, native Application Password authentication and failure handling.
- **Auth:** same lesson access policy as POST; results always belong to the authenticated user. Unenrolled preview/open-course viewers cannot use the bridge API solely because Sensei allows viewing the lesson.
- **Response:**
  ```json
  {
    "ok": true,
    "items": [
      {
        "content_id": 456,
        "title": "H5P Title",
        "type": "Library 1.18",
        "threshold": 70,
        "latest": { "raw": 7, "max": 10, "pct": 70, "passed": true },
        "best":   { "raw": 8, "max": 10, "pct": 80 }
      }
    ]
  }
  ```

The status response also supplies canonical `required` and `passed` values. Each item includes `counts_for_gate` and `requirement_met`, alongside the existing latest/best fields.

**Server headers explicitly set for `h5p-status`:**
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `Expires: 0`
- `X-LiteSpeed-Cache-Control: no-cache`

Verify actual proxy/cache behavior on staging; no explicit `Vary` header is set by this controller.

**Client:** uses `cache: 'no-store'` + `_ts=Date.now()`.

---

## i18n

### PHP
- Strings use `__()`/`_e()` with text domain `h5p-sensei-bridge`.

### JS (frontend & admin)
- Uses **`wp_set_script_translations`** with text domain `h5p-sensei-bridge`.
- Also has **fallback via `wp_localize_script`** (`fkH5P.i18n` / `fkhsAdmin.i18n` / `fkhsEditor.labels`) if `wp.i18n` is not available.

### WP-CLI (translation workflow)
```bash
# 1) Generate POT
wp i18n make-pot . languages/h5p-sensei-bridge.pot --domain=h5p-sensei-bridge

# 2) Create/Update PO (e.g. for nb_NO)
# Edit PO in POEdit or another tool
# 3) Compile MO
msgfmt languages/h5p-sensei-bridge-nb_NO.po -o languages/h5p-sensei-bridge-nb_NO.mo

# 4) (Optional) Generate JSON files for JS translations
wp i18n make-json languages --no-purge
```

---

## Hooks

### Filters

- `fkhs_should_enqueue_bridge` (bool): Whether to enqueue the frontend script.
- `fkhs_threshold_for_lesson` (float, lesson_id, user_id): Override threshold before calculation.
- `fkhs_calculated_passed` (bool, statement, lesson_id, user_id): Override pass policy.
- `fkhs_should_log_attempt` (bool, statement): Decide whether to log attempt.
- `fkhs_completion_progress_scale` (null|string, key, statement, lesson_id, content_id): Default `null` ignores numeric progress for completion. Return `percent` or `ratio` only for a tested library/key; completion requires exactly 100 or 1 respectively.

### Example
```php
add_filter('fkhs_threshold_for_lesson', function($thr, $lesson_id, $user_id){
  if ($lesson_id === 42) return 80.0; // Special case
  return $thr;
}, 10, 3);
```

---

## Troubleshooting

1. **Quiz button stays locked even when tasks are passed**
   - Enable debug: `window.fkH5P.debug = true;`
   - Check `/fkhs/v1/h5p-status` response for `latest.passed` or `best.pct >= threshold`.
   - Verify `window.fkhsGatePassed` is `true` and `setQuizButtonVisibility(true)` runs.
   - If using Litespeed/edge cache, exclude **only** these REST endpoints. The server already sends no-store headers.

2. **JS translations not applied**
   - Ensure `wp_set_script_translations( 'handle', 'h5p-sensei-bridge', FKHS_DIR . 'languages' );`.
   - Fallback: `wp_localize_script` is attached to the same handle.
   - Confirm WordPress language settings match your `.mo`/`.json`.

3. **xAPI not firing**
   - Check that H5P is loaded and `H5P.externalDispatcher` exists.
   - Some H5P types do not set `score.max`; plugin falls back to `result.success`.
   - Look for `[FKHS] Bound H5P xAPI listener` in console.

---

## Security

- Requires logged-in user + WordPress REST nonce.
- GET/POST share lesson authorization using Sensei enrollment and lesson access, with scoped editor preview. POST validates H5P membership and the payload fields used by the bridge. See [HSB-001 implementation and validation](docs/HSB_001_VALIDATION.md) for the compatibility changes and test boundaries.
- Client-generated xAPI scores are not independent proof of assessment integrity.
- Does not mark lessons complete – Sensei remains in charge.

---

## Development

- Enable `WP_DEBUG` for more logging (`fkH5P.debug = true`).
- Frontend script is versioned with `filemtime()` for dev cache busting.
- Admin report depends on `wp-i18n`.
- Run `php tests/progress.php`, `php tests/rest.php`, `php tests/durability.php` and `node --test tests/*.test.cjs` for regressions with test doubles.
- Run `bash tests/integration/run.sh` with Docker Compose for disposable WordPress/Sensei/H5P/MariaDB REST tests. It downloads pinned versions, exposes no host ports and removes its own containers/volume on exit. See the [validation notes](docs/HSB_001_VALIDATION.md) for coverage and remaining staging work.
- Schema version 0.3.0 adds nullable event identity columns and a unique index without rewriting old attempts. Complete and verify the activation/admin upgrade before student use; see [upgrade, rollback and durability evidence](docs/HSB_004_VALIDATION.md).

---

## License

GPLv2 or later (`GPL-2.0-or-later`), confirmed by the maintainer on 2026-11-03. See the included [license text](h5p-sensei-bridge/LICENSE). This resolves the former README/header mismatch. No payment or premium functionality is introduced by this choice.

---

## Credits

- H5P (https://h5p.org/)
- Sensei LMS (Automattic)
