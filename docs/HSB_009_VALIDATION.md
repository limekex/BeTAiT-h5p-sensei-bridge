# HSB-009 — Personal data and lifecycle

Implemented 2026-11-03 after HSB-008 (`b1cb4d2`). The candidate adds bounded personal-data export/erasure and explicit raw-statement cleanup. The default remains retention: no automatic expiry, scheduled deletion, account-deletion cleanup, table drop or historical regrading is enabled. All destructive validation uses disposable Docker fixtures. The shared localhost site received only a read query and a cleanup dry run.

## Stored data and retention

| Data | Purpose and behavior |
| --- | --- |
| Attempt/user/lesson/activity IDs and lesson-title snapshot | Associate the authenticated learner's attempt with its lesson/activity; retained until explicit personal-data erasure |
| Raw/max score, stored pass/completion flags and stored site time | Preserve existing history and progress policy; raw-statement cleanup does not change these |
| Event UUID and SHA-256 payload hash | Duplicate delivery/conflict detection; remain after raw-statement cleanup |
| Canonical submitted xAPI JSON | Retained by default; can contain actor names/email, answers, object/context/extensions and other client-supplied data; not a validated LRS record |
| Lesson thresholds and schema marker | Remain on deactivation/uninstall; no automatic legacy metadata cleanup |

The authenticated WordPress user ID, not an xAPI actor string, owns the row. Reports and student status do not return the raw statement. A payload hash is still linked to an identified attempt; clearing raw JSON is not anonymization or complete personal-data erasure.

Sites can disable **future** raw-statement storage in site-owned code:

```php
add_filter( 'fkhs_store_raw_statement', '__return_false' );
```

The filter receives `(retain, user_id, lesson_id, content_id)` and only an explicit boolean `false` omits raw storage. Original canonical JSON still drives scoring, completion evaluation and the payload hash. Receipt/deduplication and changed-payload conflicts therefore survive both opt-out and later cleanup. Existing raw statements remain until separately reviewed cleanup. Old plugin files ignore this new filter; file rollback may resume raw storage.

Suggested technical text is registered in WordPress's privacy-policy guide. It describes the default behavior and the operator's responsibility to choose retention periods and coordinate other stores. It is not a site-specific policy or a claim that this plugin erases data held by Sensei, H5P's own result tables, backups or external services.

## WordPress personal-data tools

The plugin registers `h5p-sensei-bridge` with WordPress's [personal-data exporters](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/) and [erasers](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/). Use the existing Tools → Export/Erase Personal Data workflow. WordPress owns the request, nonce, verification/administrator override and generated files. There is no new public REST route.

Callbacks also check the core capabilities themselves: `export_others_personal_data`, or `erase_others_personal_data` plus `delete_users`. Teacher report access does not grant privacy-tool access. The request email must match an existing WordPress account; the account's ID scopes every read/delete. Unknown emails return no matching account data. Account email changes, deleted accounts/orphaned rows and arbitrary other addresses inside raw statements are not matched by scanning JSON. Resolve such cases manually and run the account's privacy request before account deletion where possible.

### Export

- Ascending attempt ID, 20 attempts per callback, then WordPress requests the next page. Unique `fkhs-attempt-ID` item keys prevent merging different attempts.
- Includes all stored attempt fields: IDs, snapshot title, scores, flags, time, event UUID, payload hash and raw statement when retained. SQL NULL is labelled “Not stored”. No current runtime threshold is invented as historical evidence.
- Raw statements are limited to **256 KiB each** for automatic export. SQL checks their length before returning the raw value. A page therefore fetches at most 20 × 256 KiB raw JSON, before HTML escaping/WordPress aggregation overhead.
- An oversized historical statement stops the export with an explicit error requiring a complete manual export. It is never silently truncated, omitted or reported as a complete automatic export. The limit does not delete data or change event ingestion limits.
- Values are HTML-escaped because WordPress permits HTML in export fields. Literal answer markup remains readable rather than becoming active content; JSON inside the WordPress ZIP contains those escaped display values.
- Failed subject/table reads produce `WP_Error`, not an empty successful export. Page inputs are validated and bounded to 1–1,000,000.

