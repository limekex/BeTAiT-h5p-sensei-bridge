# Engineering delivery plan — BeTAiT H5P → Sensei Bridge

Reviewed: 2026-11-01. Baseline: [`41795edbb4fea34a15bfad6ad0346677c9dda714`](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/commit/41795edbb4fea34a15bfad6ad0346677c9dda714).

This is the working order for backend, frontend, UI/UX, optimization and future features. It supersedes the ordering in the [historical roadmap](../.github/ISSUE_TEMPLATE/roadmap.md), without treating unchecked boxes as proof that a feature is absent. All tasks below start **Planned**; none is completed by this documentation change.

## Evidence and limits

Static review covered every PHP runtime component, both JavaScript assets, repository structure, README, contribution guidance, roadmap and release workflow. GitHub returned zero open issues, zero open PRs and zero Actions runs at review time. A [v1.0.0 release](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/releases/tag/v1.0.0) exists with no attached assets; the reviewed plugin header and FKHS_VER say 0.1.0. No WordPress instance, production database, browser session, integration tests, load tests or packaged installation was exercised. Findings below distinguish visible implementation defects from integration risks requiring reproduction. This is not a production security certification.

The repository has no tracked automated test suite, Composer/package manifest or PR test workflow at this baseline. The sole workflow builds a ZIP on tags. README minimum PHP is 7.4 while the plugin header requires 8.0; README says MIT and references a missing LICENSE, while the plugin header says GPLv2 or later. Resolve these release metadata conflicts with the maintainer; this plan does not select or change the license.

## Architecture assessment

Keep this as a small WordPress plugin. A separate service or frontend framework is not warranted by the reviewed scope.

| Component | Current responsibility | Recommended boundary |
| --- | --- | --- |
| [Bootstrap](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/h5p-sensei-bridge.php) + [FKHS_Plugin](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-plugin.php) | Hooks, activation, duplicate schema definitions, frontend configuration | Dependency checks and one versioned schema definition |
| [FKHS_REST](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-rest.php) | Authentication entry points, policy calculation, SQL, responses | Request validation/access checks; delegate policy and persistence |
| [FKHS_Sensei](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-sensei.php) | Shortcode discovery, enrollment placeholder, completion rollback, quiz redirect | Version-tested Sensei adapter and shared lesson policy |
| [FKHS_Metabox](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-metabox.php) | Lesson requirement and threshold settings | Validated settings consumed by the same policy service |
| [Student JS](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js) | Transport, event mapping, state, CSS, DOM and button guards | Small transport/state/rendering modules with stable listeners |
| [Reporter](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-reporter.php) + [Admin JS](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-admin-report.js) | Recent attempt table; local filtering and pagination | Authorized server queries and accessible report controls |

Recommended flow: authenticated request → lesson/course access and content membership → validated attempt → persistence result → shared policy evaluation → canonical status response. Sensei remains the owner of lesson completion. The browser displays server decisions; browser-supplied scores remain untrusted assessment evidence.

Introduce a small shared policy service and attempt repository incrementally behind existing routes and hooks. Do not perform a broad rewrite before regression coverage exists. Keep current route compatibility unless a documented migration is approved.

## Concrete findings

