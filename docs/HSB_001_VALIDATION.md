# HSB-001 — lesson access and attempt validation

Implementation branch: `fix/hsb-001-access-validation`, based on reconciled main `3417d3b`. HSB-001 itself has no schema change or attempt backfill. The subsequent [HSB-004 work](HSB_004_VALIDATION.md) adds an additive schema upgrade. Neither task includes production deployment or a release.

## Access contract

- Both REST handlers use the authenticated user; client actor/user IDs cannot select whose progress to read or write.
- Students need a published, accessible Sensei lesson belonging to a published course, actual course enrollment and a passing Sensei lesson-access check. Password-protected content must be unlocked. Preview lessons and disabled login requirements alone do not authorize bridge records.
- Administrators and users with `edit_post` for the specific lesson may preview, including drafts. A teacher role or grading capability by itself does not grant access to another teacher's lessons. Trashed/auto-draft lessons remain denied.
- Missing supported Sensei APIs or H5P runtime returns 503 rather than bypassing checks. Missing/inaccessible lessons share a 403 response; progress/H5P queries are not run for denied access.
- POST requires an explicit positive `contentId`, actual shortcode membership in the lesson and an existing H5P registry row. WordPress shortcode parsing excludes escaped literals and misleading attributes such as `data-id`.
- Validate the object paths, boolean flags, finite numeric values and score bounds consumed by the bridge. Malformed nested data returns 400 without insertion or PHP type errors. A nonempty `verb.id` is required; actor/object are not required for the existing minimal synthetic event.

The integration uses public Sensei interfaces: `Sensei()->lesson->get_course_id`, `Sensei_Course::is_user_enrolled` and `sensei_can_user_view_lesson`. Source references: [course enrollment](https://github.com/Automattic/sensei/blob/trunk/includes/class-sensei-course.php), [lesson access](https://github.com/Automattic/sensei/blob/trunk/includes/sensei-functions.php). The version pinned in the executable fixture is the tested contract; these upstream links may change.

## Compatibility changes

Previously any logged-in user with nonzero IDs could reach the write path. Unenrolled users, invalid lessons, unrelated/deleted content and malformed payloads now fail. Existing clients sending missing/null activity IDs, string booleans/numbers or statements without verbs must correct those requests. Missing activity mapping must not be silently guessed.

At HSB-001 completion, frontend identity fallback, completion inference and unchecked persistence remained open. The subsequent [HSB-004 implementation](HSB_004_VALIDATION.md) addresses these with strict mapping, actual completion signals and durable receipts/recovery. Server membership validation still cannot independently prove event provenance; scores remain client-provided formative evidence. Site-specific browser/library verification remains a rollout requirement.

## Reproducible checks

From the repository root:

```sh
find h5p-sensei-bridge tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/progress.php
php tests/rest.php
node --test tests/gate.test.cjs
bash tests/integration/run.sh
git diff --check
```

- `tests/progress.php`: shared threshold/history policy regressions, using WordPress/SQL doubles.
- `tests/rest.php`: access and malformed-payload matrix, including no inserts on rejection, scoped preview, missing dependencies, client identity/threshold overrides, and retained zero-threshold behavior. PHP warnings become test failures.
- `tests/wordpress-rest.php`: real WordPress REST dispatch and cookie nonce authentication, Sensei manual enrollment/withdrawal/access filters, teacher scope, real shortcode parsing, H5P registry rows and MariaDB attempt/status queries. Runs only with the disposable fixture's explicit test flag.
- `tests/integration/run.sh`: creates a unique Compose project, installs WordPress 7.1.4, Sensei 4.26.4 and H5P 1.17.10 on PHP 8.2/MariaDB 10.11.18, and cleans up only that project's resources. Email is disabled by a fixture-only MU plugin. Plugin and test source mounts are read-only. No production data or host ports are used.
- CI runs both the lightweight regressions and the disposable integration suite. A local pass does not establish that GitHub Actions ran successfully.

## Execution evidence — 2026-11-02

| Check | Environment | Result |
| --- | --- | --- |
| PHP syntax, shared progress policy | Host PHP 8.5.7 | Pass; 52 progress assertions |
| Access and malformed-payload matrix | Host PHP 8.5.7 | Pass; 373 assertions; no PHP warnings |
| Frontend regressions and JS syntax | Node 22.22.2 | Pass; 2 tests; both JS assets parse |
| REST, enrollment, nonce handling, teacher scope, user isolation, registry and SQL | WordPress 7.1.4 / PHP 8.2.32 / Sensei 4.26.4 / H5P 1.17.10 / MariaDB 10.11.18 | Pass; 71 assertions |
| Whitespace and shell syntax | `git diff --check`, `bash -n tests/integration/run.sh` | Pass |

The integration run exited successfully and removed its containers, network and volume. The final teacher fixture assigns a teacher-owned course as well as a lesson: Sensei derives the lesson author from the owning course. Tests of cookie nonce handling use WordPress's real authentication path with a simulated authenticated-cookie identity, not a browser login session. No GitHub Actions run was triggered by this local work.

## Site-specific staging still required

The owner authorizes the FagKlar local site at `http://localhost:8887/` as staging. Initially that address returned HTTP 404 and the old `/tmp/fagklar-runtime/wordpress` tree was incomplete. Later on 2026-11-02 the owner restored the server from `FagKlarTheme/.local/wordpress`; it returned HTTP 200. HSB-004 then installed H5P and the bridge there and ran a bounded browser smoke test; see [follow-up evidence](HSB_004_VALIDATION.md#fagklar-local-browser-smoke--2026-11-02). The disposable integration fixture remains separate.

Before rollout, verify the deployed package/SHA, exact production versions, Sensei Pro/access extensions, custom filters, real library-generated xAPI payloads, FagKlar Learning Mode and browser behavior. The fixture creates an H5P registry row without executing an H5P content library; it does not prove browser delivery, completion/quiz hook timing or production compatibility. HSB-001 is implemented with targeted tests, but broader staging verification remains open.