This bounds each plugin callback, not the total size of a user's export. WordPress accumulates pages and generates the ZIP; extremely large histories still need an operator-managed export. Offset pagination is not a frozen snapshot. Coordinate concurrent writes/erasure while preparing a complete export. WordPress owns retention/removal of its export files; clearing attempts does not revoke previously downloaded or independently retained copies.

### Erasure

By default the eraser reports `items_retained=true`, `items_removed=false`, `done=true` and an explanatory message when matching attempts exist. The standard WordPress request does not silently override the plugin's retention default.

After deciding the site's erasure policy, site-owned code can opt in for a specific reviewed account:

```php
add_filter( 'fkhs_allow_personal_data_erasure', function ( $allowed, $user_id ) {
    // Replace this example ID with the specifically approved account.
    return 123 === $user_id;
}, 10, 2 );
```

This only enables the bridge's eraser; it does not bypass WordPress's normal permission/request workflow. Do not deploy the example with an arbitrary ID or enable all accounts accidentally. A boolean `true` is required.

Each invocation deletes at most 100 complete attempt rows for that user, including raw JSON, receipts and hashes. It always selects the first remaining IDs: applying page offsets after deletion would skip rows. Repeating a callback is safe; an exact multiple of 100 requires one final empty callback. Other users and all lesson settings are preserved. Failed reads/deletes stop with an error rather than a successful completion response.

Full erasure is intentionally different from raw cleanup: it can change the bridge's positive-threshold requirement to unmet and returns zero-threshold activities to the existing optional-before-attempt state. It does not reset Sensei's own completed lessons, quizzes, enrollment or grades. Active users/queued clients can submit new attempts after erasure; coordinate access/session handling and other stores as part of the site's process. These tools do not claim to erase concurrently arriving future activity.

## Explicit raw-data cleanup

`wp fkhs cleanup-statements` is an administrator-only WP-CLI command. It is **read-only by default**, with no cron job or automatic loop. Each invocation selects at most 200 eligible rows plus one look-ahead ID. Dates use the stored site time and an exclusive midnight cutoff.

Example workflow; replace the date and IDs with values from your reviewed preview:

```sh
wp --user=<administrator> fkhs cleanup-statements --before=2025-01-01 --batch-size=100
# Inspect the JSON. Suppose through_id is 12345; the initial after_id is 0.
wp --user=<administrator> fkhs cleanup-statements --before=2025-01-01 --through-id=12345 --after-id=0 --batch-size=100 --apply
```

The output contains `dry_run`, `before`, `through_id`, `after_id`, `matched`, `changed`, `next_after_id` and `done`. `matched` counts the bounded batch, not all site records. No statements or learner identifiers are printed.

- Applying requires the explicit reviewed `through_id`; it is not inferred on a destructive call. New attempts with larger IDs are excluded, even if their stored dates are old. This is a numeric high-water boundary, not a signed authorization token; trusted operators still must review the date/scope.
- **Apply the preview using its original `after_id`.** The preview's `next_after_id` is not a reason to skip that unprocessed batch. Only after a successful apply use the returned `next_after_id` to process the next batch with the same cutoff and through-ID.
- `--no-apply` remains a preview. Supplying a text value such as `--apply=false` cannot enable deletion: it is rejected or treated as false by the CLI parser.
- Cleanup only sets `statement=NULL`. Attempts, scores, historical flags, timestamps, event UUIDs and hashes remain byte-for-byte unchanged. Retries do not recreate removed raw JSON for an already acknowledged UUID.
- Repeating an applied batch is safe. No rollback payload is kept inside the plugin. Restore cleared raw data only from an independently retained, appropriately handled backup.
- Select the site explicitly with WP-CLI `--url` on multisite. The command only uses the active prefix; it never traverses the network automatically. A real multisite migration/privacy matrix remains unverified.

