# Changes — local_scorm_maker_import

## 1.2.5 (2026-10-08) — version 2026100800
Changes requested by the Moodle Plugins Directory review.
- Added: `LICENSE` file (GNU GPL v3) in the plugin root.
- Changed: temporary package files are created with `make_request_directory()`
  instead of a hand-made directory under `$CFG->tempdir`.
- Added: SCORM packages are limited to the course upload size,
  `get_max_upload_file_size($CFG->maxbytes, $course->maxbytes)`, the same
  limit as the activity form. Downloads set `CURLOPT_MAXFILESIZE` and stop
  from a progress callback once over the limit; draft files are checked
  before copying. New error `packagetoolarge`.
- Changed: `$plugin->requires` and the `mod_scorm` / `mod_book` dependencies
  raised to `2024100700` (Moodle 4.5), matching `$plugin->supported`: the
  code uses `core_external` (Moodle 4.2+) and PHP 8 syntax.
- Changed: the `name` parameter of both functions now defaults to `''`; an
  empty name uses the new `defaultscormname` / `defaultbookname` language
  strings instead of the hard-coded Portuguese names.
- Docs: README in English (Portuguese kept as `README.pt_br.md`); code
  comments and PHPDoc translated to English; screenshots in
  `docs/screenshots/`.
- CI: GitHub Actions workflow running moodle-plugin-ci on Moodle 4.5–5.2.

## 1.2.4 (2026-09-14) — version 2026091401
- Maintenance: incremented the plugin build number for the Moodle Marketplace
  resubmission. No functional changes.

## 1.2.3 (2026-09-14) — version 2026091400
- Fixed: supplied Moodle's `cmidnumber` when creating SCORM and Book activities,
  removing undefined-property warnings on Moodle 4.5 and 5.2.
- Compatibility: declared Moodle 4.5 through 5.2 as the supported range after
  running the complete PHPUnit suite on Moodle 4.5.14 and Moodle 5.2.3.

## 1.2.2 (2026-09-09) — version 2026090902
- Security: restricted remote SCORM package downloads to the exact HTTPS host
  `scormmaker.com.br`; HTTP, subdomains, host-confusion URLs, credentials and
  non-standard ports are rejected before any network request.
- Security: disabled redirects for remote SCORM downloads so a response from
  the authorized host cannot make the Moodle server fetch a different host.
- Security: sanitized Book descriptions and chapter HTML with Moodle's
  `clean_text(..., FORMAT_HTML)` before storing content.

## 1.2.1 (2026-09-09) — version 2026090901
- Added the plugin icon at `pix/icon.svg`.
- Escaped the literal `<h1>` tag in the Book web service description so the
  word "section" is rendered with normal text formatting in Moodle's UI.

## 1.2 (2026-09-09) — version 2026090900
- Renamed the plugin to **Scorm Maker Import**, including its Moodle component,
  namespaces, language files, web service names and temporary directory.
- Existing installations of `local_asd_resource_import` are not migrated
  automatically; install the renamed plugin and recreate its external service
  and tokens as needed.

## 1.1 (2026-08-11) — version 2024060101
- Added: `import_scorm_from_url` now accepts a `draftitemid` (from a prior
  `/webservice/upload.php` call) as an alternative to `url`, for callers that
  cannot host the SCORM ZIP at a public URL. Requires `uploadfiles => 1` on
  the external service, now declared in `db/services.php` (existing 1.0
  installs must run the site upgrade once for this to take effect on the
  already-created service record).
- Fixed: `import_scorm_from_url` failed on every valid call with
  `Undefined constant SCORM_UPDATE_NEVER` — `mod/scorm/locallib.php` (where
  that constant and `GRADESCOES` are defined) was never included, only
  `mod/scorm/lib.php`. Found via E2E testing against a real Moodle 5.0
  install.
- Changed: capability check now runs before the course-existence check in both
  external functions, so a caller without `moodle/course:manageactivities`
  cannot distinguish an existing course from a non-existing one by exception
  type (course-enumeration oracle, found in the mandatory security audit).

## 1.0 (2024-06-01) — version 2024060100
- Initial release: `import_scorm_from_url` and `import_book_from_html`.
