# Engineering delivery plan — develop reconciliation

Updated 2026-11-01. This plan replaces the main-only execution order. The owner reports that `develop` has been used live for some time. That is operational context, not a substitute for checking the deployed SHA, site-specific hooks, dependency versions or database state.

## Baselines and decision

- Reviewed development baseline: [`830975c`](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/commit/830975c6fa00049708d5549f438b1fabc9822259).
- Documentation baseline on main: [`b5c8d03`](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/commit/b5c8d03013cde7103b841a27889ef604d04892fe).
- Develop has five commits absent from main; main has the documentation commit absent from develop. Develop changes nine files from their common ancestor `41795ed`.
- Preserve the development features and the main documentation. Review fixes on an isolated integration branch; do not deploy or move main/develop as part of this review.
- **Merge recommendation: hold pending the staging gate in [DEVELOP_REVIEW.md](DEVELOP_REVIEW.md).** Local regression checks passed; WordPress/Sensei/H5P integration has not been exercised here.

The [original main-only review](MAIN_BASELINE_REVIEW.md) remains historical evidence. Its statements about missing per-activity thresholds, frontend HTTP handling, and malformed CSS do not describe develop. Its task IDs remain stable below; a task is not Verified merely because part of it already exists.

## What already exists on develop

| Area | Implementation | Remaining work |
| --- | --- | --- |
| Per-activity thresholds | [Threshold helper](../h5p-sensei-bridge/includes/class-fkhs-helpers.php), map editor, REST and report integration | Align consumers and validate migration; do not rebuild as a new feature |
| Completion-only activities | Zero threshold, sticky completion, optional before first stored attempt | Confirm intended product semantics and event provenance |
| Student feedback | Best score, progress, remaining items, overlays after evaluation | Reliability, accessibility and localization |
| Gate controls | Quiz/lesson control handling, admin bypass, server completion/quiz hooks | Stable listeners and shared server decisions; actual Sensei hook integration |
| Transport | HTTP status checked for POST and GET; no-store controls | Visible failures, persistence acknowledgement, idempotency and stale response handling |
| Reports | Effective threshold per row; latest 200 attempts by default | Full-history filtering/pagination, teacher scope and query improvements |
| Initialization/styles | H5P bind-once flag and cleaner CSS | Bounded readiness retry, broad DOM observer/footer CSS, delayed-binding races |

## Architecture and bounded corrections

Keep the plugin architecture. Add the small [FKHS_Progress](../h5p-sensei-bridge/includes/class-fkhs-progress.php) read service rather than a framework or separate backend. It extracts the existing develop status queries and policy, and is shared by [REST](../h5p-sensei-bridge/includes/class-fkhs-rest.php) and [Sensei guards](../h5p-sensei-bridge/includes/class-fkhs-sensei.php). REST retains existing fields and adds `required`, `passed`, `counts_for_gate` and `requirement_met`. Frontend prefers those decisions while retaining a fallback for older responses.

Compatibility contract for this candidate:

- Positive threshold: required even without an attempt; current best percentage or latest stored pass satisfies it, matching develop's student UI.
- Zero threshold: optional without a stored attempt; once attempted it requires completion. Any historical completed row satisfies completion-only content.
- No detected H5P: no gate. Discovery still scans shortcodes only.
- Threshold precedence remains new map → legacy map → lesson threshold → 70. The lesson filter now runs once, after item filters, for every consumer and its result is clamped to 0–100. Non-idempotent custom filters therefore need staging tests.
- Old `require` metadata no longer disables server enforcement independently of the student UI. This deliberately closes the mismatch after develop's editor removed that setting. Administrator/teacher exemption hooks remain.
- No attempts or old pass/completion flags are rewritten. No schema migration, table deletion or automatic regrading is included. Previous deletion of legacy settings cannot be undone without a backup.

See [the review findings and behavioral impact](DEVELOP_REVIEW.md) before deployment. Shared reads are an incremental step; POST validation/access, event trust and performance remain separate work.

## Reconciled backlog

Statuses: Planned → Implemented → Verified → Released. “Implemented (partial)” below means a focused correction exists in this candidate and local checks passed; the broader task and staging verification remain open.

