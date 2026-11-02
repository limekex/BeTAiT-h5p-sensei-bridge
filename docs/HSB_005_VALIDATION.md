# HSB-005 — Sensei browser and HTTP/REST validation

Performed locally on 2026-11-02 using the authorized FagKlar site at `http://localhost:8887/`, WordPress 7.1.4 / Sensei 4.26.4 / H5P 1.17.10 / PHP 8.5.7 / SQLite Database Integration 3.1.2. This supplements the separate MariaDB REST/storage tests; it does not complete the full Sensei compatibility matrix.

## Fixture

Added lesson **109**, quiz **110** and boolean question **111** to the existing local HSB course 105. The fixture option is `fkhs_quiz_fixture_20261102`. Student 14 is enrolled through Sensei. The lesson embeds the official H5P.TrueFalse 1.8 example with a 70% threshold. The Sensei quiz uses its question storage/renderer (not serialized quiz blocks), one question, automatic grading, a 100% passmark and `_pass_required=on`. Quiz ownership uses `post_parent=109`; the bridge's installed Sensei API fallback resolves it correctly.

Existing test and site data was not reset. Sensei already had a quiz-progress record after the lesson was displayed; this test does not claim that mere viewing never creates native progress records.

## Observed flow

1. Before a passing H5P result, Take Quiz and the course-outline quiz link are locked. A real pointer click and Enter on the visible quiz button are rejected with an explanation.
2. Navigating directly to `/quiz/hsb-quiztest/` returns the student to `/lesson/hsb-quiztest-lokalt/?fkhs_quiz_blocked=1`; the quiz question is not displayed.
3. An H5P submission with score 0/1 leaves the gate locked. Retrying with a selected correct answer stores 1/1 and opens the quiz controls.
4. Take Quiz then opens the real Sensei quiz. Submitting True displays **Your Grade: 100%** and the passed message; course progress increases from 2/4 to 3/4 lessons complete. Sensei owns the quiz submission/grading and lesson completion.
5. Returning to the lesson retains the quiz grade/completed state and passing H5P history.

The browser test also found that the old fixed-position redirect notice could cover H5P controls and remain after passing. It is now normal-flow feedback, moved into the bridge's status area when JS is available, and hidden after fresh passing status. It remains readable at the footer without JS. No core Sensei or theme files were changed.

## Direct HTTP and REST follow-up

`tests/wordpress-sensei.php` runs through `tests/integration/run.sh` against a disposable WordPress 7.1.4 / Sensei 4.26.4 / H5P 1.17.10 / PHP 8.2 / MariaDB environment. It sends real HTTP requests to Apache with a dedicated enrolled subscriber's session cookie and valid action/REST nonces. Existing local staging content is not reset. The fixture uses native comment-backed Sensei progress and saved boolean answers; no H5P browser execution is claimed by these HTTP tests.

Source inspection found these execution points in Sensei 4.26.4:

- `Sensei_Frontend::sensei_complete_lesson` runs on `wp` priority 10. Previously the bridge only reacted to `sensei_user_lesson_end`, after progress had been saved; the former fallback removed lesson/quiz data. The bridge now checks native `lesson-complete` requests on `wp` priority 1 and redirects before Sensei writes. It matches Sensei's text normalization and nonce action, retains capability exemptions, and leaves native reset requests to Sensei. If headers are already sent, it removes the denied completion action so the later listener cannot process it.
- `Sensei_Quiz` handles submit, save, page change and reset on `template_redirect` priority 10. The bridge's existing quiz guard at priority 1 rejects those requests as well as direct GET navigation before the native handlers.
- The registered `lesson-quiz` REST route edits quiz definitions and requires lesson editing permission. `course-progress/batch` resets progress and requires course editing permission. The inspected core Sensei REST controllers do not expose a student quiz-submit or lesson-complete route. This is a version-specific inventory, not a claim about Pro or third-party endpoints.

Final run: **68 HTTP/REST assertions passed**, alongside the existing 71 REST/database assertions, 23 migration/storage assertions and concurrent-delivery check. The dependency-free suite also passes 470 PHP assertions and 17 Node tests.

HTTP assertions cover:

- Before H5P pass: quiz submit/save/page/reset/GET and lesson-complete POSTs return the expected bridge redirect. Direct database snapshots confirm saved answers, answer metadata and native progress are unchanged. Test-only hook recording confirms no lesson completion, reset or quiz submission events fire. Padded/tagged completion values accepted by Sensei are also blocked.
- An invalid completion nonce does not change progress. A successful bridge status GET verifies that the cookie and REST nonce authenticate the enrolled learner before permission tests run.
- Student GET/POST of quiz definitions, DELETE of course progress and POST editing a lesson return 403 and leave progress unchanged.
- After H5P pass, native quiz submission is accepted, Sensei emits its submission/completion hooks, and SQL confirms the lesson's completed status. Positive-threshold lesson completion also works after pass; an unattempted zero-threshold lesson and a lesson without H5P remain completable under the existing policy.

The fixture leaves one course lesson unfinished when checking the quiz-submitted hook: Sensei's completed-course redirect can exit during the preceding lesson-completion hook. Mail is suppressed and all Docker services/volumes are removed by the runner. The hook recorder is mounted only in that disposable environment, never shipped with the plugin.

## Programmatic completion follow-up

The `sensei_user_lesson_end` fallback no longer calls `sensei_remove_user_from_lesson`. It reopens existing lesson and quiz progress through Sensei's repositories, retaining their row identities and original start dates. Quiz answer values, numeric grades and grading feedback are retained. Both progress records matter: with table-backed progress, a passed quiz can otherwise make `Sensei_Utils::user_completed_lesson()` return true even after the lesson is reopened.

