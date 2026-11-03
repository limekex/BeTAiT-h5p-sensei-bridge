# HSB-011 — Lesson progress UI/API

Implemented on `feature/hsb-011-lesson-progress`, 2026-11-03, based on `6b86967`. The owner selected **progress in one lesson**. This is targeted feature verification, not production rollout approval. Plugin remains `1.2.0-dev`, database schema remains `0.3.0`.

See [placement and API contract](PROGRESS_API.md) for supported usage, authentication, fields and failure handling.

## Evidence

- PHP syntax and 477 regression assertions pass (59 progress, 373 REST, 45 durability).
- 29 Node tests pass, including four new real-DOM tests: multiple placements share one GET/listener; canonical mixed/optional requirements and safe titles; pending/failed saves hide stale passes and reuse the same event on retry; stale-read suppression; malformed/auth errors; empty/optional-only lessons; activity focus and recovery focus.
- Seven package tests pass. The new renderer and stylesheet are explicit required package files; no fixtures, tests or credentials ship.
- Disposable WordPress 7.1.4 / PHP 8.2.32 / Sensei 4.26.4 / H5P 1.17.10 / MariaDB integration passes 943 named assertions, plus concurrent-delivery and runtime/benchmark checks. The new suite contributes 30 assertions. It verifies real block/shortcode rendering, no personalized cached HTML, positive/zero/scoreless canonical results, OPTIONS schema, and actual HTTP Application Password authentication for two enrolled learners and an outsider. A forged `user_id` never selects another learner; missing/inaccessible lessons are indistinguishable; invalid and revoked passwords fail. All temporary application passwords are revoked.
- Native translation tests cover the progress editor/frontend labels in `en_US` and `nb_NO`. POT/PO/MO regenerated and gettext validation passes. HTTP fixtures alone set `WP_ENVIRONMENT_TYPE=local`; no production authentication setting changes.
- Chrome at authorized `http://localhost:8887/`, FagKlar theme: created a dedicated teacher-owned lesson and enrolled synthetic student. Inserted **H5P-progresjon** through Gutenberg's native search, saved successfully and inspected the compact editor placeholder. The lesson has two real H5P.TrueFalse 1.8 instances: threshold 70 and threshold 0.
- Student browser: initially 0/1 H5P requirements met and zero-threshold activity optional. The activity button focused its matching iframe. Correct answer at threshold 70 produced 1/1 and best score 100%, retained after reload. Completing the zero-threshold activity with an incorrect answer produced 2/2 with “Completed”, correctly using actual completion rather than score. Sensei still displayed 0/1 lessons complete until its own completion action; the new view never completed the lesson.
- Inspected responsive student layout at 390×844: wrapped scores/help text, visible status badges and no progress-card clipping. Restored normal viewport, closed the agent test tab and expired synthetic login sessions. Existing user content/attempts were not changed.

The first integration attempt failed because the test looked for schema inside `get_routes()` rather than WordPress's OPTIONS response. Correcting the assertion to exercise OPTIONS passed; no runtime schema workaround was needed.

## Limits and rollout

GitHub's full eight-job matrix must pass for the committed candidate before delivery. It covers PHP 8.0/8.2/8.4/8.5 regressions, WP 6.9 + PHP 8.2 and WP 7.1.4 + PHP 8.2/8.4 integration, and packaging. Record its run with the delivered commit.

Existing site-specific HSB-001–010 release gates remain. This change does not establish screen-reader speech, other browser compatibility, live proxy/cache behavior, arbitrary H5P libraries' completion signals or deployed access/filter customizations. External HTTPS/proxy forwarding and site Application Password policy need validation at the consuming site; disposable HTTP tests prove WordPress native authentication and isolation, not production TLS configuration.

No new progress calculation, historical regrading, attempt rewrite, delegated teacher API, course aggregate, external credential store, LRS, notification or release deployment is included. Revert the feature commit to roll back runtime behavior; stored attempts remain intact. The plain shortcode in placed blocks can be removed through the editor if reverting to a version without this feature.

## Template placement follow-up — 2026-11-03

The progress block now has a standalone editor script, loaded in the Site Editor and template-part editor as well as lesson editing. It appears in Design and supports wide/full alignment and margin/padding. Lesson registry/threshold UI stays scoped to lesson authoring.

A dynamic PHP render callback renders the placeholder directly in template markup; it does not depend on `do_shortcode` running on the entire template. Existing blocks retain their literal shortcode fallback and render once. Both block and standalone shortcode return an empty string outside a single lesson or when the queried lesson has no H5P IDs. Foreign Query Loop context is suppressed. The editor keeps an explanatory placeholder for selection and placement.

Targeted real-WordPress assertions now also cover Site Editor asset isolation, direct nested template rendering, saved-block compatibility, dynamic spacing, empty lessons, non-lesson pages and foreign post context. The suite has 38 progress/API and 32 locale/enqueue assertions (953 named integration assertions overall). Local PHP, Node and package checks pass. The full GitHub matrix is required for the delivered commit.

After explicit owner approval, manual Site Editor verification was completed on localhost:8887 using a temporary administrator and a separate template/course/two lessons. The native inserter found H5P-progresjon; wide alignment and 24px vertical padding saved, and the template reopened without block validation errors. The template rendered one progress card above the content of the H5P lesson. The lesson without H5P rendered no progress card, loading notice or legacy status panel. Existing templates/content were not modified. Temporary administrator privileges and sessions were removed after testing; test posts were moved to Trash. The prior student desktop/mobile verification remains as recorded above.

This browser check exposed a legacy footer regression: even though the new block correctly returned no markup, the frontend bridge still injected a status panel on lessons without H5P. The bridge now skips enqueueing on those lessons, using the same queried-lesson discovery as the block. Lessons with H5P retain tracking and status updates. Real WordPress assertions cover this asset boundary and confirm that an empty lesson introduces no server-side H5P completion requirement.
