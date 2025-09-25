```bash
# H5P Sensei Bridge

A WordPress plugin that bridges **H5P** task results with **Sensei LMS** lessons and quizzes.  
It tracks xAPI events, stores attempts, and conditionally unlocks lesson/quiz CTAs until tasks are passed.

---

## Features

- Listens to H5P xAPI events in-lesson.
- Logs attempts (latest + best score) per content & lesson.
- Calculates pass/fail based on threshold % (default 70).
- REST endpoints for frontend:
  - `POST /wp-json/fkhs/v1/h5p-xapi`
  - `GET  /wp-json/fkhs/v1/h5p-status?lesson_id=123`
- Frontend script (`assets/js/h5p-sensei-bridge.js`):
  - Adds overlay + badge on H5P containers (pass/fail state).
  - Locks quiz/lesson CTAs until passed.
  - Shows status panel (tasks remaining, clickable links to scroll).
- Admin report (`admin.php?page=fkhs-report`):
  - Sortable, filterable table of attempts.
  - Pagination, per-column filtering.
- i18n support: PHP + JS strings translatable.

---

## Requirements

- WordPress 6.0+
- Sensei LMS 4.0+
- H5P plugin (modular content).
- PHP 7.4+

---

## Installation

1. Clone or download this repo into `wp-content/plugins/h5p-sensei-bridge`.
2. Run `composer install` if dependencies are required (future).
3. Activate **H5P Sensei Bridge** in WP Admin → Plugins.
4. Ensure your H5P and Sensei plugins are active.

---

## Frontend Flow

1. Student opens lesson with H5P tasks.
2. JS listens to `H5P.externalDispatcher.on('xAPI')`.
3. Each attempt → REST `POST /h5p-xapi`.
4. Server logs attempt + evaluates pass.
5. JS fetches `/h5p-status?lesson_id=123`:
   - Adds overlay/badge on each H5P task.
   - Updates status panel under lesson buttons.
   - Enables/disables quiz/lesson CTAs accordingly.

---

## Admin Report

- JS: `assets/js/h5p-sensei-admin-report.js`
- Loaded only on `admin.php?page=fkhs-report`.
- Supports:
  - Dynamic sorting, filters (user, lesson, date, score, criterion, passed, completed).
  - Pagination (10/25/50/100/200).
  - Minimal CSS injected from JS.

---

## REST API

### `POST /wp-json/fkhs/v1/h5p-xapi`

- **Auth:** logged-in user + valid `X-WP-Nonce`.
- **Body:**
  ```json
  {
    "lesson_id": 123,
    "contentId": 456,
    "threshold": 70,
    "statement": { "result": { "score": { "raw": 7, "max": 10 }, "success": true, "completion": true }, ... }
  }
  ```
- **Server calculates** `passed`:
  - Primarily `(raw/max)*100 >= threshold`.
  - Fallback: `result.success` if score is missing.
- **Stores** attempt (including lesson title).
- **Response:** `{ "ok": true }`.

### `GET /wp-json/fkhs/v1/h5p-status?lesson_id=123`

- **Auth:** logged-in user + nonce.
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

**Server headers for `h5p-status`:**
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `Expires: 0`
- `Vary: Cookie, X-WP-Nonce`

**Client:** uses `cache: 'no-store'` + `_ts=Date.now()`.

---

## i18n

### PHP
- Strings use `__()`/`_e()` with text domain `h5p-sensei-bridge`.

### JS (frontend & admin)
- Uses **`wp_set_script_translations`** with text domain `h5p-sensei-bridge`.
- Also has **fallback via `wp_localize_script`** (`fkH5P.i18n` / `fkhsAdmin.i18n`) if `wp.i18n` is not available.

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
- Validates incoming data and checks Sensei enrollment.
- Does not mark lessons complete – Sensei remains in charge.

---

## Development

- Enable `WP_DEBUG` for more logging (`fkH5P.debug = true`).
- Frontend script is versioned with `filemtime()` for dev cache busting.
- Admin report depends on `wp-i18n`.

---

## License

MIT. See `LICENSE`.

---

## Credits

- H5P (https://h5p.org/)
- Sensei LMS (Automattic)
```