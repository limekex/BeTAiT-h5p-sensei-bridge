# HSB-010 — Package identity, lifecycle and release operations

Implemented 2026-11-03 after HSB-009 (`a64a6cd`). This is preparation and disposable validation of an **unreleased `1.2.0-dev` candidate**, not a tag, public release or production deployment. The broader site-specific staging gate remains open.

## Identity and licensing

| Field | Candidate contract |
| --- | --- |
| Plugin slug / ZIP root | `h5p-sensei-bridge/` |
| Header / `FKHS_VER` / packaged readme / POT, PO and compiled MO | `1.2.0-dev` |
| Database schema | `0.3.0`, independent of plugin version; no new migration in HSB-010 |
| Installation minimum | WordPress 6.9 / PHP 8.2, matching the lowest full integration profile |
| Required plugins | `sensei-lms`, `h5p` via WordPress dependency metadata |
| Tested plugin versions | Sensei LMS 4.26.4 and H5P 1.17.10 |
| License | GPLv2 or later (`GPL-2.0-or-later`), expressly selected by the owner on 2026-11-03 |
| Update identity | Repository URL in `Update URI`; this is not an automatic GitHub update client |

The historical GitHub `v1.0.0` release points to `1188906907ba378e004f57741c0cd4dc6c02e434` and its source header says `0.1.0`. Its plugin lived at the repository root. The develop baseline `830975c6fa00049708d5549f438b1fabc9822259` moved the plugin into a nested directory, still declares `0.1.0`, and has a changelog entry claiming `1.1.0`. These historical records are preserved. `1.2.0-dev` identifies the new candidate without pretending it has been released, and compares above `1.1.0` under PHP version comparison.

The earlier README MIT claim is replaced by the owner's GPLv2-or-later decision, with the official [GNU GPLv2 text](../h5p-sensei-bridge/LICENSE) shipped in the plugin. No premium/payment feature is added. The [WordPress header contract](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) defines dependency and update metadata. The header minimum is a supported installation boundary, not a claim that every later dependency combination has been tested. PHP 8.0/8.5 dependency-free regression coverage remains useful but is distinct from full WordPress integration.

## Package contract and reproduction

From a checkout with Python 3, Git, PHP and Node available:

```sh
python3 tests/package_test.py
python3 scripts/build-plugin.py
bash tests/php-checks.sh
npm ci --ignore-scripts
npm test
bash tests/integration/run.sh wp71-php82
```

The build emits `dist/h5p-sensei-bridge-1.2.0-dev.zip`, `.zip.sha256` and `.manifest.json`. It uses only allowlisted, tracked files from the nested plugin directory: runtime PHP/JS, translations, packaged readme and license. There is one plugin root, with no tests, Git metadata, Node dependencies, repository documentation or local secrets. Unexpected tracked entries and symlinks fail the build; missing required files and inconsistent metadata fail before packaging. Untracked files are not included. Add intended runtime files to Git before building.

Entries are sorted and use fixed timestamps and permissions, so repeated builds of the same bytes with the same toolchain produce the same ZIP. Frontend/report script URLs use plugin version plus a content hash instead of file mtime: two packages with changed JavaScript and identical timestamps still invalidate the browser cache. An installed-file test modifies/restores each script with its original mtime to verify this behavior. The manifest records Git revision, dirty-state flag, archive SHA-256 and per-file hashes. A dirty developer build is explicitly marked; release mode requires a clean checkout. The checksum detects changes but is not a digital signature. ZIP compression implementations may differ between platforms; compare the supplied checksum for the specific artifact.

Seven package tests cover repeatable output, single-root/file-byte correctness, excluding untracked files, rejecting unexpected tracked files and symlinks, missing translations, mismatched versions/tags, stale compiled catalog metadata, and release-mode clean-tree/dated-changelog requirements. The builder also requires the repository and packaged license texts to match.

## Actual installation, upgrade and recovery

Every integration profile now builds and installs the ZIP into a disposable WordPress volume. The installed plugin is no longer a read-only bind mount of source. Tests/packages remain read-only mounts; no host ports or production data are exposed. The fixture intercepts all mail.

The package sequence performs:

1. Authenticated multipart upload to the real `/wp-admin/update.php?action=upload-plugin` endpoint using WordPress cookies and nonce. Every installed file is compared byte-for-byte with the ZIP; then WordPress activates the plugin and creates the fresh schema.
2. Native uninstall of that clean fixture. Only the proven-empty disposable attempts table is reset by test code, so historical activation can construct schema `0.2.0` itself. This test reset is not shipped or run on shared staging.
3. Install the immutable develop baseline `830975c6fa00049708d5549f438b1fabc9822259`, repackaged without code modifications for this test. Its real REST endpoint writes a scored attempt; threshold metadata and the full row are snapshotted.
4. Deactivate old files, install/activate the candidate through WordPress's upgrader, verify every historical value and threshold, and save an identified attempt under the new REST endpoint.
5. Export a real MariaDB SQL backup of this synthetic database. Replace files with the historical package and reactivate. Verify retained rows/columns/thresholds and submit a real legacy REST attempt against the additive schema.
6. Reinstall the candidate, verify the rollback-period attempt and original UUID receipt survive, then deliberately change a synthetic score. Import the pre-rollback SQL backup, flush caches and verify exact row, metadata and schema-marker restoration plus working deduplication.
7. Remove only package fixtures and run the existing full REST, durability, concurrency, Sensei, report, privacy and locale suites against the installed ZIP. HSB-009 also exercises populated native uninstall/reactivation with retention.

