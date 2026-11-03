=== BeTAiT H5P → Sensei Bridge ===
Contributors: limekex
Tags: h5p, sensei, learning
Requires at least: 6.9
Tested up to: 7.1.4
Requires PHP: 8.2
Stable tag: 1.2.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track H5P attempts and enforce activity requirements in Sensei lessons.

== Description ==

Unreleased development candidate. This package is for isolated validation, not an approved production release.

Requires active Sensei LMS and Interactive Content – H5P. Tested integration versions: Sensei LMS 4.26.4 and H5P 1.17.10; WordPress 6.9/PHP 8.2 and WordPress 7.1.4/PHP 8.2 or 8.4. Other combinations are not certified.

Positive thresholds require a passing result. A zero threshold is optional before the first stored attempt, then requires an actual completion signal. Opening an activity does not manufacture completion. Existing shortcodes and lesson threshold metadata remain supported.

In Sensei lesson editing, insert the H5P activity block to search existing activities and place their shortcode. Set a threshold immediately or inherit it; the block and lesson metabox share the same values before saving. Repeated activities share one threshold per lesson. Detach synced patterns containing activity blocks before use.

Place H5P course progress in a course or course template, or use [fkhs_course_progress], to show the learner’s own H5P requirements across accessible lessons. The view is hidden outside courses and in courses without H5P. It supports up to 200 published lessons and 500 current activity requirements. Sensei still controls course completion.

Attempts and raw statements are retained by default, including after uninstall. WordPress privacy tools export attempts; complete erasure requires explicit site policy opt-in. Administrator CLI cleanup previews raw-statement removal by default and preserves scores and receipts.

== Installation ==

1. Back up the database and plugin files; validate the package on an isolated copy.
2. Install and activate Sensei LMS and Interactive Content – H5P.
3. Upload the h5p-sensei-bridge-VERSION.zip in Plugins → Add New → Upload Plugin and activate it.
4. On upgrade, verify schema 0.3.0 and retained attempts before allowing learners back in.

Full requirements, known limits and rollback procedure:
https://github.com/limekex/BeTAiT-h5p-sensei-bridge/blob/main/docs/HSB_010_VALIDATION.md

== Changelog ==

= 1.2.0-dev (unreleased) =
* H5P course progress block and [fkhs_course_progress], with private learner-only course summaries.
* H5P progress layout block for templates and lessons, plus [fkhs_progress]. Hidden automatically without lesson H5P activities.
* Shared access and progression checks; actual completion signals and durable duplicate receipts.
* Native Sensei completion guards, accessible feedback and Bokmål translations.
* Scoped, paginated reporting and explicit privacy/retention tools.
* Installable package with aligned metadata and tested lifecycle.
* H5P activity block with registry search and synchronized placement/metabox thresholds.

== Upgrade Notice ==

= 1.2.0-dev =
Development candidate. Schema changes are additive; no automatic regrading or historical deletion. File rollback cannot restore cleared raw data or erased attempts and restores older behavioral limitations. See the operator procedure before use.
