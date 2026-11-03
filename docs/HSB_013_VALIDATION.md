# HSB-013 — H5P activity block and shared thresholds

Implemented on the stabilization branch, 2026-11-03. This is targeted authoring verification, not production rollout approval. Plugin version remains `1.2.0-dev`; no schema migration or attempt rewrite is introduced.

## Authoring and persistence

The `fkhs/activity` block is available in the Sensei lesson editor. Search the existing registry by title or numeric ID, select an activity, and optionally set its threshold before saving. Results show title, ID and library/version, with 20 results per page, loading/empty/error states and retry. The block stores only `contentId`; its saved markup contains the literal `[h5p id="ID"]` shortcode. Normal WordPress rendering and existing bridge discovery therefore use the same content.

Block controls and the existing lesson metabox edit `_fkhs_threshold_map` through the native `core/editor` store. The overview also discovers manual shortcodes and nested blocks in unsaved content. Inherit removes the new override and follows legacy activity → lesson → 70; the effective preview uses existing runtime filters for the editor account without persisting their result. Explicit zero remains zero and retains the agreed optional-before-first-attempt/actual-completion contract. Inputs outside 0–100 disable save/autosave until corrected.

Every occurrence of an activity in one lesson shares one override. Replacement uses the new activity's existing lesson override or inherited value. Copy/paste carries the ID only, so the destination lesson owns its threshold. Removal leaves unused overrides and attempts intact; reintroducing an activity reuses its prior lesson override. No automatic cleanup or historical regrading runs.

WordPress owns normal saves, undo/redo, autosaves and revision restoration. The metadata is revision-enabled. Core's author-draft autosave writes directly through `wp_update_post`, so the bridge includes validated metadata in that write. Autosaves are validated before the controller and checked afterward for successful metadata persistence: core does not consistently propagate preparation/meta-write failures in these paths. A failed native save can have written post content before metadata fails; it is reported as an error and the pending values can be saved again. This is not a cross-table transaction guarantee.

Gutenberg's legacy metabox POST never writes the threshold map. Classic editing remains supported: blank means inherit, numeric overrides are validated, omitted form data leaves the map alone, and a snapshot hash rejects stale threshold submissions with a visible notice. Activity titles/types remain visible. Native WordPress post locking governs concurrent Gutenberg sessions; there is no custom multi-author merge protocol.

## Registry and save contract

`GET /wp-json/fkhs/v1/activities` uses normal WordPress cookie/REST-nonce authentication. It requires edit access to the specific `lesson_id` and H5P's `view_h5p_contents` capability. As in H5P's own picker, results are owner-scoped unless `view_others_h5p_contents` is granted. Only ID, title and library/type are returned; raw H5P parameters and xAPI statements are excluded.

Parameters: required positive `lesson_id`; optional `search` (maximum 100 characters), `page` (1–1000), or positive `content_id` for a selected activity. A selected lookup accepts an optional pending `threshold` (0–100); omitting it previews inheritance. Responses contain `items`, `has_more` and `page`, with private/no-store caching. Missing H5P or failed registry reads return 503; denied lesson/registry access returns 403. Unknown or inaccessible IDs produce an empty result.

Native lesson REST saves reject invalid numeric maps, new inaccessible activities, IDs outside the candidate content, and mismatched block-ID/shortcode markup before post mutation. Unchanged legacy/orphan overrides and already-present unavailable activities are preserved so unrelated editing remains possible. Changed overrides require current registry access. The map accepts at most 500 entries. These checks protect this authoring path; existing manual shortcode behavior is retained.

Synced patterns containing activity blocks are explicitly unsupported: block reuse is disabled and lesson REST saves reject direct or nested references containing these blocks. Detach the pattern first. Ordinary inline groups and other nested layouts are supported. This does not add discovery for historical manual shortcodes hidden inside synced patterns. Other post types do not gain lesson gating by copying the block.

## Automated verification

`tests/wordpress-editor.php` runs against the installed ZIP with real WordPress, Sensei, H5P registry tables and MariaDB. It covers scoped search, bounded pagination, title/ID queries, literal wildcard handling, unauthorized reads/writes, empty pickers, first creation, copy semantics, nested/repeated/manual shortcode discovery, normal block/shortcode rendering, zero/inheritance/filter behavior, revision restore, classic stale/missing fields, removed content, missing dependencies, database failures/retry, nested pattern rejection, published autosaves and direct author-draft autosaves. It also verifies an enrolled learner's REST attempt, status and Sensei guard for block content, and checks that authoring does not rewrite existing attempts.

The native renderer test uses a shortcode spy to isolate WordPress serialization; real H5P rendering is separately covered in the local browser checks below. `tests/wordpress-i18n.php` now verifies editor strings in English/Bokmål and content-hash cache invalidation for the editor script, alongside frontend/report assets. The package builder requires both new editor runtime files.

Local WordPress 7.1.4/PHP 8.2.32, Sensei 4.26.4, H5P 1.17.10 and MariaDB 10.11.18 passed 65 editor assertions, 26 locale/enqueue assertions and all existing integration suites: 909 assertions in total, plus native ZIP upload, concurrent delivery and the report/progress benchmark. The fixture services and volume were removed on exit. The 477 dependency-free PHP assertions, 25 Node tests and seven package tests also passed; PHP/JavaScript syntax, gettext and whitespace checks passed.

Branch CI runs eight jobs: four PHP regression versions, three complete integration profiles and package validation. The exact remote run/result is linked in the delivery summary; local results alone do not certify the other profiles.

## Local browser evidence

Using the authorized FagKlar site at `http://localhost:8887/`, a dedicated test teacher and draft lessons 121/122:

- Inserted the block through Gutenberg, selected real `H5P.TrueFalse 1.8` activity 1, and edited zero/positive thresholds in both block and metabox before saving. Both locations immediately agreed.
- An out-of-range value (101) showed validation and disabled save/publish; correction restored saving. Saved zero survived a reload with valid block markup.
- Duplicated a block: both occurrences changed together. Native Undo/Redo restored both values. Replacing one with fixture activity 2 used inherited 70 while activity 1 retained 85. Save/reload preserved both.
- Used the native clipboard to copy activity 1 into lesson 122: it inherited 70, independently of the source's 85.
- Injected one metadata-write failure restricted to test user 17 and draft 122. Gutenberg displayed the native update error and retained the pending 80. Retrying saved 80, confirmed after reload and by reading metadata. The temporary failure helper was removed.
- Connected the test draft to test course 131 for actual H5P preview. The True/False question rendered, a correct answer produced 1/1, and bridge feedback showed the saved pass and satisfied requirements. This was a teacher preview; enrolled-student authorization and Sensei enforcement are covered by the real integration suite.
- Reviewed English and Bokmål controls, keyboard activation of a search result, and the empty search state. Core editor language may remain English when its language pack is absent; plugin strings are translated independently.

Only dedicated fixtures were changed; no existing learner attempts were rewritten. Test-user sessions are expired after verification. Fixture drafts remain available for maintainer inspection.

## Remaining rollout limits

The site-specific release gate from HSB-010 still applies. Wider screen-reader/mobile/browser coverage, additional deployed H5P types, large-registry search latency, custom editor restrictions and third-party revision integrations remain rollout checks. This implementation does not create/upload H5P, provide independent thresholds per occurrence, support synced activity patterns or publish an external progress API. HSB-011 remains the next planned product task.
