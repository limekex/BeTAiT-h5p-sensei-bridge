# HSB-006 — CI and compatibility

Updated 2026-11-03. The local candidate was subsequently committed as `2479a26`; its seven GitHub jobs now pass. The matrix does not imply that all versions between the sampled combinations are supported.

## CI changes

The [workflow](../.github/workflows/checks.yml) now has independent PHP regression jobs for **8.0, 8.2, 8.4 and 8.5**, using the shared [PHP check script](../tests/php-checks.sh). Each job also runs the 17 Node 22 regressions and JavaScript syntax checks. PHP 8.0 checks the currently declared language floor; it is not a deployment recommendation. PHP's maintained branches are listed in the [official support table](https://www.php.net/supported-versions.php).

Three full integration profiles run separately with `fail-fast: false`. Pull requests, pushes to main/develop/review/fix/feature branches and manual dispatch are covered. Ubuntu 24.04 is explicit to avoid the moving `ubuntu-latest` label. Regression and integration jobs have 10- and 20-minute timeouts respectively. The matrix follows [GitHub's documented job strategy](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/run-job-variations).

Integration logs, CLI/HTTP runtime versions, Docker image identities and service diagnostics are retained as artifacts for 14 days, including on failure. The runner captures diagnostics before removing its own services and volumes, preserves failing exit codes, and handles normal exit, INT and TERM. A hard kill or Docker daemon failure can still prevent cleanup. Images remain tag-pinned rather than digest-pinned; runtime patch versions and resolved digests are evidence for the specific run, not a guarantee that future pulls are identical.

## Local matrix

All full profiles use **Sensei LMS 4.26.4**, **H5P 1.17.10**, **MariaDB 10.11.18** and WP-CLI 2.12.0. These are deliberately pinned, not dynamically resolved as “latest”. The installed Sensei package declares WordPress 6.9 as its minimum; the lower WordPress profile tests that boundary with the same Sensei version.

| Profile | WordPress | PHP | Result |
| --- | --- | --- | --- |
| `wp69-php82` | 6.9 | 8.2.32 CLI/HTTP | All suites passed |
| `wp71-php82` (default) | 7.1.4 | 8.2.32 CLI/HTTP | All suites passed |
| `wp71-php84` | 7.1.4 | 8.4.27 CLI / 8.4.23 HTTP | All suites passed |

All three local profiles passed **365 assertions each**, plus concurrent delivery and runtime verification. Each full profile runs 71 REST/database assertions, 23 migration/storage assertions, 154 Sensei HTTP/REST assertions (68 classic, 86 block quiz), 117 programmatic-completion assertions across three Sensei storage modes, and the concurrent-delivery check. These are the same behavioral suites used in HSB-001/004/005, not reduced compatibility smoke tests.

The first WordPress 6.9 run returned 404 for the quiz URL before reaching the bridge. Rebuilding rewrite rules in a fresh WP-CLI process after all plugins are activated restores the registered Sensei lesson/quiz routes. The runner now does this explicitly for every profile instead of relying on activation-time rewrite state.

The Apache image provides PHP and extensions. Before installation, the runner replaces WordPress core with the selected version and verifies its official checksums; only the image's known `wp-config-docker.php` helper is excluded. A test-only MU REST probe verifies the actual WordPress, PHP minor, Sensei, H5P and database versions in **both CLI and Apache** before any fixture tests. A mismatched runtime fails the profile. PHP minor versions must match the profile; the official CLI and Apache images can contain different patch versions, which are recorded above. The probe is mounted only inside the isolated test site, requires `FKHS_INTEGRATION_TESTS`, and is not shipped with the plugin. Compose publishes no host ports and mounts plugin/test code read-only.

| Dependency-free PHP checks | Result |
| --- | --- |
| 8.0.30 | 470 assertions and all PHP syntax checks passed |
| 8.2.32 | 470 assertions and all PHP syntax checks passed |
| 8.4.23 | 470 assertions and all PHP syntax checks passed |
| 8.5.12 | 470 assertions and all PHP syntax checks passed |

The first PHP 8.0 run exposed a test-harness compatibility bug: invoking a private method through reflection needs `setAccessible(true)` before PHP 8.1. The harness now calls it only on PHP 8.0, avoiding the deprecated call on PHP 8.5. The corrected 8.0 and 8.5 runs pass without changing plugin behavior.

Node 22: **17 tests passed**. JavaScript/shell syntax, `git diff --check` and actionlint 1.7.7 validation of the workflow passed. Local Docker execution uses the host architecture; GitHub's Ubuntu jobs subsequently passed on `2479a26`.

## Reproduce

```sh
bash tests/php-checks.sh
npm ci --ignore-scripts
npm test
bash tests/integration/run.sh wp69-php82
bash tests/integration/run.sh wp71-php82
bash tests/integration/run.sh wp71-php84
```

Running without a profile keeps the prior WordPress 7.1.4/PHP 8.2 default. Unknown profile names fail before Docker is called. Local diagnostics are written under ignored `artifacts/integration/<profile>/`; redirect or tee the command output to retain the complete CLI transcript as CI does. Use different profiles for parallel runs; simultaneous runs of the same profile share a diagnostics directory even though their Docker projects are isolated.

## GitHub status and remaining limits

Updated 2026-11-03 after the owner resolved billing: the [HSB-006 GitHub run](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41545955184) on `2479a260b6f220d12c4340512ab88a7a33ddc348` passed all seven jobs (four PHP regressions and three full integration profiles), including actual test steps and artifact upload. The formerly blocked [main rerun](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41360546477) on `3417d3b99f9285f2515564aa83828e3a2138b636` was rerun and passed. These results apply to those revisions; newer changes require their own checks. The historical billing failure is resolved, while the broader staging/release limits remain.

The plugin header still declares WordPress 6.0/PHP 8.0, and older documentation claimed Sensei 4.0+. Full integration at those historical minima is **not verified**. In particular, the current Sensei package cannot establish WordPress 6.0 support, and the repository-based progress fallback depends on newer Sensei APIs. Header/release support claims must be reconciled in HSB-010 or backed by additional fixtures before release. No plugin version or minimum metadata is changed here.

PHP 8.0 and 8.5 only have dependency-free checks in this matrix. The earlier PHP 8.5 local FagKlar browser test is separate evidence, not a full MariaDB compatibility run. PHP 8.1/8.3, older Sensei/H5P versions, MySQL, different operating systems/architectures, third-party/Pro integration and broader browser/library/accessibility combinations remain outside this matrix. This work does not alter existing attempts or the authorized local staging site.
