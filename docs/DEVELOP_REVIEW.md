# Develop review and pre-merge gate

Reviewed 2026-11-01 against develop `830975c6fa00049708d5549f438b1fabc9822259` and main `b5c8d03013cde7103b841a27889ef604d04892fe`. The owner reports develop has been live for some time. No production access, deployed SHA, database or actual H5P payload was supplied or inspected. Source review covered PHP bootstrap/helpers/metabox/REST/Sensei/reporting, both JS assets, translations and release tooling. No deployment or production-data change is part of this candidate.

## Findings and disposition

| ID | Finding / source | Disposition |
| --- | --- | --- |
| R1 | [Develop metabox](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/830975c6fa00049708d5549f438b1fabc9822259/h5p-sensei-bridge/includes/class-fkhs-metabox.php) deletes `_fkhs_require_h5p_pass` and lesson threshold; unchanged Sensei guards still depend on them | Corrected in candidate: retain legacy settings and use shared progress reads for guards. Staging must confirm intended stricter enforcement, including old lessons without a map. |
| R2 | [Develop JS](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/830975c6fa00049708d5549f438b1fabc9822259/h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js) recreates listener functions inside gate update; removal cannot target old functions | Corrected: stable listener identities/current lock state; restore original disabled/title/ARIA/opacity. Baseline fails and candidate passes the regression tests. |
| R3 | [Threshold helper](../h5p-sensei-bridge/includes/class-fkhs-helpers.php) and REST applied lesson filters differently or twice; editor defaulted absent map values to zero even when runtime inherited 70/legacy threshold | Corrected: one consistent filter chain and editor shows effective inherited value; output bounded 0–100. Inventory custom filters before rollout. |
| R4 | Latest/best ordering lacked ID tie-breaker; server used different history policy from student UI | Corrected: deterministic timestamp/ID ordering and extracted develop status policy. Existing attempt rows unchanged. Actual SQL still requires integration tests. |
| R5 | [Completion inference](../h5p-sensei-bridge/includes/class-fkhs-rest.php) tests `progress >= 100 || progress >= 0.999`, which also accepts 50 on a 0–100 scale; `/answered` is accepted as complete without content-type qualification | Open merge/rollout blocker. Establish scale per library/key from real sanitized statements; value 1 is ambiguous between 1% and full ratio. Add fixtures for partial/full progress, explicit false, nested activity and answered events before changing semantics. |
| R6 | [Student JS](../h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js) emits synthetic completed after 900ms inactivity following initialized/experienced/interacted/progressed/attempted on zero-threshold content | Open product/rollout decision. This can record mere interaction as completion. Do not silently remove a behavior relied on live; decide per activity type, label it accurately, and test before rollout. No old completed rows are rewritten. |
| R7 | Enrollment remains a truthy-ID placeholder; activity membership/deep payload validation absent; content fallback chooses first DOM activity | Open HSB-001/004. Logged-in access is not actual course authorization. Client-generated results are formative tracking evidence, not tamper-proof exam evidence. |
| R8 | `$wpdb->insert` result is ignored; transport errors are debug-only; synthetic done flag is set before acknowledged persistence | Open HSB-004. Add real persistence acknowledgement, visible recovery and idempotency before retries; no promise of saved attempts on failed inserts. |
| R9 | Shortcode-only discovery, after-completion rollback, template redirect quiz guard | Open HSB-005. Inspect actual Sensei hooks and supported completion/quiz paths, including learning mode/REST; missing dependency behavior not validated. |
| R10 | Frontend translation registration still targets admin handle/path; admin fallback ignores MSG; report only filters latest N rows; repeated queries and body observer remain | Open HSB-007/008. Develop fixes some UI issues but does not satisfy accessibility, localization or scalability acceptance. |
| R11 | Changelog says 1.1.0 while header/FKHS_VER say 0.1.0; old release 1.0.0 has no attached package; ZIP named betait-spfy; license conflict | Open HSB-010. Changelog claims are not proof of versioned, tested deployment. No tag/version/licensing change made here. |

## Compatibility and data impact

This candidate follows the existing develop **student UI** contract, not main's lesson-wide checkbox model. Zero-threshold content without a persisted attempt is optional. Positive thresholds gate progression. Historical completion remains sticky for completion-only content. A positive-score activity uses best score or the latest stored passed flag as before; earlier scoreless passes followed by failure are not newly made sticky. Changing that policy would require a separate decision.

Server enforcement now applies even if a former editor save deleted the legacy require flag. A lesson that was only visually locked may become server-blocked. Older lessons inheriting 70% can therefore expose previously bypassed requirements. Inspect those lessons before merging/deploying. No automatic regrading or completion-state backfill is included.

Existing result rows, schema and old metadata are preserved; settings already deleted by earlier versions cannot be reconstructed automatically. The editor displays the effective threshold rather than zero when it would otherwise inherit a value. Saving still materializes map values, so inspect site-specific threshold filters before saving production lessons.

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

See [commands and observed results](ENGINEERING_PLAN.md#local-evidence). PHP test doubles cover 52 assertions; two Node tests reproduce the original handler failure and pass with the candidate. These fixtures do not execute WordPress, SQL, a browser, or the real Sensei API.

Rollback on staging: restore the previous plugin files/package, clear relevant PHP/asset caches, and compare stored rows/settings against the backup. This candidate has no schema/data migration to reverse. Restoring files does not undo Sensei state changes made during test completions; use the fixture/database snapshot for that. Do not restore a production database over newer learner activity without a separate recovery plan.

The review branch combines both source histories and preserves main's documentation. Main and develop are not advanced, no release is published and no production deployment is performed by this review.

## CI follow-up

The first [Actions run](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41358277345) failed with no test steps and an empty runner name; job logs were unavailable (BlobNotFound). The cause is unconfirmed. Inspect the run annotations and repository Actions configuration before re-running; do not treat this as a failing PHP/JS test or a successful CI verification. CI remains a merge blocker.
