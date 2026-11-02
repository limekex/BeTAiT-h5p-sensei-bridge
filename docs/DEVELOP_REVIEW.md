# Develop review and pre-merge gate

Reviewed 2026-11-01 against develop `830975c6fa00049708d5549f438b1fabc9822259` and main `b5c8d03013cde7103b841a27889ef604d04892fe`. The owner reports develop has been live for some time. No production access, deployed SHA, database or actual H5P payload was supplied or inspected. Source review covered PHP bootstrap/helpers/metabox/REST/Sensei/reporting, both JS assets, translations and release tooling. No deployment or production-data change is part of this candidate.

## Findings and disposition

| ID | Finding / source | Disposition |
| --- | --- | --- |
| R1 | [Develop metabox](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/830975c6fa00049708d5549f438b1fabc9822259/h5p-sensei-bridge/includes/class-fkhs-metabox.php) deletes `_fkhs_require_h5p_pass` and lesson threshold; unchanged Sensei guards still depend on them | Corrected in candidate: retain legacy settings and use shared progress reads for guards. Staging must confirm intended stricter enforcement, including old lessons without a map. |
| R2 | [Develop JS](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/830975c6fa00049708d5549f438b1fabc9822259/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js) recreates listener functions inside gate update; removal cannot target old functions | Corrected: stable listener identities/current lock state; restore original disabled/title/ARIA/opacity. Baseline fails and candidate passes the regression tests. |
| R3 | [Threshold helper](../h5p-sensei-bridge/includes/class-fkhs-helpers.php) and REST applied lesson filters differently or twice; editor defaulted absent map values to zero even when runtime inherited 70/legacy threshold | Corrected: one consistent filter chain and editor shows effective inherited value; output bounded 0–100. Inventory custom filters before rollout. |
| R4 | Latest/best ordering lacked ID tie-breaker; server used different history policy from student UI | Corrected: deterministic timestamp/ID ordering and extracted develop status policy. Existing attempts unchanged. Shared SQL exercised by HSB-001/004 integration tests; site-specific history/filter cases remain open. |
| R5 | Baseline completion inference accepted 50 on a 0–100 scale and unqualified `/answered` | HSB-004 ignores unknown-scale progress and unqualified answered events, honors explicit false, and excludes nested results. Regression fixtures pass; library-specific scale adapters require real payload tests before enabling. |
| R6 | Baseline JS emitted synthetic completed after 900ms inactivity following interaction on zero-threshold content | Owner selected actual signals as the default on 2026-11-02. HSB-004 removes the timer; no old completed rows are rewritten. Real library completion behavior still needs staging verification. |
| R7 | At review baseline: enrollment was a truthy-ID placeholder, membership/payload validation absent, and content fallback chose first DOM activity | HSB-001 adds shared authorization and server validation; HSB-004 rejects ambiguous/conflicting client mapping. Site-specific verification remains open. Client-generated results are formative evidence, not tamper-proof exam evidence. |
| R8 | Baseline ignored insert results and exposed transport errors only in debug logs | HSB-004 adds stored receipts, UUID deduplication, visible recovery and stale-read rejection. SQL failures, concurrent delivery and additive upgrade pass targeted tests. See [contract and limits](HSB_004_VALIDATION.md); real-browser recovery remains open. |
| R9 | Shortcode-only discovery, after-completion rollback, template redirect quiz guard | HSB-005 partially addressed: native completion POST is gated before writes; quiz submit/save/page/reset and REST permissions tested over HTTP. Programmatic correction now preserves quiz work across three storage configurations. Two-page block quiz delivery is covered by HTTP and local browser tests. Earlier/third-party hook effects, direct repository writes and Pro/video/prerequisite paths remain open. |
| R10 | Frontend translation registration still targets admin handle/path; admin fallback ignores MSG; report only filters latest N rows; repeated queries and body observer remain | Open HSB-007/008. Develop fixes some UI issues but does not satisfy accessibility, localization or scalability acceptance. |
| R11 | Changelog says 1.1.0 while header/FKHS_VER say 0.1.0; old release 1.0.0 has no attached package; ZIP named betait-spfy; license conflict | Open HSB-010. Changelog claims are not proof of versioned, tested deployment. No tag/version/licensing change made here. |

## Compatibility and data impact

This candidate follows the existing develop **student UI** contract, not main's lesson-wide checkbox model. Zero-threshold content without a persisted attempt is optional. Positive thresholds gate progression. Historical completion remains sticky for completion-only content. A positive-score activity uses best score or the latest stored passed flag as before; earlier scoreless passes followed by failure are not newly made sticky. Changing that policy would require a separate decision.