The sequence has 27 package assertions plus native HTTP upload/file comparison. File replacement uses WordPress/WP-CLI's installer; the initial upload exercises the actual admin HTTP endpoint. It does not claim a manual visual review of every upgrader screen. The historical ZIP is a **test reconstruction of pinned source**, not a previously published asset or approved rollback target. Both candidate and historical package hashes are retained in integration evidence.

**Observed rollback boundary:** historical activation retains the additive identity columns/index and current row data, but rewrites `fkhs_db_ver` to its own `0.2.0`. Its REST writer accepts the new schema while ignoring the UUID, creating an attempt with NULL identity. Reinstalling/activating the candidate safely rechecks schema `0.3.0` and preserves the original receipt. Old code also restores older access/completion behavior and ignores new raw-storage opt-out filters. Do not select this old code for production solely because the storage test passed.

## Operator procedure

Before upgrading a real site:

1. Record deployed commit/package checksum, active WordPress/PHP/Sensei/H5P versions, custom hooks, thresholds and `fkhs_db_ver`. Identify the verified recovery package; do not rely on the conflicting old display version alone.
2. Suspend learner writes and background processing. Back up the database and current plugin files together, store them privately, and test restoration on an isolated copy. Keep a baseline of attempt counts and representative full-row/threshold comparisons.
3. Install the reviewed ZIP on that copy and activate it. Check schema `0.3.0`, prior attempt preservation, positive/zero thresholds, authorized and denied status/events, retry deduplication, reports and native Sensei completion. Include the site's actual H5P libraries and custom integrations.
4. After owner approval, install that exact package/checksum during the controlled production window. Verify the same critical checks before resuming learners. Keep logs and the rollback package/backup available.

For rollback, keep writes suspended. Choose between restoring previously verified files while retaining the additive database, or restoring the matching database/files backup. A full database restore discards writes since the backup; account for them before choosing it. Do not manually drop new columns/indexes, rewrite attempts or fake a schema downgrade. Check the actual marker after old activation and explicitly revalidate migration before returning to current files. File rollback cannot recover raw JSON or attempts deliberately erased under HSB-009. Reopen traffic only after identity, history, thresholds and Sensei behavior are verified.

This fixture proves a MariaDB backup/restore and file-cycle path. It does not certify SQLite production restoration, multisite, alternative historical packages, live traffic during migration or an unsanitized copy of the deployed site. No package replacements, test fixtures or database restores were run against shared `localhost:8887` or production; this test sequence targets disposable Docker services only.

## Verification evidence

Local WordPress 7.1.4/PHP 8.2.32, Sensei 4.26.4, H5P 1.17.10 and MariaDB 10.11.18 passed all 27 package assertions, native authenticated ZIP upload/file comparison, 809 other integration assertions (including four new same-mtime script-cache checks), concurrent-delivery verification and the report/progress benchmark. The fixture services and database volume were removed on exit.

The 477 PHP assertions, 25 Node tests, seven Python packaging tests, gettext checks, shell/PHP syntax and actionlint workflow validation passed locally. All eight GitHub jobs passed for `39d889e`: [HSB-010 package and integration run](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41678542350).

## Release automation and remaining gate

Branch/PR CI has eight jobs: four PHP regression versions, three complete package/integration profiles and one package-boundary/artifact job. The tag workflow [reuses the checks](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows); its drafting job depends on their success. It validates tag = `v` + header version, aligned metadata, a dated changelog entry and clean checkout. `-dev` cannot release. The newly built ZIP must match the tested package artifact byte-for-byte. The workflow has read permission by default and grants `contents: write` only to the draft job. No broad repository-root ZIP or unrelated `betait-spfy` artifact remains.

After the staging gate is satisfied and a release is explicitly approved, update all version fields/catalogs and the changelog, commit, rerun the checks, then create an annotated version tag on that exact revision. Tag automation prepares a **draft release** with ZIP, checksum and manifest. A maintainer reviews test evidence, package and notes before manually publishing. No tag, draft, public release, merge or deployment was performed in this task; remote draft-publication behavior therefore remains a future release-time verification.

Remaining release decisions/checks:

- Site-specific staging acceptance from HSB-001–009: actual deployed libraries/extensions, broader accessibility/quiz variants and policy/performance limits remain as recorded in their validation documents.
- Enable and verify a public private-reporting route or publish a monitored security email. GitHub private vulnerability reporting was disabled on 2026-11-03; SECURITY.md no longer advertises a placeholder address or an uncommitted 72-hour SLA.
- Choose final release version/date and support/backport policy. A green candidate build is not approval to publish or deploy.
- Directory-specific WordPress.org review, premium architecture and automatic updates are separate future work.

The next planned implementation is HSB-013, the Gutenberg activity picker and shared threshold editor. Its isolated implementation can proceed; rollout still depends on the release/staging gate.
