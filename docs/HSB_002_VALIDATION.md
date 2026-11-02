# HSB-002 — Learning Mode controls and gate lifecycle

Local follow-up on 2026-11-02 after HSB-004. This change covers action ownership, keyboard/form blocking, dynamic controls, startup timing and feedback placement. It is not a claim that every HSB-002 acceptance scenario is complete.

## Finding and implementation

Sensei 4.26.4 deliberately renders a screen-reader-only navigation/action group from `Page_Actions::render_lesson_actions`, in addition to the normal Lesson Actions block. The two controls are not clones made by this bridge or a FagKlar template override. Removing that navigation would remove an accessibility feature. The bridge previously missed the older Learning Mode form/button selectors.

- Both generations of completion/quiz actions now share delegated capture handlers for click, Enter/Space and form submit. Handlers bind once; newly inserted controls are covered before the mutation observer runs. Unrelated navigation, actions and keyboard keys are left alone.
- Locked controls use `aria-disabled`, a bridge-owned class and explanatory feedback. The bridge no longer writes their native `disabled` state or inline opacity. Sensei and its video/completion logic retain ownership of native disabling, including changes made while the H5P gate is locked.
- Unlocking restores only bridge-owned presentation. Controls removed from the action set lose their bridge decoration and retained references. Administrator/teacher bypass and removal of a requirement release the bridge gate.
- The newer `button-lesson-completed` block is a status indicator with no action, according to the installed Sensei source. Its button is kept disabled, matching the older Completed indicator, rather than presenting a focusable no-op button.
- Status and transport feedback share one bridge-owned wrapper before the action area, outside Sensei forms/flex rows and screen-reader navigation. Removed the global footer `display:block !important` override and wrapper visibility resets.
- The DOM observer filters additions/removals for affected controls and coalesces refreshes, rather than rescanning controls for every badge/status mutation. Script startup also works when DOMContentLoaded has already fired.

The installed plugin source is the compatibility evidence: `includes/blocks/course-theme/class-page-actions.php`, `class-lesson-actions.php`, and `assets/blocks/lesson-actions/lesson-completed-block/index.js`. No Sensei or FagKlar source files were edited.

## Validation

`node --test tests/*.test.cjs` passes **17 tests**. Gate tests cover repeated renders, both selector generations, click/keyboard/form capture, dynamically inserted and detached controls, bypass, native disabled/title changes during a lock, and the Completed indicator. The transport suite retains retry/stale-read/completion tests and covers startup before/after DOMContentLoaded, missing/late H5P and explicit recovery. JavaScript syntax and `git diff --check` pass.

Real browser test on the authorized `http://localhost:8887/` stack documented in [HSB-004](HSB_004_VALIDATION.md):

1. Existing completed lesson 106 exposes both Completed indicators as disabled after reload.
2. Added lesson **108**, “HSB – knappetest (lokalt)”, to the dedicated local test course 105, using H5P.TrueFalse 1.8 and threshold 70. Existing results were not reset. Fixture ID is stored in `fkhs_gate_fixture_20261102`.
3. Before a passing result, the visible completion button rejects a real pointer click, Enter and Space. Shift+Tab reaches the intentional screen-reader button; Enter and Space are also rejected there. Course completion remains 1/3.
4. A real incorrect H5P answer (0/1) leaves both actions locked. Retrying with a correct answer (1/1) persists the passing result and releases both actions.
5. The visible completion button then submits through Sensei; both controls become natively disabled during the request. Sensei records completion and displays Completed. Feedback is visually stacked above the action area without changing Sensei's footer layout.

The browser automation's semantic press helper refused aria-disabled controls, so the blocked-control test used an actual pointer click followed by keyboard events and Shift+Tab. This did not remove attributes or execute synthetic page scripts to bypass the gate.

## Bounded H5P startup follow-up

The dispatcher wait now has a 15-second deadline and a maximum of 50 scheduled checks at 300 ms intervals. A background/throttled tab stops on the first callback after the deadline. Only an explicit Retry H5P tracking action starts a new bounded window. Waiting/failure is visible and keeps the student gate locked, even when an earlier status says passed or optional. The existing teacher/admin bypass remains. Lessons with no detected H5P do not wait for a dispatcher.

Successful late/retried binding attaches one listener and refreshes progress. It neither manufactures missed events nor replays work done before binding; reload/repeat may be needed. Readiness checks the H5P event dispatcher, not every library asset or activity's rendering health.

New regression cases verify timeout without remaining timers, a deadline exceeded while a tab is suspended, no parallel retry loops/listeners, late arrival, manual recovery after timeout and no-runtime lessons. Existing transport tests also verify that redirect warnings sit with feedback and disappear after a fresh passing status.

On localhost:8887, a temporary MU filter omitted `h5p-core-*` scripts only for test user 14 on lesson 109. Chrome showed waiting, then the startup error and Retry H5P tracking; retry restarted waiting while quiz controls remained locked. The filter was removed and navigation/reload restored normal H5P operation. No injected failure remains. Late arrival and retry-without-reload recovery are covered by the deterministic JS harness, not this browser fault test.

## Remaining scope

Sensei's two action groups remain intentionally available; their presence alone is not a bridge defect. Full screen-reader assistive-technology testing, mobile/zoom, custom video requirements, broader library loading and every quiz/REST path remain open. See [HSB-005](HSB_005_VALIDATION.md) for the targeted quiz browser test. The server guards remain essential; client-side blocking is not access control.

No new schema change, production deployment, commit or release was made in this follow-up. The plugin symlink on local staging points at the working tree, so the local test site uses these edits immediately.
