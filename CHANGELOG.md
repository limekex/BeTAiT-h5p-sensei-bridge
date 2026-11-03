## [Unreleased] — 1.2.0-dev stabilization candidate

### Added
- Added the Sensei lesson H5P activity block with scoped, paginated registry search, literal shortcode placement and shared block/metabox threshold controls. Native saves, autosaves and revisions preserve lesson-scoped overrides, including explicit zero; synced activity patterns must be detached.

### Fixed
- Keep H5P editor controls compact under theme typography, move explanations into expandable details, and use a searchable replacement dialog. Keep threshold inputs active while previews refresh.
- Version script URLs by plugin version and content hash so reproducible ZIP timestamps cannot keep obsolete browser scripts cached.
- Validate learner enrollment, lesson access, H5P membership and consumed xAPI fields on both REST paths.
- Require actual completion signals; remove synthetic inactivity completion and ambiguous answered/progress inference. Preserve existing completion history.
- Acknowledge attempts only after storage, deduplicate UUID retries and reject changed payloads under the same receipt. Preserve queued retries and reject stale status responses.
- Guard native Sensei lesson/quiz submissions and correct programmatic completion while preserving answers and grades.
- Reuse stable gate listeners and restore original control state after unlock/bypass.
- Use shared per-activity progress reads in REST and Sensei guards, including deterministic latest/best ordering.
- Apply effective threshold filters consistently once and retain legacy metadata on editor save.
- Display inherited thresholds in the editor instead of silently showing zero.

### Added
- Scoped, full-history report search/sort/pagination; metadata batching and measured query plans.
- WordPress personal-data exports, explicit opt-in erasure and administrator raw-statement cleanup with a read-only default.
- Keyboard/focus handling, responsive feedback and Bokmål translations.
- Deterministic installable ZIP, manifest/checksum, native upload and historical-package upgrade/rollback/backup-restore tests; tag builds require checks and create drafts only.
- GPLv2-or-later license text, as confirmed by the maintainer.
- Canonical REST gate/item flags, PHP/Node regression fixtures and CI configuration.
- Develop/main review, updated engineering plan and staging/rollback checklist.

### Changed
- Identify the unreleased candidate consistently as `1.2.0-dev`; schema remains independently `0.3.0`.
- Require WordPress 6.9/PHP 8.2 in installation metadata, matching the lowest full integration profile, and declare Sensei LMS/H5P dependencies.

This candidate is not a release or deployment. Server enforcement is intentionally stricter where old editor saves removed the legacy require flag. Read [the staging gate](docs/DEVELOP_REVIEW.md) before deployment. Historical entries below describe past code: the 1.1.0 entry was a changelog claim, while the published v1.0.0 tag contained header 0.1.0. Neither historical identity is rewritten. The old synthetic completion behavior below is removed in this candidate.

---

## [1.1.0] - 2025-10-10

### Added
- **Server: xAPI completion inference.**  
  New `infer_completion_from_stmt()` detects completion from:
  - `result.completion`
  - verbs ending with `/completed` or `/answered`
  - extension bags in `result.extensions`, `context.extensions`, `object.definition.extensions`, and top-level `extensions`  
  Supports `https://h5p.org/x-api/completed`, `https://h5p.org/x-api/progress`, and `http://id.tincanapi.com/extension/progress`.
- **Front-end: synthetic completion for completion-only items.**  
  For H5P with `threshold = 0`, we synthesize a `completed` xAPI after short inactivity following activity verbs (debounced per content id).
- **Front-end: status panel UI.**  
  Shows overall progress and remaining requirements with scroll-to-target links, and separates **gating** vs **optional** items.
- **Front-end: badges & overlays.**  
  Per-H5P “pass”/“fail” badges and contextual overlays (retry / practice again). Overlays appear only after the first attempt.
- **Front-end: bypass flag.**  
  `window.fkH5P.bypass` disables gating completely (useful for admins/preview).
- **REST: cache control.**  
  Added aggressive `no-store`/`no-cache` headers to `/h5p-status` (plus LiteSpeed hints) to prevent stale UI.
- **i18n: extended strings.**  
  Added keys: `taskPassed`, `notPassedYet`, `notCompletedYet`, `overlayCompleted`, `overlayIncomplete`, `optionalNotice`.  
  (Existing keys retained: `completeH5PFirst`, `blockedNoticeQuiz`, `blockedNoticeLesson`, `overlayPassed`, `overlayNotPassed`, `practiceAgain`, `retryNow`, `statusHeading`, `statusAllPassed`, `statusProgress`, `statusRemaining`, `statusRemainingItem`.)

### Changed
- **Gating logic (front-end).**  
  A lesson is “gated” only if there is at least one gating item:
  - any H5P with `threshold > 0`, or
  - completion-only (`threshold = 0`) **after** the user has made an attempt.  
  Optional completion-only content (no attempt yet) no longer blocks progression.
- **Gate button control.**  
  Gate toggling now respects `require` (derived from `/h5p-status`), `bypass`, and `passed`. Event listeners are added/removed idempotently, and tooltips/notices are used when gated.
- **Admin report (reporter).**  
  The “Criterion” column now shows the **effective threshold** per row via `FKHS_Thresholds::get_effective_threshold(lesson_id, content_id, user_id)` instead of the legacy `_fkhs_pass_threshold` meta.  
  The H5P column shows content title + library/version (e.g., `H5P.DragText 1.10`).

### Fixed
- **Gate not opening after completion.**  
  Reduced races between early xAPI events and the initial status fetch by seeding completion-only sets from `/h5p-status` and binding listeners slightly later.
- **Overlay spam for score-based H5P.**  
  Overlays now appear only after the first attempt; debouncing/state flags reduce repeated popups.
- **Incorrect threshold shown in admin report.**  
  Report now displays the effective (often 0%) threshold for completion-only items.

### Performance
- **Noise reduction for completion-only H5P (server).**  
  For `threshold = 0`, we **do not insert** DB rows unless the attempt is actually `completed = true`.
- **Best-score query tightened.**  
  Computes best score only where `max_score > 0`.

### Upgrade notes
1. **Update i18n payload** to include the new keys listed above when enqueueing the script.
2. **Caching:** ensure REST responses are not cached by proxies/CDN; server sends `no-store`.
3. **Reporter:** adopt the new effective-threshold lookup (already included in this release).

---

## [1.0.0] - 2025-09-xx
### Added
- **Multiple H5P items per lesson with distinct thresholds.**  
  Front-end and server respect per-content thresholds when evaluating pass/completion and gating.
