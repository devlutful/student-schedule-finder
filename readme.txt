=== Student Schedule Finder ===
Tags: students, schedules, csv, excel, shortcode
Requires at least: 5.0
Requires PHP: 7.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage student schedules, import CSV or Excel files, and display matching details through a full-name lookup shortcode.

== Description ==

Student Schedule Finder provides a WordPress admin screen for adding, editing and deleting student schedule records. Import CSV or .xlsx files with a preview before saving. Display a responsive search form using [student_lookup].

Features:

* Nine fields for student name, level, rehearsal, assigned show, classes, drop-off, performance and instructions.
* CSV delimiter detection and UTF-8 or BOM-marked UTF-16 decoding.
* Native .xlsx import of the first worksheet, requiring PHP ZIP and SimpleXML.
* Duplicate complete rows are skipped. Existing records are not overwritten by imports.
* Case-sensitive complete-name matching, with whitespace normalisation.
* Duplicate full names produce no result until staff resolve the ambiguity.
* Bulk editing for shared schedule fields and confirmed bulk deletion of selected students.
* Responsive student cards and dates displayed without timezone conversion.
* No external services, analytics, tracking or remote executable code.

Important: this is a PUBLIC name-only lookup. It does not verify parent identity. Anyone who knows a student's registered name can view their schedule. Only publish information intended for that audience. Do not use it for confidential records.

The plugin uses PHP 7.0-compatible syntax for legacy sites. PHP 7.0 is unsupported, and current WordPress requires a newer PHP version. Upgrade your hosting runtime rather than downgrading WordPress.

== Installation ==

1. Upload the student-schedule-finder directory to wp-content/plugins or upload the plugin ZIP.
2. Activate Student Schedule Finder.
3. Open Student Schedules to manage records or preview and confirm an import.
4. Add [student_lookup] to a Shortcode block or page-builder shortcode widget.
5. Use HTTPS and exclude the lookup page and admin-ajax.php endpoint from caching.

When migrating from Student Schedule Lookup, back up first and deactivate it before activating this plugin. The existing table and shortcode are retained.

== Frequently Asked Questions ==

= What are the import columns? =

Last Name, First Name, Level, Dress Rehearsal Assigned Arrival Time, Show Assigned, # of Classes, Show Day Drop Off Time, Performance Date/Time, Details.

Headings can be reordered. Case and repeated whitespace are ignored. Blank rows and trailing empty CSV columns are ignored.

= Does it accept Excel files? =

Yes, unencrypted .xlsx files, using the first worksheet. Native PHP ZIP and SimpleXML extensions must be enabled. Save old .xls files as .xlsx first. Common date formats and the demo sheet's date formats are supported. Unsupported date formats prompt you to export CSV with the desired text values.

= Are student details private? =

No. Exact-name matching is a lookup rule, not authentication. The plugin intentionally provides public lookup without parent accounts.

= How much can I import? =

Up to 2 MB and 1,000 nonempty students per upload. Invalid records block confirmation. Preview expires in ten minutes.

= What happens when I uninstall? =

Student data is retained to prevent accidental loss. See README.md for complete removal instructions and backup-retention considerations.

== Privacy ==

Student records are stored in the site's WordPress database. Uploads are processed temporarily; previews are stored as WordPress transients. IP addresses are HMAC-hashed for short-lived best-effort rate limiting. Names are submitted in POST requests. No data is sent to an external service by the plugin. Hosts and site backups may retain data independently. Name-only lookup exposes matching schedules publicly.

== Changelog ==

= 1.3.0 =
* Added page selection checkboxes and selected-record count.
* Added confirmed bulk deletion with selected-student preview.
* Added bulk editing for level, rehearsal, show, classes, drop-off, performance and details.
* Added stale-record detection before bulk actions and duplicate-record checks before bulk editing.

= 1.2.0 =
* Renamed to Student Schedule Finder; retained the legacy data table and shortcode.
* Added directory documentation and GPL license text.
* Used distinct PHP class names for the public-release candidate.

= 1.1.0 =
* Added native .xlsx import and improved CSV encoding and delimiter handling.
* Added import diagnostics and ignored blank rows.

= 1.0.0 =
* Initial student management and full-name lookup implementation.