| ID | Evidence | Consequence / required decision |
| --- | --- | --- |
| F1 | [user_is_enrolled_in_lesson](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-sensei.php#L69) returns only truthiness of IDs; [handle/status](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-rest.php#L119) lacks actual course access validation in status and content-to-lesson membership validation in POST | Login is implemented, enrollment authorization is not. Verify real Sensei access, lesson type/visibility and activity membership on both routes. |
| F2 | [setQuizButtonVisibility](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js#L439) defines handlers per invocation, then removes newly created functions rather than the registered ones | Old closures retain a false passed value and can keep blocking after visual unlock; repeated calls accumulate listeners. |
| F3 | [POST policy](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-rest.php#L278) applies threshold/pass filters; status and [completion policy](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-sensei.php#L138) use separate rules | Filtered decisions, historical passed flags, scoreless success and best/latest attempts can disagree. Zero threshold is allowed by the editor but falsy-defaulted to 70. |
| F4 | [content discovery](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-sensei.php#L45) scans shortcode text only and an empty list passes; [content mapping](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js#L601) can choose the first DOM/Integration content ID | Unsupported embeds can look like no requirement; ambiguous events can be attributed to the wrong activity. Test blocks and nested activities explicitly. |
| F5 | [store_attempt](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-rest.php#L362) ignores insert result; [postXAPI](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js#L99) ignores HTTP status and swallows failures | UI cannot distinguish persisted results from failed saves. Retries currently have no server idempotency key. |
| F6 | [guard_completion](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-sensei.php#L93) acts after completion and conditionally calls a rollback API; quiz guard uses template_redirect | Completion hooks, rollback side effects, REST submission paths and supported Sensei versions require integration validation. Do not assume redirects cover all completion paths. |
| F7 | [status queries](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-rest.php#L119) performs latest/best/metadata queries per activity; [report query](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-reporter.php#L139) loads only latest N rows (default 200), including statement LONGTEXT | Browser pagination/filtering cannot search complete history; query volume and unnecessary payload grow with activities/attempts. |
| F8 | [translation registration](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/includes/class-fkhs-plugin.php#L158) targets the admin handle and an includes/languages path from the frontend enqueue; [translation fallback](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-admin-report.js#L31) does not use MSG when fallback is omitted | Translation paths/fallback behavior need correction. Existing PHP-localized strings partially mitigate this. |
| F9 | [binding/observer](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js#L658) retries indefinitely and observes the entire body; [styles](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js#L13) includes a malformed CSS fragment and a global Sensei footer override | Bound initialization, scoped rendering/styles and coalesced updates should replace repeated global work. |
| F10 | [release workflow](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/41795edbb4fea34a15bfad6ad0346677c9dda714/.github/workflows/release.yml) names ZIP betait-spfy and archives repository root | Package identity/layout and clean installation need verification before another release. |

Client-generated xAPI statements can be forged by an authenticated user. Nonces and enrollment checks protect access; they do not prove a score was earned. Document whether this bridge is intended for formative progress or whether higher-assurance assessment needs a different trusted result source. Do not promise fraud-resistant certification from the present transport.

## Roadmap reconciliation

“Present” means code exists, not verified production behavior.

| Historical item | Reviewed status | Next work |
| --- | --- | --- |
| Cache/fetch robustness | no-store headers and client cache busting present; error/retry handling incomplete | HSB-004 |
| Debug mode | WP_DEBUG/localized toggle and console output present | HSB-004, with redacted diagnostics |
| Improved i18n | PHP strings and localized JS strings present; registration/fallback defects remain | HSB-007 |
| Flexible thresholds | Per-lesson threshold present; per-activity absent | HSB-003 then HSB-011 |
| Admin report | Recent-attempt report present; full per-student/course progress absent | HSB-008 |
| Extended student feedback | Best scores already shown in overlays/status | HSB-007, avoid rebuilding |
| Progressive unlocks | Quiz/lesson button guards and server guards present; reliability unverified | HSB-002, HSB-005 |
| Progress block and external progress API | Not implemented in reviewed tree | HSB-011 |
| Analytics, export, hints, gamification, LRS | Not implemented in reviewed tree | HSB-012 |
| Theming, notifications, other LMS | Deferred ideas | HSB-012 |

## Ordered backlog and acceptance criteria

Priority P0 blocks confidence in progress/access correctness; P1 establishes supportable operation; P2 adds product value. Effort S/M/L is relative, not a schedule. Owner denotes a role to assign in VS Code/project planning.

| Order / ID | Priority | Owner | Effort | Depends on | State |
| --- | --- | --- | --- | --- | --- |
| 1 / HSB-001 | P0 | Backend + QA | M | — | Planned |
| 2 / HSB-002 | P0 | Frontend | S | 001 | Planned |
| 3 / HSB-003 | P0 | Backend | M | 001 | Planned |
| 4 / HSB-004 | P0 | Backend + Frontend | M | 002, 003 | Planned |
| 5 / HSB-005 | P0 | Backend + QA | M | 003, 004 | Planned |
| 6 / HSB-006 | P1 | QA / DevEx | M | 001–005 | Planned |
| 7 / HSB-007 | P1 | Frontend + UI/UX | M | 002, 004, 006 | Planned |
| 8 / HSB-008 | P1 | Backend + Admin UX | M | 003, 006 | Planned |
| 9 / HSB-009 | P1 | Backend | M | 003, 006, 008 | Planned |
| 10 / HSB-010 | P1 | Maintainer / Release | M | 005–009 | Planned |
| 11 / HSB-011 | P2 | Full stack + Product | L | 010 | Planned |
| 12 / HSB-012 | P2 | Product + Integrations | L | 010; feature-specific prerequisites | Planned |

### HSB-001 — Reproducible baseline and real authorization

References: F1, F4; REST and Sensei adapter above.

Set up a disposable WordPress fixture with pinned WordPress, PHP, Sensei and H5P versions; student A/B, unenrolled user, teacher and administrator; lessons with zero/one/multiple activities. Add the minimal test harness needed for the first regression tests now; broaden CI in HSB-006.

Replace the enrollment placeholder using the supported Sensei access API, with an explicit privileged-user policy. Validate lesson existence/type/visibility and activity existence/membership; scope status to the current user and an accessible lesson. Validate nested statement types, score ranges, booleans and payload size before persistence. Decide which top-level events count as attempts; reject ambiguous mappings rather than guessing the first content.

Acceptance: unauthenticated, unenrolled, wrong-lesson, nonexistent activity and malformed requests are denied with no inserted rows; valid enrolled requests pass; cookie/nonce behavior and teacher exemptions have regression tests. Record formative-assessment trust limitations. Do not claim that input validation authenticates the score.

### HSB-002 — Reliable unlock and bounded frontend lifecycle

References: F2, F9.

Move listeners to stable identities or one delegated handler reading current state. Bind once, tear down correctly, narrow/throttle the observer and bound H5P readiness retries. Render only when state changes.

Acceptance: repeated locked → passed → locked transitions work with click, Enter, Space and form submission; 100 repeated render calls do not multiply handlers; late H5P initialization and missing H5P terminate predictably; enabling the gate truly removes blocking behavior. Include a DOM regression test reproducing the old closure bug.

### HSB-003 — One server policy for every consumer

References: F3, F4; metabox, REST status/POST and Sensei guards.

Extract policy resolution/evaluation shared by writes, status, completion/quiz guards and report labels. Decide and document: best versus latest; historical passes after threshold changes; scoreless success; required activity discovery; teacher bypass. Preserve intended behavior through explicit fixtures, not accidental SQL ordering. Distinguish unset threshold from valid zero and apply filters consistently.

Acceptance: thresholds 0/70/100, custom filters, pass-then-fail, threshold increase/decrease, scoreless pass-then-fail, missing/removed content and equal timestamps return consistent outcomes everywhere. Use deterministic ordering with an ID tie-breaker. Return canonical per-activity and overall pass states. Unsupported embeds must produce an actionable configuration warning or supported discovery, not an unnoticed empty requirement.

### HSB-004 — Durable attempts and visible transport state

References: F5; POST/status routes and postXAPI.

Return persistence failures as non-success responses. Define event identity and bounded duplicate handling before adding retries. Handle non-2xx responses, network loss, expired authentication and stale/out-of-order status responses. Coalesce bursts of status refreshes. Keep per-user responses non-cacheable and verify actual headers; README currently claims Vary that the controller does not explicitly set.

Acceptance: failed insert never returns a saved result; retrying the same event creates one logical attempt; simultaneous events do not overwrite newer UI state; users see saving/saved/failed/retry or re-login states. Recovery preserves policy correctness. Debug output must omit nonces and full personal statement payloads.

### HSB-005 — Prove Sensei enforcement and dependency behavior

References: F6; bootstrap, get_h5p_ids_for_lesson and gate_quiz_access.

Pin a supported compatibility matrix and verify completion hook argument order, timing and rollback effects against the actual installed versions. Cover classic forms, learning mode, direct quiz navigation and applicable REST completion/submission paths. Prefer a supported precondition hook where available; otherwise document the verified rollback contract. Handle missing/inactive H5P or Sensei gracefully.

Acceptance: a student cannot complete a required lesson or enter a protected quiz through tested alternate paths before passing; required-off and privileged cases behave as specified; failing an H5P guard does not erase unrelated progress/enrollment. Plugin activation with missing dependencies produces a clear notice without fatal errors or repeated missing-table queries.

### HSB-006 — Automated checks and integration fixtures

References: repository tree and release workflow; CONTRIBUTING currently asks for CI that is not configured.

Add PHP syntax checks, WordPress coding standards with a documented baseline, JS lint and meaningful policy/REST/DOM tests. Add a PR workflow and reproducible local commands. Test the agreed minimum and primary supported versions; do not advertise compatibility beyond evidence.

Acceptance: fresh checkout instructions run locally and in CI; tests catch F1/F2/F3/F5 regressions, multi-activity mapping and required-off behavior. Integration smoke covers create lesson → submit attempt → refresh → unlock → Sensei completion. Record fixture versions and failures, and avoid committing test user data or secrets.

### HSB-007 — Accessible student/admin UX and localization

References: F8/F9; overlay/badge/status rendering, admin filter popups, lesson metabox.

Separate scoped CSS from behavior; remove malformed CSS and avoid overriding an entire Sensei footer globally. Use keyboard-operable badge/filter controls, associated labels, focus restoration, meaningful empty/error states, mobile layout and reduced-motion support. Preserve useful existing best-score feedback. Fix script handle/path and fallback translation lookup, translate hard-coded controls such as Close, and generate translation artifacts through a reproducible process.

Acceptance: keyboard-only task navigation/retry/unlock/report filtering works; status changes are announced without stealing focus; screen-reader checks and 320px/200% zoom checks are recorded. English and nb_NO fixtures show no undefined strings; visible labels reflect the canonical server policy. Use DOM text APIs for dynamic text and review remaining HTML insertion sites.

### HSB-008 — Complete, scoped reporting and measurable query improvements

References: F7; schema in FKHS_Plugin.

Move sorting/filtering/pagination to authorized server queries with stable ordering, totals and whitelisted sort keys. Retain administrator default access; define teacher/course scope before widening capabilities. Select only needed columns and batch activity/user metadata. Batch status lookups, then evaluate a composite index on user/lesson/content/time/id with EXPLAIN; do not add indexes without measurements.

Acceptance: a matching row older than the first 200 attempts is discoverable, pagination has no duplicates under stable fixtures, and users cannot inspect another teacher's scope. Benchmark 1/10/50 activities and 1k/10k/100k attempts; publish query counts, p50/p95, data size and hardware. Status query count should not grow by three per activity. Set latency budgets from this baseline before release.

### HSB-009 — Data lifecycle and safe schema evolution

References: REST stores full statement JSON; activation/maybe_upgrade duplicate schema; uninstall intentionally retains data.

Define which xAPI fields are necessary, retention duration, export/erasure behavior, deleted-user handling and explicit uninstall cleanup options. Preserve data by default until the owner chooses a policy. Unify schema definitions, verify migrations before recording success, and plan backfill/rollback for any new event identity or index. No automatic destructive cleanup is authorized by this plan.

Acceptance: migration succeeds on populated and fresh databases and is safely repeatable; failed migration does not mark itself complete; retention/export/erasure operate in bounded batches on synthetic fixtures; documentation explains retained data and operator controls. Capture backup/restore and rollback evidence.

### HSB-010 — Release identity, installable ZIP and operations

References: F10; README, CHANGELOG, plugin header and v1.0.0 release.

Resolve version/PHP/license inconsistencies with the maintainer and add the selected license file. Correct the artifact name and package only the deployable h5p-sensei-bridge directory with one plugin root. Ensure release permissions, required checks and dependencies are explicit; write concrete changelog entries and support/rollback instructions.

Acceptance: inspect ZIP contents, install through WordPress upload on a fresh fixture, activate, upgrade from the baseline with retained attempts, and uninstall under the documented policy. Release/tag/header/constants agree; no development/test files or credentials ship. Existing v1.0.0 release is historical evidence, not proof of a passing build. No new release until HSB-001–009 acceptance evidence is attached.

### HSB-011 — Per-activity policy and reusable progress UI/API

References: historical roadmap; shared policy from HSB-003.

First add per-H5P threshold overrides with explicit lesson-default inheritance and editor preview. Then add a progress shortcode/block using the canonical status contract. Assess whether extending h5p-status meets external needs before introducing h5p-progress; document authentication, versioning and access scope.

Acceptance: mixed thresholds and lesson edits produce identical API/guard/UI results; existing lessons migrate without behavior surprises; progress widgets are accessible and do not duplicate event listeners. External clients cannot enumerate unauthorized learner progress.

### HSB-012 — Evidence-led advanced roadmap

Build features separately after a product decision and the release gate: (1) scoped CSV export and course aggregates, (2) configurable practice hints, (3) LRS integration, (4) optional badges/notifications. Custom theming can reuse HSB-007 styles. Other LMS adapters remain exploratory until Sensei integration is stable.

Acceptance per selected feature: a named user need, assigned owner, source data definition, access tests, performance budget and rollback strategy. Export must neutralize spreadsheet formula injection; aggregates must define best/latest policy and denominator; LRS requires secure configuration, retry/idempotency and delivery visibility; notifications require explicit opt-in/rate limits. Do not build all stretch goals as one milestone.

## Phase gates and evidence ledger

- **Correctness gate:** HSB-001–005 verified, including server enforcement and visible persistence failures.
- **Operational gate:** HSB-006–009 verified, with compatibility, accessibility, query measurements and migration evidence.
- **Release gate:** HSB-010 verified, install/upgrade/rollback exercised.
- **Product expansion:** HSB-011/012 selected after the release gate; no calendar commitments without capacity estimates.

Track each task as Planned → Implemented → Verified → Released. Implemented requires code/PR references; Verified requires commands/results plus integration evidence where applicable; Released requires tag/package and smoke-test evidence. Add an evidence row when work starts:

| Task | Owner | Commit / PR | Checks and fixture versions | Result / remaining risk | Release |
| --- | --- | --- | --- | --- | --- |
| HSB-001–012 | Unassigned | — | Static planning only | Planned; runtime behavior unverified | — |

## VS Code handoff

1. Fetch main and inspect local changes before pulling; do not overwrite uncommitted work.
2. Read this plan and [CONTRIBUTING](../CONTRIBUTING.md). Create one focused branch per task.
3. Start HSB-001; add its minimal regression harness before policy changes. Use the nested h5p-sensei-bridge directory as the WordPress plugin, not the whole repository.
4. Record the chosen dependency versions, test commands and observed baseline. Existing minimum-version claims are not a tested matrix.
5. Implement one task, run its acceptance checks, and update the evidence ledger/status. Keep broad refactors and future features out of the first fixes.

Suggested coding-agent prompt:

> Read docs/ENGINEERING_PLAN.md and CONTRIBUTING.md. Start HSB-001 only. Inspect current code and local changes, build a reproducible disposable WordPress/Sensei/H5P fixture and minimal regression harness, then implement real lesson access/content membership checks and payload validation. Preserve current routes and Sensei ownership of completion. Do not treat browser scores as trusted assessment proof. Add positive and negative tests, report exact checks and fixture versions, and update the plan with commit/test evidence. Do not mark the task Verified if integration checks could not run. Do not release or implement later tasks implicitly.
