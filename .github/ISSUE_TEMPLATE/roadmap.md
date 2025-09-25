---
name: Roadmap
about: Tracking planned features and improvements for H5P Sensei Bridge
title: Roadmap
labels: enhancement
assignees: ''

---

# Roadmap — H5P Sensei Bridge

## Phase 1: Stabilization & Polish
- [ ] **Cache & fetch robustness** – Ensure fresh status data is always used (no browser/proxy caching issues).
- [ ] **Better debug mode** – Add a developer toggle (via `window.fkH5P.debug` or admin setting) to log all status checks, events, and REST calls.
- [ ] **Improved i18n** – Generate JSON translation files automatically and verify front-end strings are localized properly.
- [ ] **Flexible thresholds** – Allow per-H5P threshold settings instead of relying only on the global default.

---

## Phase 2: Teacher & Student Value
- [ ] **Admin report panel** – Display per-student status: which H5P tasks are passed, best score, last attempt date.
- [ ] **Extended student feedback** – Show best score (and not just pass/fail) in the front-end status panel.
- [ ] **Progressive unlocks** – Option to lock not only quizzes but also “Mark lesson complete” until all H5Ps are passed.
- [ ] **Shortcode / Gutenberg block** – Provide a block to display task progress (list or progress bar) anywhere in lesson content.
- [ ] **Refined REST endpoint** – Add a clean `/h5p-progress` endpoint for external tools/dashboards.

---

## Phase 3: Advanced Features
- [ ] **Analytics dashboard** – Aggregated data per course: % of students passing each H5P, average scores, attempts distribution.
- [ ] **Gamification** – Award badges or points for completing all H5Ps in a lesson/course (integration with BadgeOS, BuddyBoss, etc.).
- [ ] **Adaptive hints/resources** – If students repeatedly fail, provide automatic links to extra resources or practice material.
- [ ] **Data export** – Allow teachers to export H5P results to CSV/Excel.
- [ ] **xAPI LRS integration** – Push statements to external LRS for enterprise-level learning analytics.

---

## Phase 4: Nice-to-Have / Stretch Goals
- [ ] **Custom theming** – Let admins override the look & feel of overlays, badges, and status panels without touching code.
- [ ] **Notifications** – Email/notify teachers when all tasks are completed by a student (or when someone is struggling).
- [ ] **Compatibility with other LMS plugins** – Explore support for LearnDash, LifterLMS, or TutorLMS in addition to Sensei.

---
