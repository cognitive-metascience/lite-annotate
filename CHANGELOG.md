# Changelog

## 1.0.0 — 2026-06-16

### Added
- Per-project multiple-choice label sets with configurable Label | Shortcut | Value format.
- Dynamic decision buttons rendered from project choice schemas.
- Keyboard shortcuts for annotation decisions (press the number key shown on a button).
- Decision choice normalization engine (`includes/annotation_core.php`) — parses, validates, normalizes, and serializes choices.
- Project instructions field displayed to annotators.
- Nominal Krippendorff's alpha for multi-class inter-annotator agreement.
- Annotator consistency check for duplicate snippets (same content, same annotator).
- Superannotator interface for resolving disagreements with final decisions.
- Unanimous decision detection in JSON export (unanimous annotations auto-resolved).
- Column/index existence checks in `setup_database.php` for safe upgrades.
- PHPUnit 12 test suite (`tests/AnnotationCoreTest.php`) covering decision choices, Cohen's kappa, Krippendorff's alpha, and highlighting.
- PHPUnit configuration (`phpunit.xml.dist`), bootstrap, and stub classes.
- Application versioning: `APP_VERSION` constant, `VERSION` file, and version badge in UI.
- Composer project definition with PHP 8.4 requirement.
- `.gitignore` for database config, tools, and cache directories.

### Security
- Added session regeneration after login to prevent session fixation.
- Added CSRF protection to all state-changing forms (login, user management, project assignment, annotations, superannotator).
- Added login rate limiting (5 attempts per 15-minute window).
- Added MIME-type validation on JSON upload.
- Removed exposed `public/pass_hash.php` dev artifact.
- Hardened `setup_database.php` against re-run with a guard check.

### Fixed
- Fixed double-counting in annotator consistency calculation.
- Replaced racy DELETE-then-INSERT pattern with atomic `ON DUPLICATE KEY UPDATE` in `saveAnnotation` and `saveFinalDecision`.
- Made `highlightSnippet` case-insensitive (`str_ireplace`).
- Added input validation to `createUser` (username format, password length, valid role).
- Replaced fragile ID-increment navigation with SQL-based next/prev queries in annotate view.
- Fixed PHPUnit stub classes: assertions now throw `LogicException` rather than silently passing when PHPUnit is missing.
- Removed dead `session_status()` checks in annotate and superannotate views.
- Fixed form handler control flow in admin.php (all handlers now inside CSRF-guarded chain).
- Backtick-quoted database identifiers in `setup_database.php`.
- Made `BASE_URL` dynamically derived from server vars instead of hardcoded localhost.

### Changed
- `annotations.decision` and `final_decisions.decision` migrated from `BOOLEAN` to `VARCHAR(100)` for multi-class support.
- Default Yes/No choices auto-populated when project schema is empty.
- `setup_database.php` normalizes legacy boolean decisions (`1` → `yes`, `0` → `no`) and cleans duplicate rows.
- JSON export now includes unanimous decision resolution logic.
- Admin project settings form loads existing choices via `serializeDecisionChoicesToText`.
