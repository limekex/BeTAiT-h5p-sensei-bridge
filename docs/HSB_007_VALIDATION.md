# HSB-007 — Language, keyboard and responsive feedback

Performed 2026-11-03, continuing from `2479a26`. This is targeted accessibility/localization work, not a full WCAG conformance claim.

## Changes

- Frontend script translations now attach to `fkhs-h5p-bridge` with `wp-i18n` and the plugin-root `languages` directory. Report fallbacks resolve `MSG[key]` before calling WordPress i18n. Both surfaces use PHP-localized messages; the shipped Bokmål `.po`/`.mo` catalog contains 91 translated source strings. English remains the source fallback. The POT was regenerated from PHP, including all keyed script messages.
- Remaining activities and retry badges are real buttons. Activating them focuses the matching H5P iframe/container and scrolls it into view, honoring reduced-motion preference. Titles are escaped as text. Result feedback is now in normal flow beneath H5P rather than covering its controls. Close/retry controls have explicit button types; Escape dismisses the feedback and restores activity focus. Saving/retry feedback retains a meaningful focus target when its controls are rebuilt.
- Report headers have separate named sort/filter buttons and `aria-sort`. Unsupported H5P-column controls are omitted. Filter dialogs use native `dialog.showModal()`, explicit first/last Tab cycling, associated field labels and focus return. Escape/Close discard draft changes; Apply/Clear commit them. Page size has a name, page information is a live status, initial pagination is populated, and unavailable previous/next controls are disabled.
- Report tables scroll within a named, focusable region; pagination wraps. Dialogs fit within the viewport, inputs shrink within their container, focus indicators are visible, and filter icons no longer rely on very low opacity. Bridge feedback CSS stays scoped to bridge classes and does not restyle Sensei's footer or take ownership of its disabled states.

The dialog behavior follows the [WAI-ARIA dialog keyboard pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/); script registration follows [WordPress's translation API](https://developer.wordpress.org/reference/functions/wp_set_script_translations/). Browser coverage below is narrower than those patterns' full assistive-technology matrix.

## Automated evidence

- **470 PHP assertions**, PHP/JS/shell syntax and whitespace checks pass.
- **22 Node tests pass**: the previous 17 gate/transport tests plus five jsdom DOM tests. The new tests exercise English fallback, localized labels, sorting semantics, initial pagination, filter commit/cancel, Tab cycling/focus return, associated labels, escaped activity titles, iframe focus and reduced motion. jsdom is a development dependency only; `npm ci --ignore-scripts` installs it in CI. Native dialog modality is checked in Chrome separately.
- A disposable WordPress 7.1.4/PHP 8.2.32/Sensei 4.26.4/H5P 1.17.10/MariaDB 10.11.18 run passes the existing **365 integration assertions**, concurrent delivery and **12 new real WordPress translation/enqueue assertions**. `tests/wordpress-i18n.php` switches request-local locale filters, relies on the actual plugin textdomain loader, and verifies both script handles, paths/dependencies and localized payloads. It does not change site language options. This test is now included in all three CI integration profiles.
- `msgfmt --check` validates the Bokmål catalog and its format placeholders; recompilation matches the checked-in MO file.

## Local browser evidence

Chrome on the authorized FagKlar site at `localhost:8887` uses WordPress 7.1.4, PHP 8.5.7, Sensei 4.26.4 and H5P 1.17.10. A new fixture lesson **117**, `/lesson/hsb-sprak-og-tastatur-lokalt/`, belongs to test course 105 and uses H5P 1 with a 70% threshold. Student 14 and existing attempts were retained. The fixture option is `fkhs_hsb007_fixture`.

At **320 CSS pixels** wide:

1. Bokmål status text, retry badge and remaining-activity button appear while completion is locked. Enter on the activity button moves focus to the actual H5P iframe.
2. Keyboard selection and submission of the real True/False activity saves 1/1, shows Norwegian passed/saved feedback and releases Sensei's completion controls.
3. The result panel fits beneath the activity. Escape from Close removes it and returns focus to the iframe. Document width and scroll width both remain 320; the panel does not cover H5P controls.
4. Enter on Complete Lesson completes the native Sensei lesson. The subsequent normal English page retains the passing activity and disabled Completed state.

Report controls were tested in an **isolated HTML fixture with synthetic rows**, the actual plugin script and actual WordPress-generated en_US/nb_NO payloads. This did not change administrator credentials or expose real report rows. At 320 pixels, the dialog is 288 pixels wide and document scroll width is 320. Enter opens the filter and focuses its input. Shift+Tab/Tab cycle between input and final Apply button. Applying the User filter changes two rows to one and returns focus to the now-labelled active filter. English date filters expose From/To names; Escape returns focus to the opener. Desktop/default width was also checked.

The temporary locale MU filter was restricted to local requests with an explicit test parameter and removed afterwards. The preview server was stopped and viewport override reset. The new lesson remains for follow-up; no theme source or global site language was changed.

## Reproduce and remaining scope

```sh
npm ci --ignore-scripts
npm test
bash tests/php-checks.sh
bash tests/integration/run.sh wp71-php82
msgfmt --check h5p-sensei-bridge/languages/h5p-sensei-bridge-nb_NO.po -o /tmp/fkhs-nb_NO.mo
```

Regenerate the POT with `wp i18n make-pot h5p-sensei-bridge h5p-sensei-bridge/languages/h5p-sensei-bridge.pot --exclude=languages --skip-js`, update the PO and compile it to the matching MO file. Keyed script messages are extracted from their PHP localization arrays, not dynamic JavaScript gettext calls.

Actual VoiceOver/NVDA speech, confirmed browser 200% zoom, physical touch devices, Firefox/Safari, and the complete report inside WordPress admin chrome remain unverified. The zoom shortcut attempt did not establish a changed zoom factor; 320-pixel reflow is the verified result. Native dialogs require a browser supporting `showModal()`. H5P-authored question strings and Sensei's own translations belong to those packages. The report still filters only its loaded latest-N rows; full-history filtering/teacher scope/performance remain HSB-008. No access policy, scoring threshold or existing attempts were rewritten.


Follow-up 2026-11-03: all seven [GitHub jobs for HSB-007](https://github.com/limekex/BeTAiT-h5p-sensei-bridge/actions/runs/41666234080) passed on `fadff83`. [HSB-008](HSB_008_VALIDATION.md) subsequently replaces the latest-N report with full-history server search and checks the report in actual WordPress admin, including keyboard focus, English/Bokmål and 320px reflow. The broader screen-reader/zoom/browser limits above remain open.
