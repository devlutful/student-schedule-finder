# Student Schedule Finder

**Free WordPress student schedule lookup with CSV/Excel import, responsive results and bulk record management.**

Built by Lutful Ahmed for schools, dance studios and event organisers who want to publish rehearsal, drop-off and performance details through a complete-name search.

## Release status

Release candidate 1.3.0. JavaScript logic checks passed in the preparation environment. PHP regression tests, WordPress integration testing and Plugin Check remain pending. This is not a WordPress.org-approved release.

## Features

- Add, edit and delete individual student records.
- Bulk edit shared schedule fields and bulk delete after confirmation.
- CSV and Excel .xlsx imports with preview and duplicate-row protection.
- One full-name search using `[student_lookup]`.
- Responsive student cards showing nine schedule fields.
- Supported date/time formats preserved without timezone conversion.
- WordPress-native database and permission APIs; native PHP ZIP/XML.
- No paid licence, external service, analytics or mandatory parent account.

## Install

Download the [installable student-schedule-finder.zip](dist/student-schedule-finder.zip?raw=true), upload it through Plugins > Add New > Upload Plugin and activate. Use a staging site first. Open Student Schedules to manage/import records, then add `[student_lookup]` in a Shortcode block or Elementor Shortcode widget. Use HTTPS and exclude the lookup page and AJAX endpoint from caching.

Migrating from Student Schedule Lookup: deactivate the old plugin first. The existing data table and shortcode are retained.

## Important access behaviour

**Name-only lookup is public.** Anyone who knows the registered full name can view a student's schedule. This does not verify parent identity. Do not add private addresses, medical or financial details.

Matching is case-sensitive, with whitespace normalisation. Partial names do not match. Duplicate full names return no student until staff resolve the ambiguity.

## Import and bulk management

Use [demo-data.csv](demo-data.csv) as a template. The distributed sample and test fixtures are fictional and contain no uploaded student records. Limits: 2 MB and 1,000 nonempty students. CSV supports comma, semicolon and tab delimiters; UTF-8 and BOM-marked UTF-16. Excel reads the first worksheet and requires PHP ZIP and SimpleXML. Old .xls files are not supported. Unsupported Excel date formats prompt a CSV fallback.

Imports skip identical rows and never overwrite existing records. Bulk edit changes only ticked fields; ticked blank values clear fields. Names are edited individually. Select all covers the current page of 25 records. Bulk deletion requires confirmation.

## Documentation and testing

See [operations and staging checks](docs/operations.md) for the nine columns, import behaviour and known limitations. PHP 7.0 syntax is targeted for legacy compatibility, but PHP 7.0 is unsupported and cannot run current WordPress. Prefer a supported runtime.

CI is configured for PHP 7.0 and 8.3 syntax and standalone import/bulk-input regressions. Stubbed tests do not verify WordPress database, permissions, caching or theme integration.

## Roadmap

Planned, not implemented: multiple datasets, custom columns, ID-based import updates, CSV export, undo/change history and display controls. This is a focused schedule lookup plugin, not a full feature-equivalent replacement for general table plugins.

## Contributing and support

Open an issue with versions, steps to reproduce and errors. Use dummy data; never upload real student records, credentials or database backups. Small pull requests and reproducible bug reports are welcome.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). Copyright 2026 Lutful Ahmed.
