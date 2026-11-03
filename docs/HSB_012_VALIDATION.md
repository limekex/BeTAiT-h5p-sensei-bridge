# HSB-012 — Learner course progress validation

Implemented on `feature/hsb-012-course-progress`, based on `813c7e3`, 2026-11-03. The owner selected the learner's own progress through a course. Scope, placement, access, limits and rollback are defined in [the course progress contract](COURSE_PROGRESS.md). Version remains `1.2.0-dev`, schema remains `0.3.0`.

## Automated coverage

- PHP syntax and the existing 477 policy/REST/durability regression assertions pass. Single-lesson reads retain their SQL and now share the extracted item-policy function with the course batch reader.
- 34 Node tests pass. Five new real-DOM tests cover one read across multiple placements, counts and safe titles/URLs, stale-result suppression, loading/error/auth behavior, refresh focus, malformed totals/duplicate lessons, empty and optional-only scopes, and access-limited messaging. Returning from browser history refreshes results.
- Seven package tests pass. Both new runtime files are required by the deterministic package builder; the ZIP contains no tests, fixtures or credentials.
- `tests/wordpress-course-progress.php` runs against real WordPress, Sensei, H5P and MariaDB in every integration profile. Its 80 assertions compare every returned batch item with the canonical single-lesson item, including score ties, later lower scores, historical completion, zero-threshold optional items, shared activity IDs with different lesson thresholds, custom fractional thresholds and empty lessons.
- Access tests cover two enrolled learners, an outsider, a foreign teacher, the course owner and administrator; forged `user_id`, anonymous requests, invalid IDs, private/draft/trash/password-protected content and Sensei's access filter. Published inaccessible lessons are omitted with a limited-scope flag. Real HTTP Application Password authentication confirms learner isolation and no-store headers; the temporary credential is revoked.
- Real rendering tests cover block/shortcode registration, nested/current-course context, direct template rendering, saved shortcode fallback, width/padding, identical nonpersonal server HTML for different viewers, foreign Query Loop suppression, no markup without activities, and separation from lesson tracking assets. Both editor/frontend labels are checked in English and Bokmål. OPTIONS exposes the success schema.
- SQL and custom-filter failures return errors without partial results or private exception text. Oversized or invalid filtered lesson lists fail visibly. Attempt history is unchanged by reads. 200-lesson/500-pair bounds and a 10,000-attempt synthetic history exercise the batch reader.

## Performance correction

The first correlated-join implementation took approximately 30 seconds for 500 current activity pairs with 10,000 attempts in one lesson. That exceeds the frontend's 15-second request timeout and was rejected before delivery. The reader now resolves latest/best IDs and completion in one bounded query, then fetches at most 1,000 selected rows in a second query. The lookups explicitly use the existing `event_identity` index prefix `(user_id, lesson_id, content_id)`; otherwise stale optimizer statistics can choose a lesson-wide scan for each activity. EXPLAIN confirms all three correlated lookups use those three columns. No new index or window-function support is required. The targeted local rerun passed all 80 assertions and measured 44 ms for the populated case; [measurement and query-plan evidence](benchmarks/hsb012-course-progress.json) records that single sample. The integration suite requires the 500-pair/10,000-attempt case to finish within a generous five seconds; logs record actual time. This synthetic check is not a production capacity or concurrency guarantee.

## Local FagKlar check and remaining visual verification

On the authorized localhost:8887 site, a dedicated teacher-owned test course with three lessons returned two H5P requirements, one optional activity and two nonempty lesson summaries for the enrolled test learner. The same H5P ID appeared in two lessons. A real HTTP request through FagKlar rendered exactly one dynamic course card, without the saved shortcode leaking into the page or private learner configuration in the guest HTML. The public API schema was present. Only this test course/three lessons were moved to Trash afterward; its test enrollment was removed. Existing user content and attempt history were not changed.

**Manual visual/editor interaction remains unverified for this new course block.** The browser connection failed before local navigation (including recovery attempts), and the native-window fallback was unavailable. Server rendering and DOM tests are evidence for behavior, not a replacement for inspecting the live layout, keyboard interaction and mobile appearance. Recheck native insertion/save/reopen in course and Site Editor, the enrolled learner's desktop/mobile view, and an actual H5P attempt followed by return to the course when browser access is restored. The preceding lesson progress UI's visual checks remain documented separately in HSB-011.

## Delivery gate

Run the full eight-job GitHub matrix for the committed candidate: PHP 8.0/8.2/8.4/8.5 regressions; WordPress 6.9 + PHP 8.2 and WordPress 7.1.4 + PHP 8.2/8.4 integrations; packaging. The local representative integration profile uses WordPress 7.1.4, PHP 8.2.32, Sensei 4.26.4, H5P 1.17.10 and MariaDB 10.11.18. Record the passing workflow with delivery. No release tag, production deployment or implicit expansion to other HSB-012 extensions is included.

Site-specific order/access/threshold filters, arbitrary H5P libraries, very large histories, production proxy/cache behavior and screen-reader speech still require their own checks. The widget never owns native Sensei completion.