The fallback returns normally in learner, REST/CLI and background contexts; it no longer redirects or exits the calling process. The native form guards still provide their early redirects. Exemptions continue to apply to the **learner being completed**, so an administrator grading an ordinary student does not silently exempt that student.

Unsupported repository APIs raise an explicit exception without using a destructive fallback. After saves, the bridge rereads the authoritative status and raises an exception if correction did not persist. This is not an atomic database transaction: failures may leave an attempted completion or partially corrected status, and the caller must treat the exception as a failed operation and retry after recovery. Learner work is never deleted as a recovery measure.

Modern Sensei completion mail checks the corrected lesson status. The legacy completion mail implementation only checks whether the lesson was started; a narrowly scoped `sensei_send_emails` filter now suppresses that legacy notification for the rejected learner/lesson during its completion action. Later legitimate completion clears that request-local rejection. Ordinary quiz-submission notifications are not completion notifications and retain their normal behavior.

**117 programmatic-completion assertions passed**: 35 with comments-only storage, 41 with dual writes/comment reads, and 41 with dual writes/table reads. The same final Docker run also passed the 68 HTTP/REST, 71 REST/database and 23 migration/storage assertions and the concurrent-delivery check, without unexpected PHP or database warnings.

`tests/wordpress-completion.php` uses a fresh WordPress process for each native Sensei configuration: comments-only, dual writes with comment reads, and dual writes with table reads. The fixture exercises real programmatic quiz grading, background completion, administrator manual grading/feedback, missing repository APIs, injected failed progress UPDATEs and retry after recovery. It also checks course status, preserved start dates/identities, completion notifications and the privileged-learner exemption. For the optional storage modes, the test enables Sensei’s table feature flag, creates its schema through its own installer class, and verifies the custom-table status rows and retained grade. It explicitly starts quiz progress as the native quiz view does. The test runs in a REST-marked CLI context to avoid Sensei's own browser course-completion redirect and intercepts the manual grading handler’s expected final redirect. It fails if the process exits before its final assertion.

A blocked programmatic completion leaves the lesson and quiz **in progress**, even though the numeric quiz grade remains available. Passing H5P later does not automatically complete or replay the prior Sensei operation. A subsequent native completion/grading operation owns completion. Existing H5P attempts are not rewritten and existing records are not swept or regraded.

## Block-based quiz follow-up

`tests/fixtures/block-quiz.php` creates two boolean questions through Sensei's lesson-quiz editor REST controller, then serializes the registered quiz, question, description, answer and lesson-action blocks. An ordinary `core/shortcode` block contains the H5P shortcode. Question IDs come from the API response. The fixture checks block registration and the integration test checks serialization round-trip, Sensei's block detection and bridge H5P discovery. This is a test fixture for Sensei's existing blocks, not the planned HSB-013 activity picker.

The `blocks` mode of `tests/wordpress-sensei.php` repeats the native HTTP/REST guards and preservation checks with this content. After H5P passes, it verifies the block renderer, one question per page, native page-change submission, final submission, completion, a 100% grade and both retained answers. It reads the `questions_asked[]` fields from rendered HTML: Sensei includes **all** quiz question IDs on each page, even when only one answer is submitted. Repeated hidden fields from a second navigation group are submitted as rendered; assertions compare the unique ID set. Saved-work fixtures also retain unanswered question IDs. Omitting those IDs incorrectly narrows Sensei's saved question set and does not represent its browser form.

On local FagKlar staging, the new fixture is lesson **112**, quiz **113**, questions **114/115**, course **105** and student **14** (`fkhs_block_quiz_fixture_20261102`). A database backup was taken before adding it; existing fixtures were retained. Chrome verified:

1. Direct quiz navigation before H5P pass redirects to the lesson with `fkhs_quiz_blocked=1`; both Take Quiz controls remain disabled.
2. The real H5P.TrueFalse 1.8 activity emits its result, stores 1/1, and releases the controls at the 70% threshold.
3. Take Quiz opens the first block question. Answering True and choosing Next Question saves it and displays only the second question.
4. Answering True and completing the quiz displays **Your Grade: 100%**, both correct answers and **4 of 5 lessons complete (80%)**. Reloading retains that result.

**86 block-quiz HTTP/REST assertions passed**, alongside 68 classic quiz assertions, 71 REST/database assertions, 23 migration/storage assertions, 117 programmatic-completion assertions and the concurrent-delivery check. The dependency-free suite still passes 470 PHP assertions and 17 Node tests.

The existing plugin guards passed this flow without additional production-code changes. The fixture is retained at `/lesson/hsb-blokkquiz-lokalt/`. This validates two boolean questions in Learning Mode with pagination; other question types, editor UI save/reopen, question-bank selection and alternative quiz templates remain unverified.

## Limits and next coverage

This remains an **after-write correction**, not a universal pre-write veto. The original completion action still fires; listeners before priority 1 and third-party listeners that ignore stored status may act on the attempted completion. Callers that write directly to repositories/SQL without emitting `sensei_user_lesson_end` are outside this fallback. Integrations should check the bridge requirement before invoking completion, and inspect persisted Sensei status afterwards rather than assuming a native method's return value proves completion.

Broader block-quiz variants, real REST controllers supplied by third parties, Sensei Pro extensions, prerequisite/video rules and additional H5P libraries remain open. Storage tests cover the three configurations on the pinned Sensei version, not migration between them or every partial dual-write failure. Broader teacher/admin UI coverage also remains open.

The [HSB-002 startup test](HSB_002_VALIDATION.md#bounded-h5p-startup-follow-up) used this lesson for scoped failure injection; that MU filter has been removed. Fixture content is retained for follow-up. No production deployment, release, commit or push was performed.
