# Lesson progress UI and API (HSB-011)

## Placement

In the Site Editor’s template or template-part layout, or in a Sensei lesson, insert **H5P progress** / **H5P-progresjon** (Design category). Width (wide/full) and margin/padding follow Gutenberg block controls. The block saves the literal shortcode `[fkhs_progress]`; the shortcode can also be placed directly. It has no lesson/user attributes: it always displays the signed-in learner's progress in the current lesson. The block and shortcode render nothing outside a single lesson or when that lesson contains no discovered H5P activities. No empty progress wrapper or loading placeholder is emitted in these cases. A Query Loop item for a different post is also hidden. It does not embed H5P activities; use **H5P activity** for placement and thresholds.

The editor always displays a compact placeholder explaining conditional visibility, so authors can find and move the block while editing a template. PHP renders the block directly in templates, without depending on the content shortcode filter. Existing saved shortcode-based blocks remain compatible. The lesson page lists each distinct H5P activity, its effective requirement, best score where applicable, and status. Activity buttons focus the corresponding H5P instance. The progress bar counts satisfied **H5P requirements**, not Sensei lesson/course completion. Optional activities are listed separately from the count; optional-only lessons do not get an invented percentage. Empty lessons have no progress block.

Threshold 0 remains optional before a stored attempt. Once an attempt exists it requires actual completion, including preserved historical completion. Positive thresholds use the existing best-score/latest-passed policy. All decisions come from `FKHS_Progress`; the view never writes attempts or completes a lesson.

Every placement shares the existing bridge request, save queue, retries and H5P listener. Pending saves, status errors and tracking errors hide stale progress and show the existing recovery action. Placing the block replaces the automatic footer progress/status display; lessons without a placement keep that display. The block does not suppress Sensei's own requirements or navigation.

Cacheable HTML contains only a generic placeholder, no learner identifiers, scores or REST nonce. Authenticated JavaScript supplies results. Signed-out/no-JavaScript visitors see an explanation. Cookie-authenticated lesson pages still need the existing cache exclusions for the bridge's localized nonce.

## Existing v1 read contract

`GET /wp-json/fkhs/v1/h5p-status?lesson_id=123`

No new route, database table, migration or external authentication scheme is introduced. `OPTIONS /wp-json/fkhs/v1/h5p-status` exposes the successful-response JSON Schema. Existing v1 fields retain their meaning; clients should tolerate additional fields.

Authentication:

- Same-origin browser: WordPress login cookie plus `X-WP-Nonce` for `wp_rest`. Never put credentials into block markup.
- Server-side external client: native WordPress Application Password over HTTPS, HTTP Basic authentication with that learner's username and application password. No nonce is required for this method. The site must enable Application Passwords and forward the Authorization header. WordPress's local-environment exception is used only by the disposable HTTP integration fixture.
- Application Passwords inherit the user's WordPress permissions; they are **not** endpoint-limited read-only tokens. Use the intended learner account, keep credentials server-side, and revoke them through WordPress when no longer needed.

The API always derives the user from authentication. There is no delegated `user_id` parameter. An extra `user_id` query value cannot select another learner. An administrator credential therefore reads that administrator's progress, not a class roster. Sensei enrolment/access and the existing privileged-preview policy still apply; being able to view a public preview alone does not grant access to bridge progress.

Example successful response (one scored activity):

```json
{
  "ok": true,
  "items": [{
    "content_id": 456,
    "title": "Food safety quiz",
    "type": "H5P.QuestionSet 1.20",
    "threshold": 70,
    "latest": { "raw": 7, "max": 10, "pct": 70, "passed": true, "completed": true, "has_attempt": true },
    "best": { "raw": 8, "max": 10, "pct": 80 },
    "has_attempt": true,
    "counts_for_gate": true,
    "requirement_met": true
  }],
  "required": true,
  "passed": true
}
```

| Field | Meaning |
| --- | --- |
| `items` | Distinct activities discovered in the requested lesson; missing registry metadata can produce empty title/type. |
| `threshold` | Effective threshold for this learner, including existing filters. |
| `latest` | Latest stored snapshot. At threshold 0, completion/pass includes preserved earlier completion. |
| `best` | Best stored scored result; `raw`, `max` and `pct` can be `null`. Null means unavailable, not zero. |
| `has_attempt` | Backward-compatible copy of `latest.has_attempt`. |
| `counts_for_gate` | Whether this activity currently contributes a requirement. |
| `requirement_met` | Canonical decision; clients must not reconstruct it from best score alone. |
| `required` | At least one activity counts for the gate. |
| `passed` | All counted requirements are met; true for an empty/optional-only set. This does not mean Sensei has completed the lesson. |

Responses are private and must not be shared between learners. Successful and handler-error responses send `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache`, `Expires: 0` and `X-LiteSpeed-Cache-Control: no-cache`. Verify reverse-proxy behavior separately; the controller does not add a `Vary` header.

Error handling:

| HTTP status | Meaning / action |
| --- | --- |
| 400 | Missing/invalid positive integer lesson ID. Fix the request. |
| 401 / 403 | WordPress authentication/nonce failure, or inaccessible lesson. Reauthenticate/check access; do not show an old pass as current. |
| 403 + `lesson_access_denied` | Existing inaccessible and missing lessons use the same response. |
| 503 | `dependencies_unavailable` or `progress_unavailable`; retry after recovery. |

Plugin handler errors use `{ "ok": false, "reason": "…" }`; WordPress validation/authentication errors use its native `code`, `message`, `data` structure. Check HTTP status before reading `items`. Do not busy-poll: load on navigation and refresh after an acknowledged activity save. History exports, course aggregates, teacher delegation, webhooks and LRS delivery are outside this lesson-scoped contract.