The CLI uses the same bounded service tested directly, and [WP-CLI's command API](https://make.wordpress.org/cli/handbook/references/internal-api/wp-cli-runcommand/) is used for subprocess integration tests.

## Upgrade, uninstall and rollback

Schema remains **0.3.0**. HSB-004's additive event identity migration remains the only schema change; no privacy table, new index, destructive schema downgrade or version/release bump is introduced here.

`uninstall.php` is now the single guarded uninstall entry point. The duplicate empty registered callback was removed. Native uninstall, deactivation and reactivation preserve attempts, schema metadata and lesson thresholds. Deleting a WordPress account does not trigger plugin-owned cleanup; preserved orphaned rows require separate handling. WordPress's own deletion of content/user metadata is outside this plugin's retention hook.

Before deploying or rolling back files:

1. Record the deployed plugin/dependency versions and schema marker; preserve a database/files backup and define which system owns each progress/identity record.
2. On an isolated copy containing attempts, run the additive upgrade; compare all pre-existing values and test status reads, duplicate retries and old/new insert formats.
3. If files must be rolled back, leave the additive schema intact. Older inserts may omit the nullable identity columns. Do not drop columns/indexes or rewrite the schema marker to imitate a downgrade.
4. Raw-statement cleanup/approved erasure is not undone by rolling back files. Earlier code can resume full raw storage and can lack newer access/durability protections. Revalidate the selected rollback package before deployment.

Tests cover the populated additive upgrade/failure/retry from HSB-004, actual deactivation/uninstall/reactivation, unchanged full-row snapshots, retained thresholds, a pre-identity insert after the lifecycle cycle and a subsequent upgrade. This is a database compatibility contract, not an installed historical-release ZIP end-to-end certification; packaging/production rollback remains HSB-010's gate.

## Evidence and remaining scope

Local automated checks use disposable WordPress 7.1.4/PHP 8.2.32, Sensei 4.26.4, H5P 1.17.10 and MariaDB 10.11.18:

- Existing 477 PHP assertions and 25 JavaScript tests pass; syntax and gettext catalog checks pass.
- All 109 privacy/lifecycle assertions pass, bringing the full integration suite to 805 assertions plus concurrent-delivery verification and the report/progress benchmark. Coverage includes cross-user/capability separation, all 201 export rows, generated WordPress ZIP HTML/JSON, oversized payload refusal, default retention, bounded deletion without skipped rows, actual admin-Ajax callback/nonce handling, raw opt-out/cleanup and duplicate conflicts, high-water cutoff, failed reads/writes, populated uninstall/reactivation and legacy inserts.
- Real CLI subprocesses exercise preview, explicit false/negated apply, authorized apply/resume, permission failures, missing high-water refusal and preservation of every other field/row.
- English/Bokmål exporter labels are tested in WordPress. Existing 12 translation checks plus 2 privacy checks run in all three integration profiles.
- Mail is intercepted by the Docker fixture. Export files and fixture databases are removed; no real email, production cleanup or production personal-data erasure was performed.
- A read-only localhost:8887 check confirms the installed SQLite adapter accepts the guarded raw-export SQL and registers the exporter. A one-row cleanup dry run matched zero eligible raw statements and changed nothing. No new shared-staging accounts/data were created for HSB-009.

The runner includes privacy/lifecycle tests in all three CI profiles. GitHub evidence must refer to the new pushed revision; the prior [HSB-008 seven-job pass](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41670061205) covers `b1cb4d2`, not these changes.

Remaining site decisions/validation: actual retention duration, when to enable raw opt-out/erasure, deleted/changed-email identities, coordination with Sensei/H5P/backups/external systems, large-history export beyond WordPress's aggregate memory budget, concurrent writes and multisite. No broader legal-compliance or complete external-store erasure claim is made.
