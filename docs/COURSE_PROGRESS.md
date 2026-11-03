# HSB-012 — Learner course progress

The owner selected the learner's own H5P progress across a course's lessons. This adds **H5P course progress** (**H5P-kursprogresjon**) in the Design inserter for course and template editing, plus `[fkhs_course_progress]` for shortcode placement. No teacher roster, CSV, external LRS or notifications are included.

## Placement and behavior

Place the block in course content, a single-course template or a shared template part. It uses the current queried course, supports wide/full alignment and margin/padding, and is hidden outside single courses and in courses without H5P activities. The editor retains a compact explanatory placeholder. Dynamic PHP rendering works directly in templates without relying on a template-wide shortcode filter; the saved fallback remains a normal shortcode. Foreign Query Loop post contexts are suppressed.

The learner sees total H5P requirements met, optional activities, and the same counts per lesson with a link to that lesson. Published lessons without H5P are omitted. This is a count of activity requirements, not an average score or a declaration that Sensei has completed the course. Sensei alone controls lesson/course completion.

Each `(lesson, activity)` pair is distinct. Repeated copies in one lesson count once; the same H5P activity in two lessons counts separately with those lessons' thresholds and attempts. Removed activities and unpublished lessons are outside the current overview; their historical attempts are retained. Positive thresholds use the existing latest-pass/best-score policy. Threshold zero is optional before a stored attempt, then requires actual completion; historical completion remains sticky. Custom threshold filters continue to apply for the current learner.

Only lessons the learner can currently access are counted. If any published lesson is inaccessible, the overview explicitly says that it is limited and further requirements may appear. It never lists that lesson's title, ID, URL or results. An optional-only overview has no percentage bar. A course with no accessible activities and no access restrictions has no visible card.

Multiple placements share one GET. Refresh, returning to the tab, and restoring a page from browser history reload the snapshot. Loading or failed reads hide old results; retry preserves button focus. Authentication errors offer a sign-in link in a new tab. There is no polling, attempt write, completion action or H5P event listener on course pages.

## Read API

`GET /wp-json/fkhs/v1/course-progress?course_id=123`

Authentication uses WordPress cookies plus REST nonce or native Application Passwords. The endpoint always uses the authenticated user; `user_id` cannot select another learner. A published, unpassworded course requires actual Sensei enrollment. Course editors and administrators may preview their own progress. Each lesson still passes the existing canonical lesson-access check. A missing or inaccessible course receives the same generic 403 response.

Example successful response:

```json
{
  "ok": true,
  "course_id": 123,
  "required_count": 3,
  "met_count": 2,
  "optional_count": 1,
  "activity_count": 4,
  "limited": false,
  "lessons": [
    {"lesson_id": 124, "title": "First lesson", "url": "https://example.test/lesson/first/", "required_count": 1, "met_count": 1, "optional_count": 1, "activity_count": 2},
    {"lesson_id": 125, "title": "Second lesson", "url": "https://example.test/lesson/second/", "required_count": 2, "met_count": 1, "optional_count": 0, "activity_count": 2}
  ]
}
```

`required_count` counts canonical `counts_for_gate` items; `met_count` counts those whose `requirement_met` is true. `optional_count` is the remaining activity count. `activity_count = required_count + optional_count`. All totals equal the sums of the returned lessons. `limited` qualifies the scope, not a completion flag. OPTIONS exposes a JSON schema for successful responses. No raw attempts, statements or learner identifiers are returned.

Responses set private/no-store/no-cache headers, including the existing LiteSpeed hint. Cacheable HTML contains no personal results. Missing login gives 401, inaccessible course 403, invalid course ID 400, read/dependency/filter errors 503, and exceeding the supported scope 422 (`course_too_large`). Failed reads never return partial progress as success.

## Bounds and compatibility

A snapshot supports up to 200 published lessons and 500 current activity pairs across accessible lessons. Above those limits the API reports an error; it never silently truncates the denominator. Lessons use Sensei's `_lesson_course` membership and `_order_COURSEID` ordering, with date/ID ties, followed by the `sensei_course_get_lessons` hook. Filtered lists are revalidated for publication, course membership, duplicates and size.

At most two SQL reads resolve the latest/best row IDs plus historical completion and fetch only the selected rows (at most 1,000). Complete attempt histories stay in the database. The batch lookups use the existing `event_identity` index prefix for user, lesson and content. Both this batch read and the existing single-lesson read call the same policy function. No schema migration or index change is introduced. The snapshot is read-only and does not retroactively regrade attempts.

H5P discovery keeps the existing lesson contract: literal activity shortcodes in lesson content, including normal serialized activity blocks. Synced activity patterns must be detached; template-only H5P activities are not discovered. Site-specific access/order/threshold filters and very large histories still need their own load and rollout verification.

## Validation and rollout

Automated evidence is recorded in [HSB-012 validation](HSB_012_VALIDATION.md). The plugin remains `1.2.0-dev`, schema `0.3.0`. Existing staging/release gates apply. To roll back, restore the previous plugin files and remove course-progress placements; attempts and Sensei progress are unchanged.
