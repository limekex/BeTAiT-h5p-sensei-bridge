# Engineering delivery plan — develop reconciliation

Updated 2026-11-03. This plan replaces the main-only execution order and now includes the requested Gutenberg activity picker and threshold editing at placement. The owner reports that `develop` has been used live for some time. That is operational context, not a substitute for checking the deployed SHA, site-specific hooks, dependency versions or database state.

## Baselines and decision

- Reviewed development baseline: [`830975c`](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/commit/830975c6fa00049708d5549f438b1fabc9822259).
- Documentation baseline on main: [`b5c8d03`](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/commit/b5c8d03013cde7103b841a27889ef604d04892fe).
- Before reconciliation, develop had five commits absent from main; main had the documentation commit absent from develop. These are historical baselines.
- Reconciliation PR [#2](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/pull/2) is merged; the locally reviewed main baseline is `3417d3b99f9285f2515564aa83828e3a2138b636`. Continue from that reconciled baseline on focused branches, preserving local work and existing attempts.
- **Rollout recommendation: hold pending the staging gate in [DEVELOP_REVIEW.md](DEVELOP_REVIEW.md).** That review's pre-merge/draft wording is historical; its unresolved acceptance checks still apply before deployment. Local regressions and disposable WordPress/Sensei/H5P REST/database tests passed; real library/browser and site-specific integration remain open.

The [original main-only review](MAIN_BASELINE_REVIEW.md) remains historical evidence. Its statements about missing per-activity thresholds, frontend HTTP handling, and malformed CSS do not describe develop. Its task IDs remain stable below; a task is not Verified merely because part of it already exists.

## What already exists on develop

| Area | Implementation | Remaining work |
| --- | --- | --- |
| Per-activity thresholds | [Threshold helper](../h5p-sensei-bridge/includes/class-fkhs-helpers.php), map editor, REST and report integration | Align consumers and validate migration; do not rebuild as a new feature |
| Completion-only activities | Zero threshold, sticky completion, optional before first stored attempt | Confirm intended product semantics and event provenance |
| Student feedback | Best score, progress, remaining items, overlays after evaluation | Reliability, accessibility and localization |
| Gate controls | Quiz/lesson control handling, admin bypass, server completion/quiz hooks | Stable listeners and shared server decisions; actual Sensei hook integration |
| Transport | HTTP status checked for POST and GET; no-store controls | Visible failures, persistence acknowledgement, idempotency and stale response handling |
| Reports | Effective threshold per row; candidate adds full-history server search and bounded teacher scope | Validate deployed teacher extensions and realistic concurrent workloads; see HSB-008 |
| Initialization/styles | H5P bind-once flag and cleaner CSS | Bounded readiness retry, broad DOM observer/footer CSS, delayed-binding races |

## Architecture and bounded corrections

Keep the plugin architecture and the implemented [FKHS_Progress](../h5p-sensei-bridge/includes/class-fkhs-progress.php) read service rather than introducing a framework or separate backend. It extracts the existing develop status queries and policy, and is shared by [REST](../h5p-sensei-bridge/includes/class-fkhs-rest.php) and [Sensei guards](../h5p-sensei-bridge/includes/class-fkhs-sensei.php). REST retains existing fields and adds `required`, `passed`, `counts_for_gate` and `requirement_met`. Frontend prefers those decisions while retaining a fallback for older responses.

Compatibility contract for this candidate:

- Positive threshold: required even without an attempt; current best percentage or latest stored pass satisfies it, matching develop's student UI.
- Zero threshold: optional without a stored attempt; once attempted it requires completion. Any historical completed row satisfies completion-only content.
- No detected H5P: no gate. Discovery still scans shortcodes only.
- Threshold precedence remains new map → legacy map → lesson threshold → 70. The lesson filter now runs once, after item filters, for every consumer and its result is clamped to 0–100. Non-idempotent custom filters therefore need staging tests.
- Old `require` metadata no longer disables server enforcement independently of the student UI. This deliberately closes the mismatch after develop's editor removed that setting. Administrator/teacher exemption hooks remain.
- No attempts or old pass/completion flags are rewritten. HSB-004 adds nullable event identity columns and a unique index in schema 0.3.0; no table deletion or automatic regrading is included. Previous deletion of legacy settings cannot be undone without a backup.
- Owner decision for HSB-004: actual root completion signals are the default. Remove the synthetic inactivity timer and ambiguous progress/answered inference; add library-specific exceptions only after tests. Existing completions and the zero-threshold optional-before-attempt policy remain intact.

See [the review findings and behavioral impact](DEVELOP_REVIEW.md) before deployment. Shared reads are an incremental step; POST validation/access, event trust and performance remain separate work.

## Reconciled backlog

Statuses: Planned → Implemented → Verified → Released. “Implemented (partial)” below means a focused correction exists in this candidate and local checks passed; the broader task and staging verification remain open.

| Order | ID / owner | Status | Dependencies | Acceptance / next action |
| --- | --- | --- | --- | --- |
| 1 | HSB-001 — Backend/QA: access and fixtures | Implemented (partial); site-specific staging pending | — | Shared GET/POST access, real Sensei enrollment, scoped editor preview, shortcode/registry membership and consumed payload validation added. See [implementation and test contract](HSB_001_VALIDATION.md). Record targeted test results; still pin the deployed SHA/versions and validate real library events and site-specific access extensions before rollout. |
| 2 | HSB-002 — Frontend: gate lifecycle | Implemented (partial); targeted browser pass | Existing local fixture | Both Sensei action generations share delegated guards with native disabled ownership. Feedback layout/observer work are scoped. Late script startup and a bounded 15-second H5P dispatcher wait with manual retry are covered; the second action group is intentional screen-reader navigation. See [validation](HSB_002_VALIDATION.md). Remaining: mobile/assistive technology, broader library loading and custom quiz/video requirements. |
| 3 | HSB-003 — Backend: policy consistency | Implemented (partial) | HSB-001 fixtures | Shared status/guard reads and threshold chain added. Verify live thresholds, scoreless histories, raised/lowered criteria, custom filters and legacy settings on a staging copy. Explicitly decide historical-pass policy; do not regrade existing users implicitly. |
| 4 | HSB-004 — Backend/frontend: durable event flow | Implemented (partial); real library/browser staging pending | 001, 003 | Stored receipts, UUID deduplication, visible failure/manual retry/re-login, bounded queue, strict content mapping and stale-read rejection implemented. Actual completion signals replace inactivity/ambiguous progress inference by owner decision. Populated additive schema upgrade, failed writes and concurrent delivery tested; see [contract and evidence](HSB_004_VALIDATION.md). |
| 5 | HSB-005 — Backend/QA: Sensei integration | Implemented (partial); native HTTP/REST and browser pass | 001–004 | Native completion POSTs are now rejected before Sensei writes; real HTTP tests cover quiz submit/save/page/reset, preserved work, REST permissions and positive submission. Learning Mode confirms grading/completion; see [scope and evidence](HSB_005_VALIDATION.md). Programmatic fallback now reopens status without deleting answers/grades; three native storage configurations are covered. Two-page block quizzes now have HTTP and local Learning Mode browser coverage. Remaining: earlier/third-party hook side effects, direct repository writes, broader quiz variants and Pro/video/prerequisite integrations. |
| 6 | HSB-006 — QA/DevEx: CI and compatibility | Implemented (partial); local and GitHub matrix pass | Initial harness | Four PHP regression versions and three full WordPress/PHP integration profiles, CLI/HTTP version assertions, diagnostics/artifacts and bounded CI jobs added. See [matrix and limits](HSB_006_VALIDATION.md). GitHub run on `2479a26` passed all seven jobs after billing was resolved; historical minimum-version claims and broader integrations remain open. |
| 7 | HSB-007 — UI/UX/frontend: access and language | Implemented (partial); targeted Chrome/locale pass | 002, 004 | Translation handles/fallbacks and Bokmål catalog, semantic activity controls, inline result feedback and accessible report dialogs added. Keyboard/focus, en_US/nb_NO and 320px reflow checked; see [evidence](HSB_007_VALIDATION.md). Actual screen-reader speech, verified 200% zoom, other browsers and full WP-admin report UI remain open. |
| 8 | HSB-008 — Backend/admin UX: reporting/performance | Implemented (partial); local SQL, browser and benchmark pass | 003, 006 | Full-history server filtering/sorting/pagination, course-owner teacher scope, selected columns and batched report/progress metadata implemented. 317 report assertions and the nine-case 1/10/50 × 1k/10k/100k benchmark passed; EXPLAIN comparisons did not justify new indexes. See [contract and measurements](HSB_008_VALIDATION.md). Remaining: deployed co-teacher/custom scopes, larger/concurrent realistic workloads, deep pagination and batching the remaining progress reads. Current runtime criterion is display-only, not a misleading global SQL filter. |
| 9 | HSB-009 — Backend: lifecycle/migration | Implemented (partial); targeted lifecycle verification | 003, 006 | Native WordPress privacy export, opt-in bounded erasure, future raw-data opt-out and administrator-only dry-run/apply cleanup added. Default retention and progress/receipt preservation tested with populated upgrade/uninstall/reactivation and legacy insert compatibility. See [operator contract and evidence](HSB_009_VALIDATION.md). Remaining: deployed retention policy, external-store/Sensei coordination, large/concurrent exports, multisite and historical release-package rollback. No production deletion is authorized by this plan. |
| 10 | HSB-010 — Maintainer: release/operations | Planned | Staging gate + 001–009 | Reconcile header 0.1.0 vs changelog 1.1.0/release 1.0.0, license conflict, PHP claim and incorrect ZIP name/layout. Install/upgrade/rollback verified before tag; no release/version bump in this review candidate. |
| 11 | HSB-013 — Editor/backend: Gutenberg H5P activity picker | Planned; requested 2026-11-02 | 001, 003–007; rollout after 010 | Search/select existing H5P activities, place the correct shortcode in the lesson layout and optionally set the threshold immediately. Keep the metabox as a synchronized lesson-wide overview. See the delivery and acceptance contract below. |
| 12 | HSB-011 — Full stack/product: progress UI/API | Partially present on develop; remaining work Planned | 010 | Per-activity thresholds already exist. Add only the needed student-facing progress shortcode/block and external API contract; reuse canonical policy, preserve route compatibility and verify access scope. The editor activity picker is HSB-013, not a duplicate progress block. |
| 13 | HSB-012 — Product/integrations: extensions | Planned/deferred | 010 | Select individual needs: full-history CSV/analytics, hints, LRS, badges/notifications, theming, other LMS. Each needs owner, access tests, limits and rollback. CSV formula safety and LRS idempotency are acceptance criteria where applicable. |

## HSB-013 — Gutenberg activity picker and thresholds at placement

Requested authoring flow: insert an H5P block in a Sensei lesson → search the existing H5P activity registry → select an activity → place its shortcode at that location → optionally set its threshold before the first save. Keep the metabox as the consolidated view of all lesson activity thresholds. Creating or uploading H5P content is outside this feature's initial scope.

### Editor experience

- Provide a dedicated Gutenberg block with searchable, paginated results showing activity title, content ID and library/type. Support title and ID lookup, keyboard selection, and clear loading, empty and error states. Search only activities the editor is allowed to use; do not load the entire registry into the browser.
- Selection places the existing activity at the block's position in the layout. Generate the shortcode from a validated content ID, without requiring the author to type it. Preserve the selected activity when moving or reopening the block; allow replacement with another activity.
- Show an optional threshold control at placement: **inherit** or **explicit 0–100**. Display the inherited/effective value separately from the stored override, and explain the agreed zero-threshold semantics. A zero must not be treated as an empty value or replaced by 70.
- Keep the metabox as a lesson-wide overview, including newly inserted, unsaved activities. An edit in either location must be reflected in the other before save; the author must not have to save merely to discover or configure the activity.
- Initial scope is Sensei lesson editing. Do not imply that placing the block on arbitrary pages enables lesson gating. Provide understandable missing-H5P and deleted/unavailable-activity states.

### Persistence and compatibility contract

- Recommended representation: a block that retains the selected content ID and saves the corresponding literal H5P shortcode in post content. Verify serialization, reload and frontend rendering in the implementation spike. The current shortcode discovery must find the activity; a rendering-only approach that hides the ID from stored content is not sufficient without an explicit discovery change.
- Keep `_fkhs_threshold_map`, keyed by content ID within a lesson, as the canonical persisted override. Block controls and the metabox edit one shared editor state. Do not introduce a second authoritative threshold in shortcode attributes or block attributes.
- Adapt the metabox save path and Gutenberg persistence together. A stale metabox POST, or an editor save without the legacy field, must not overwrite or delete a newer map. Validate permissions, content membership and numeric range on the server. Failed saves must remain visible and recoverable.
- Store an explicit author-entered override, not the result of user-specific or other runtime filters. **Inherit** removes the new-map override and follows the existing legacy-map → lesson → default chain and applicable filters; explain when a legacy value still applies.
- Repeated placement of the same H5P content in one lesson shares one threshold and one progress record scope under the existing model. Reflect threshold changes in every occurrence and explain this in the editor. Independent thresholds per occurrence would require a separate identity/data-model change.
- Replacing an activity reads the replacement's existing lesson threshold or inherited value; it must not silently transfer the previous activity's threshold. Removing or replacing a block must preserve other occurrences, legacy shortcodes and all stored attempts. Do not introduce automatic historical-data cleanup.
- Existing manually entered shortcodes and lessons remain supported without conversion. Test copy/paste between lessons, undo/redo, autosave, reload and revision restoration so content and threshold metadata cannot silently diverge. Define synced-pattern/reference handling explicitly before claiming support.

### Delivery order and acceptance

1. After the access and progress-policy foundations, build an editor spike proving selection → saved shortcode → H5P rendering → discovery by the shared status/guard service. Establish authorized, bounded registry search and save behavior using the supported H5P stack.
2. Add the shared threshold editor state and metabox synchronization. Verify changes before the first save and after reload; cover inherit, explicit zero, positive thresholds, duplicate activities, replacement and custom runtime filters.
3. Run real Gutenberg/WordPress tests for nested layouts, manual shortcodes alongside blocks, unauthorized search/save, removed activities, save failures, autosave/revisions and missing dependencies. Confirm that REST status, frontend feedback and Sensei enforcement agree for block-inserted activities.
4. Ship as the first authoring enhancement after the stabilization release gate (HSB-010), ahead of optional reporting/integration extensions. Planning and isolated implementation can proceed once the listed foundations are ready; rollout still requires staging evidence, accessible keyboard interaction and English/nb_NO verification.

Acceptance is an author placing an existing activity and configuring its threshold without an intermediate save, then seeing the same activity and requirement in the block, metabox and student flow after save/reload. No existing attempts are rewritten, and no legacy content conversion is required. This section records planned work, not an implemented block.

## Local evidence

Commands from repository root:

```sh
find h5p-sensei-bridge tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/progress.php
php tests/rest.php
php tests/durability.php
node --check h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js
node --check h5p-sensei-bridge/assets/js/h5p-sensei-admin-report.js
npm ci --ignore-scripts
npm test
bash tests/integration/run.sh
git diff --check
```

HSB-005 local follow-up on 2026-11-02 with PHP 8.5.7 and Node 22.22.2: 470 PHP assertions and 17 Node tests passed, plus PHP/JS/shell syntax and whitespace checks. The original reconciliation tests reproduced handler accumulation and stale bypass blockers on develop; the current gate harness tests delegated handlers and native control ownership. That disposable WordPress run passed 71 REST, 23 migration/storage, 154 Sensei HTTP/REST (68 classic, 86 block quiz) and 117 programmatic-completion assertions across three storage configurations, plus concurrent delivery. Test doubles and real REST/SQL checks remain distinct from browser/library tests. HSB-006 expands the workflow to PHP 8.0/8.2/8.4/8.5, Node 22 and three full integration profiles; see [current compatibility evidence](HSB_006_VALIDATION.md). Remote results remain separate.

| Evidence | Scope | Status |
| --- | --- | --- |
| [PHP regression harness](../tests/progress.php) | Mixed thresholds, zero/optional/sticky state, isolation, filters, preserved metadata/attempts, guard exemption | Local pass |
| [HSB-002 validation](HSB_002_VALIDATION.md) | Delegated gate, both Sensei controls, native disabled ownership, startup and feedback placement | 4 gate tests plus transport/startup regressions pass; real click/keyboard and fail→pass→Sensei completion verified on local Learning Mode |
| [HSB-005 validation](HSB_005_VALIDATION.md) | Native HTTP form guards, REST permissions, preserved work and classic/block quiz completion | Targeted pass; earlier/third-party hook side effects and broader integrations remain open |
| [HSB-001 validation](HSB_001_VALIDATION.md) | Access/payload regressions and disposable real REST/database fixture | Local pass: 373 access/payload assertions and 71 real integration assertions; wider staging remains open |
| [HSB-004 validation](HSB_004_VALIDATION.md) | Durable receipts, retries, strict completion, stale reads, additive upgrade and concurrent delivery | Local pass: 45 PHP assertions, 9 additional JS tests, 23 migration/storage assertions and concurrent insert verification |
| [FagKlar browser smoke](HSB_004_VALIDATION.md#fagklar-local-browser-smoke--2026-11-02) | True/False 1.8 with 70/0 thresholds, actual events, Sensei lesson completion and failed-write/manual retry | Targeted pass on localhost:8887; broader library, quiz, lifecycle and accessibility coverage remains open |
| [HSB-007 language/accessibility](HSB_007_VALIDATION.md) | 22 Node tests, 12 WordPress locale checks, keyboard/focus and 320px browser tests | Targeted pass; wider assistive-technology coverage open |
| [HSB-006 CI/compatibility](HSB_006_VALIDATION.md) | Four PHP regression versions, three integration profiles, runtime verification and artifacts | Local matrix and all seven GitHub jobs passed on `2479a26` |
| [HSB-008 report/performance](HSB_008_VALIDATION.md) | Full-history REST/SQL, scoped counts, metadata batches, nine benchmark cases and actual WP-admin browser flow | Local targeted pass; site-specific scope and production-load validation remain open |
| [HSB-009 data lifecycle](HSB_009_VALIDATION.md) | Native privacy callbacks/ZIP, bounded cleanup/erasure, explicit opt-in and populated lifecycle compatibility | Targeted disposable tests; actual site policy, external stores and release rollback remain open |
| Staging copy of live | Deployed libraries/extensions, full access/progression matrix and package rollback | Not run; local smoke is not a production-copy validation; rollout gate open |

Updated 2026-11-03 after the owner resolved billing: the [HSB-006 GitHub run](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41545955184) on `2479a260b6f220d12c4340512ab88a7a33ddc348` passed all seven jobs (four PHP regressions and three full integration profiles), including actual test steps and artifact upload. The formerly blocked [main rerun](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41360546477) on `3417d3b99f9285f2515564aa83828e3a2138b636` was rerun and passed. The [HSB-007 run](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41666234080) on `fadff83` also passed all seven jobs. The [HSB-008 run](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41670061205) on `b1cb4d2` also passed all seven jobs. These results apply to those revisions; newer changes require their own checks. The historical billing failure is resolved, while the broader staging/release limits remain.

## VS Code handoff

Start a focused branch from the reconciled main baseline, preserving local uncommitted work. Read [DEVELOP_REVIEW.md](DEVELOP_REVIEW.md) as the historical review with still-open staging checks, then this plan and [CONTRIBUTING](../CONTRIBUTING.md). Do not restart per-activity threshold development from the outdated main-only plan.

> Continue stabilization of the merged reconciliation baseline. Preserve existing attempts and the live optional/completion-only contract. Run the local regression commands, then validate R1–R6 against a sanitized staging copy with the exact deployed plugin/dependency versions. Prioritize actual lesson/content authorization, completion inference and Sensei enforcement. Record pass/fail evidence and behavior changes. Do not mark broader HSB tasks Verified based on stub tests or merge status. Follow with HSB-013 for searchable H5P placement and shared block/metabox threshold editing. Do not deploy or publish a release implicitly.
