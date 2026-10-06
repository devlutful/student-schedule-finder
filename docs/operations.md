# Student Schedule Finder 1.3.0

## Install

1. Back up the website and test on a staging copy first.
2. In WordPress, open Plugins > Add New > Upload Plugin. Select student-schedule-finder.zip and activate it.
3. Open Student Schedules in the admin sidebar. Administrators have access by default.
4. Add records manually or upload a UTF-8 CSV or .xlsx file. Preview the data and click Confirm import.
5. Put `[student_lookup]` in a Shortcode block or Elementor Shortcode widget.
6. Exclude that page AND `/wp-admin/admin-ajax.php` from page/CDN caching. Use HTTPS. Check the lookup while logged out.

## Supported data and import behaviour

CSV headings must use the nine original headings, in any order. Capitalisation and repeated whitespace are ignored. Comma, semicolon and tab delimiters are detected; UTF-8 and BOM-marked UTF-16 are supported. Trailing empty columns and blank rows are ignored:

Last Name, First Name, Level, Dress Rehearsal Assigned Arrival Time, Show Assigned, # of Classes, Show Day Drop Off Time, Performance Date/Time, Details.

Excel .xlsx uploads are now accepted directly, reading the first worksheet. ZIP and SimpleXML PHP extensions are required; legacy .xls files must first be saved as .xlsx. Supported Excel date formats include the exact formats in the supplied demo. Unknown date formats are rejected with a CSV fallback instruction rather than silently changing their presentation. Formula cells require saved calculation results. No macros are executed. The supplied demo-data.csv uses the sample sheet's displayed date/time formats without timezone conversion. Dates are display text, not timestamps. The import preserves those text values. If Excel exports numeric date serials, correct the CSV before importing.

Limits: 2 MB, 1,000 nonempty student rows, nine columns. Invalid rows block the entire import until corrected. The preview shows the first ten valid rows and up to twenty validation errors. A preview expires in ten minutes. Original uploads are deleted after parsing; the pending preview is temporarily stored in a private WordPress transient. An abandoned preview may remain in the database until WordPress's expired-transient cleanup runs.

Identical complete rows are skipped, including repeated imports. Imports NEVER update or overwrite existing records. To change a student, edit that record in the backend. Importing a changed row creates another record. If that produces duplicate full names, lookup is withheld until staff resolve the duplicate. There is no stable student ID in the supplied sheet, so name-based automatic updates are intentionally avoided.

## Search rules

One input for full first and last name in that order. Whitespace is trimmed and repeated whitespace collapsed. Case, accents, spelling, hyphens and punctuation must match. Alex Smith matches; alex smith and Alex do not. First names and surnames containing spaces are supported. No partial search, suggestions, browse list or public bulk export.

If exactly one record matches, all nine approved sheet fields are returned. Zero matches or more than one match produce the same generic message. No database identifiers, hashes or internal timestamps are returned.

## Security and operational limits

Name-only lookup is public by design. It does NOT establish parent identity or make schedules private. Anyone who knows a registered full name can access that student's information. Do not add confidential medical, address, financial or contact information without changing the access model.

Staff operations require the manage_student_schedules capability and WordPress nonce verification. Queries are parameterised, records are validated, HTML output is escaped, and frontend results use textContent. There are no external service dependencies. Records use a dedicated table in the existing WordPress database and are not encrypted by this plugin. Protect the database and backups through hosting access controls.

Lookup responses send no-store headers, but the host/CDN must honour them. Names are submitted in POST bodies, not URLs. Do not enable request-body logging on the lookup endpoint. Database/preview data is covered by normal site backup and retention practices.

The application limits each REMOTE_ADDR to 10 requests in five minutes using WordPress transients. This is a best-effort control: simultaneous requests can race; shared networks may share a limit; reverse proxies may hide client IPs; distributed attackers can bypass it. For meaningful bot resistance, add atomic rate limiting at the host or reverse proxy on the lookup action. Do not blindly trust forwarded IP headers. A public WordPress nonce is not used as an access secret.

Deleting a student permanently removes the active database record. Backups may still retain it. Deactivation and uninstall preserve data to avoid accidental loss. To fully remove the plugin data, a qualified administrator must remove the prefixed student_schedules table, ssl_import_* and ssl_rate_* transients, and the manage_student_schedules capability after backing up as needed. No automatic deletion is triggered.

## PHP / WordPress compatibility

The plugin is written in PHP 7.0-compatible syntax and uses WordPress APIs available since WordPress 5.0. Current WordPress requires PHP 7.4 or higher; PHP 7.0 cannot run the current WordPress release. Verify the actual web PHP version under Tools > Site Health > Info > Server. Hosting CLI PHP can differ from web PHP.

PHP 7.0 is unsupported. Plugin syntax compatibility does not make the runtime secure. Plan a tested upgrade to a supported PHP release.

## Verification status

Automated frontend logic checks with a minimal simulated DOM and mocked network cover successful nine-field results, failure messages, malicious-looking values rendered as text, POST request fields, cleared prior results, button recovery and N/A preservation. JavaScript syntax was checked. CSS includes a single-column mobile breakpoint, but no rendered browser layout check was possible because no browser executable was available. No PHP interpreter or running WordPress database was available in the build environment. PHP execution, activation/dbDelta, permission checks, CSV import, persistence and real mobile layout must therefore be verified on staging before production use. This is a packaged implementation, not a claim of live-site validation or verified PHP runtime compatibility.

Staging acceptance checks: activate without errors; add/edit/delete as an administrator; reject staff operations without the capability; import demo CSV and confirm three rows; import it again and confirm zero additional rows; test malformed headers/oversized file/invalid row; search each sample name; reject partial and wrong-case names; create another Alex Smith and confirm withholding; test 11 requests in five minutes; confirm no caching of student responses.

## Migrating from Student Schedule Lookup

Back up first. Deactivate the old plugin before activating Student Schedule Finder. Both use the same existing student_schedules table and [student_lookup] shortcode. Do not run both at once. Data is preserved, but staging verification is still required.

## Bulk record management

Select students using the list checkboxes, or Select all for the current page (25 students). Choose Bulk edit or Bulk delete and Continue. Selection does not persist across pages. Bulk operations require the staff capability and an action nonce.

Bulk edit: choose the shared fields to replace. Only ticked fields change; a ticked blank clears a field. First and last names are edited individually. All selected rows are validated and identical-record conflicts checked before any writes. Records modified since preview block the action. Writes are not transactional: an unexpected database failure can leave some records updated; the report shows saved/failed counts. Concurrent changes in the interval after the stale-record check remain a limitation.

Bulk delete: review the selected names, tick the explicit deletion confirmation and submit. Only the listed IDs are deleted. Active-database deletion is permanent. Backups may retain copies.

Existing individual edit/delete controls remain available. Required staging checks: no selection, mixed checkbox selection, selecting all on a page, leaving fields untouched, clearing an explicit field, rejecting name mass changes, rejecting duplicate rows, stale preview, deletion confirmation, permission/nonce failure, and partial database failures. PHP database execution remains unverified in the preparation environment.

## GitHub verification update

GitHub Actions passed PHP syntax and standalone import/bulk-input regression tests on both PHP 7.0 and PHP 8.3 on 6 October 2026. Earlier preparation-environment notes describe the checks available at that time. Full WordPress activation/database/permissions/caching and rendered mobile checks are still pending.
