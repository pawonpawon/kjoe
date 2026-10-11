# Changelog

## Unreleased

- Extracted the original file-based queue into a standalone Composer library.
- Retained the small queue API and CSV storage with an explicit storage directory.
- Kept the original method layout and explicit coding style, without Laravel inheritance.
- Preserved appending and in-place removal in a single CSV, with consistent file locking.
- Fixed CSV round trips, exact pending duplicate checks, empty-queue handling,
  long records, and error reporting.
- Added generic bounded cron examples, process-level tests, and Linux CI for
  PHP 7.3–8.5.
- Documented installation, usage, and what happens when a worker crashes.