Server enforcement now applies even if a former editor save deleted the legacy require flag. A lesson that was only visually locked may become server-blocked. Older lessons inheriting 70% can therefore expose previously bypassed requirements. Inspect those lessons before merging/deploying. No automatic regrading or completion-state backfill is included.

Existing result rows and old metadata are preserved; HSB-004 adds nullable identity columns/index in schema 0.3.0. Settings already deleted by earlier versions cannot be reconstructed automatically. The editor displays the effective threshold rather than zero when it would otherwise inherit a value. Saving still materializes map values, so inspect site-specific threshold filters before saving production lessons.

## Staging checklist — required before main/deployment

Use a sanitized copy of the actual live site. Record deployed plugin SHA/package checksum, WordPress, PHP, Sensei, H5P and library versions, active theme/learning mode and custom `fkhs_*` filters. Disable outbound email, payment and external analytics on the staging copy. Take and verify a database/files backup.

| Scenario | Expected result | Evidence |
| --- | --- | --- |
| Legacy lesson with no map, require flag present/absent/off | Document actual inherited threshold and intended gate; compare before/after explicitly | Pending |
| One scored task; two different scored thresholds; mixed scored/zero | Status, displayed requirement, quiz and completion agree | Pending |
| Zero without attempts; imported incomplete attempt; completed then later activity | Optional → unmet when attempted → sticky completed, per agreed contract | Pending |
| Course Presentation, Interactive Video and every other deployed library | Sanitized partial/full/nested xAPI events resolve scale and completion correctly; R5/R6 closed | Pending |
| Browser click, Enter, Space, form submission; dynamic footer | Lock/unlock cycles work; unrelated disabled controls remain disabled | Pending |
| Direct quiz URL, Sensei completion and applicable REST paths | Guard enforced for students; teacher exemptions intentional; no unrelated enrollment/progress removal | Pending |
| Refresh, logout/expired nonce, failed POST/DB insert, concurrent events | No false saved confirmation; recovery behavior documented; HSB-004 gaps addressed | Pending |
| Enrolled/unenrolled student, wrong lesson/activity | Access rule enforced on POST/status without leaking another learner's progress | Pending |
| Save existing lesson, switch plugin back to original develop | Attempts retained; available legacy settings preserved; known prior deletions documented | Pending |
| Old and new package install, translation, mobile/accessibility smoke | No fatal errors/missing assets; understandable UI | Pending |

No staging result is marked passed by the local fixture suite. Keep the PR draft while this gate is open. A successful CI run is necessary, not sufficient, for deployment readiness.

## Local validation and rollback

See [commands and observed results](ENGINEERING_PLAN.md#local-evidence), [HSB-001](HSB_001_VALIDATION.md), [HSB-002](HSB_002_VALIDATION.md), [HSB-004](HSB_004_VALIDATION.md) and [HSB-005](HSB_005_VALIDATION.md). The current suite passes 470 PHP assertions and 17 Node tests with doubles, plus 71 real REST, 23 migration/storage, 154 Sensei HTTP/REST (68 classic, 86 block quiz) and 117 programmatic-completion assertions and the concurrent-delivery check in disposable WordPress/Sensei/H5P/MariaDB. HSB-006 adds a [version matrix and CI evidence](HSB_006_VALIDATION.md); remote checks are still blocked by the account restriction. Separate targeted Chrome tests cover True/False 1.8, Learning Mode keyboard controls, failed-write retry and Sensei classic/block quiz completion; broader staging remains open.

Rollback on staging: restore the previous plugin files/package, clear relevant PHP/asset caches, and compare stored rows/settings against the backup. Retain HSB-004's additive nullable columns/index; legacy inserts remain valid, but old code loses the new receipt/deduplication guarantees. See the [upgrade/rollback contract](HSB_004_VALIDATION.md#additive-schema-upgrade-and-rollback). Restoring files does not undo Sensei state changes made during test completions; use the fixture/database snapshot for that. Do not restore a production database over newer learner activity without a separate recovery plan.

The review branch combines both source histories and preserves main's documentation. Main and develop are not advanced, no release is published and no production deployment is performed by this review.

## CI follow-up

Reconfirmed 2026-11-03 from the [latest main run annotation](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41360546477): “The job was not started because your account is locked due to a billing issue.” The workflow did not execute its test steps. The account owner must resolve the billing lock, then re-run the checks on the current PR head. No workflow/test change can resolve this account restriction. CI remains unverified; local passing tests and the staging requirements are unchanged.