| Order | ID / owner | Status | Dependencies | Acceptance / next action |
| --- | --- | --- | --- | --- |
| 1 | HSB-001 — Backend/QA: access and fixtures | Planned; minimal regression harness added | — | Pin the deployed SHA and actual WP/PHP/Sensei/H5P versions. Replace truthy-ID enrollment placeholder, validate accessible lesson/content membership and payload structure. Unauthenticated, unenrolled, wrong-lesson and malformed attempts must not insert rows; legitimate users still work. |
| 2 | HSB-002 — Frontend: gate lifecycle | Implemented (partial) | Existing local fixture | Stable handlers and control-state restoration tested. Complete browser checks for click/keyboard/forms, dynamically inserted controls, custom Sensei disabled states, missing/late H5P, observer work and bounded retry. |
| 3 | HSB-003 — Backend: policy consistency | Implemented (partial) | HSB-001 fixtures | Shared status/guard reads and threshold chain added. Verify live thresholds, scoreless histories, raised/lowered criteria, custom filters and legacy settings on a staging copy. Explicitly decide historical-pass policy; do not regrade existing users implicitly. |
| 4 | HSB-004 — Backend/frontend: durable event flow | Planned; develop already checks HTTP status | 001, 003 | Fix unchecked database insert success, expose saved/failed/retry/re-login states, define event identity before retries, reject ambiguous content mapping and stale response updates. Resolve completion inference and synthetic completion findings R5/R6 before rollout. |
| 5 | HSB-005 — Backend/QA: Sensei integration | Implemented (partial) | 001–004 | Deleted metadata no longer bypasses guards. On the real supported stack verify hook argument order/timing, quiz navigation, lesson completion, learning mode and applicable REST paths; no enrollment/progress side effects from rollback. Missing dependencies must fail gracefully. |
| 6 | HSB-006 — QA/DevEx: CI and compatibility | Implemented (partial) | Initial harness | PHP syntax and dependency-free PHP/Node regressions added. Verify CI results and add real WP/database fixtures plus supported-version matrix. Stub tests do not establish actual SQL or plugin integration correctness. |
| 7 | HSB-007 — UI/UX/frontend: access and language | Planned | 002, 004 | Keyboard/focus/screen-reader/mobile/zoom tests; semantic progress links and filter controls; fix frontend translation handle/path and admin fallback lookup; English/nb_NO checks. Scope CSS and avoid global footer layout overrides. |
| 8 | HSB-008 — Backend/admin UX: reporting/performance | Planned | 003, 006 | Find records older than first 200; server filtering/sorting/pagination and bounded teacher scope. Select needed columns, batch metadata, benchmark query count and p50/p95 at 1/10/50 activities and 1k/10k/100k attempts; choose indexes using EXPLAIN. |
| 9 | HSB-009 — Backend: lifecycle/migration | Planned | 003, 006 | Define retained xAPI data, bounded export/erasure and opt-in cleanup. Test schema upgrades/rollback with populated DB. Preserve data by default; never treat this plan as authorization to delete production attempts. |
| 10 | HSB-010 — Maintainer: release/operations | Planned | Staging gate + 001–009 | Reconcile header 0.1.0 vs changelog 1.1.0/release 1.0.0, license conflict, PHP claim and incorrect ZIP name/layout. Install/upgrade/rollback verified before tag; no release/version bump in this review candidate. |
| 11 | HSB-011 — Full stack/product: progress UI/API | Partially present on develop; remaining work Planned | 010 | Per-activity thresholds already exist. Add only the needed shortcode/block and external API contract; reuse canonical policy, preserve route compatibility and verify access scope. |
| 12 | HSB-012 — Product/integrations: extensions | Planned/deferred | 010 | Select individual needs: full-history CSV/analytics, hints, LRS, badges/notifications, theming, other LMS. Each needs owner, access tests, limits and rollback. CSV formula safety and LRS idempotency are acceptance criteria where applicable. |

## Local evidence

Commands from repository root:

```sh
find h5p-sensei-bridge tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/progress.php
node --check h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js
node --check h5p-sensei-bridge/assets/js/h5p-sensei-admin-report.js
node --test tests/gate.test.cjs
git diff --check
```

Observed locally with PHP 8.3.6 and Node 24.22.1: PHP syntax passes, 52 PHP assertions pass, both Node gate tests pass, JS syntax and whitespace checks pass. The Node tests fail against the develop baseline: 100 renders leave 200 handlers on the test link instead of two, and bypass does not release stale click blockers. Test doubles exercise actual plugin methods/JS closure, not WordPress/browser integration. The new workflow targets PHP 8.0 and Node 22; its results must be checked separately.

| Evidence | Scope | Status |
| --- | --- | --- |
| [PHP regression harness](../tests/progress.php) | Mixed thresholds, zero/optional/sticky state, isolation, filters, preserved metadata/attempts, guard exemption | Local pass |
| [JS regression harness](../tests/gate.test.cjs) | Repeated locking/unlocking, bypass, requirement removal, pre-disabled controls | Local pass; baseline fails |
| [CI configuration](../.github/workflows/checks.yml) | Syntax and same regression tests | Added; check PR run |
| Staging copy of live | Real H5P/Sensei/DB/browser behavior and rollback | Not run; merge gate open |

## VS Code handoff

Fetch and check out the review branch shown in the PR, preserving local uncommitted work. Start with [DEVELOP_REVIEW.md](DEVELOP_REVIEW.md), then this plan and [CONTRIBUTING](../CONTRIBUTING.md). Do not restart per-activity threshold development from the outdated main-only plan.

> Continue the develop reconciliation candidate. Preserve existing attempts and the live optional/completion-only contract. Run the local regression commands, then validate R1–R6 against a sanitized staging copy with the exact deployed plugin/dependency versions. Prioritize actual lesson/content authorization, completion inference and Sensei enforcement. Record pass/fail evidence and behavior changes. Do not mark broader HSB tasks Verified based on stub tests. Keep the PR draft until the staging gate passes; do not deploy or publish a release implicitly.
