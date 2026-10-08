# Scorm Maker Import (local_scorm_maker_import)

*[Versão em português](README.pt_br.md)*

Local plugin that exposes two token-authenticated web service functions,
letting external systems create content in Moodle courses without a teacher
doing it by hand:

- **Import a SCORM package** from an authorised HTTPS URL on
  `https://scormmaker.com.br` **or** from a file uploaded directly.
- **Import a Book** (`mod_book`) from a block of HTML, split automatically
  into chapters at the `<h1>` tags.

## Screenshots

The plugin has no user interface of its own; these show its service and the
activities it creates (Moodle 4.5):

- [External service functions](docs/screenshots/01-external-service-functions.png)
- [Course with the imported SCORM and Book activities](docs/screenshots/02-course-with-imported-activities.png)
- [Imported SCORM package in the player](docs/screenshots/03-imported-scorm-player.png)
- [Imported Book, one chapter per `<h1>`](docs/screenshots/04-imported-book-chapter.png)

## Features

- `local_scorm_maker_import_import_scorm_from_url` — downloads a SCORM
  package (.zip) only from `https://scormmaker.com.br` (exact host), **or**
  uses a file already uploaded through `/webservice/upload.php` (see "Usage"
  below); checks that the ZIP has `imsmanifest.xml` at its root and creates
  the SCORM activity in the given course/section.
- `local_scorm_maker_import_import_book_from_html` — creates a Book activity
  from a block of HTML, split automatically into one chapter per `<h1>`
  section (text before the first `<h1>` becomes the "Introduction" chapter;
  without any `<h1>`, the whole content becomes a single chapter).
- Packages are held to the course upload limit, the same one a teacher gets
  in the activity form.
- Both functions re-check the `moodle/course:manageactivities`
  capability inside `execute()`, whatever `db/services.php` declares — even
  when the token has access to the service, the user behind it must be a
  teacher/manager in the target course.

## Requirements

- Moodle 4.5 or later (`$plugin->requires = 2024100700`). Supported Moodle
  branches: 4.5 through 5.2 (`$plugin->supported = [405, 502]`), checked with
  the PHPUnit suite on Moodle 4.5.
- `mod_scorm` enabled on the site, to use the SCORM import function.
- `mod_book` enabled on the site, to use the Book import function.
- PHP extension `ext-zip` (already required by Moodle) to validate the ZIP.
- For the `url` option, the package must be available over HTTPS on the exact
  host `scormmaker.com.br`.

## Installation

1. Copy this plugin to `local/scorm_maker_import`.
2. Go to *Site administration → Notifications* (or run
   `php admin/cli/upgrade.php --non-interactive`) to finish the installation.
3. Enable web services and the REST protocol in *Site administration →
   Server → Web services*, if they are not enabled yet.
4. In *Site administration → Server → Web services → External services*,
   enable the **Scorm Maker Import** service (it is disabled by default) and
   add the users authorised to call it.
5. Create a web service token for an authorised user in *Site
   administration → Server → Web services → Manage tokens*.

If you had version 1.0 of this plugin installed (before direct upload was
supported), run the upgrade again (step 2) — that is what applies the file
upload permission (`uploadfiles`) to the existing service.

## Configuration

This plugin has no `settings.php` — there is nothing to configure beyond the
standard web service setup above (enable the service and issue tokens).

The largest accepted package is the course upload limit:
`get_max_upload_file_size($CFG->maxbytes, $course->maxbytes)`, which is also
capped by the PHP `upload_max_filesize` and `post_max_size` settings. Raise
*Site administration → Security → Site security settings → Maximum uploaded
file size* and the course *Maximum upload size* (and the PHP limits) if your
packages are larger.

## Usage

All calls below use Moodle's classic REST protocol
(`/webservice/rest/server.php`) with `moodlewsrestformat=json`. Replace
`$WWWROOT` with your site URL and `$TOKEN` with the token created in step 5
of the installation.

### 1. Import SCORM from an authorised HTTPS URL

**Function:** `local_scorm_maker_import_import_scorm_from_url`

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `courseid` | integer | Yes | — | Id of the course where the activity is created. |
| `url` | URL | See note¹ | `''` | HTTPS URL of the `.zip` file on the exact host `scormmaker.com.br`. |
| `name` | text | No | `''` | Name of the activity. Empty uses the `defaultscormname` language string ("Imported SCORM package" in English). |
| `sectionnum` | integer | No | `0` | Course section number (0 = general section). |
| `draftitemid` | integer | See note¹ | `0` | Alternative to `url` — see "Import SCORM through direct upload" below. |

¹ **Give exactly one** of `url` and `draftitemid` — never both, never
neither. Giving both or neither results in the `invalidscormsource` error.

**Returns:**

```json
{
  "scormid": 12,
  "cmid": 34,
  "warnings": []
}
```

**Example (by URL):**

```bash
curl -sS "$WWWROOT/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_scorm_from_url" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=2" \
  --data-urlencode "url=https://scormmaker.com.br/packages/course.zip" \
  --data-urlencode "name=Onboarding course" \
  --data-urlencode "sectionnum=1"
```

### 2. Import SCORM through direct upload (no public URL)

When the SCORM package is not hosted on an authorised HTTPS URL that the
Moodle server can reach, first upload the file to the token user's own draft
area with Moodle's standard upload endpoint, `/webservice/upload.php`, then
call `import_scorm_from_url` passing the returned `itemid` in the
`draftitemid` parameter instead of `url`:

```bash
# Step 1: upload the .zip and get a draftitemid back.
# Note: the file field name must NOT use brackets "[]"
# (use "file_box=", never "file_box[]=") — Moodle's upload endpoint
# reads $_FILES as plain entries and misreads the array form.
curl -sS "$WWWROOT/webservice/upload.php" \
  -F "token=$TOKEN" \
  -F "file_box=@package.zip"
# -> [{"itemid": 123456, "filename": "package.zip", ...}]

# Step 2: import using that draftitemid instead of url.
curl -sS "$WWWROOT/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_scorm_from_url" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=2" \
  --data-urlencode "draftitemid=123456" \
  --data-urlencode "name=Uploaded course"
```

The draft area is always restricted to the token user — a caller cannot
reference a file uploaded by another user by guessing the `itemid`, the same
guarantee every Moodle upload form already relies on.

This flow needs the file upload permission (`uploadfiles`) on the **Scorm
Maker Import** service, which this plugin declares in `db/services.php` since
version 1.1. If the site was installed with version 1.0, run the upgrade once
(*Site administration → Notifications*) to apply it to the existing service.

### 3. Import a Book from HTML

**Function:** `local_scorm_maker_import_import_book_from_html`

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `courseid` | integer | Yes | — | Id of the course where the activity is created. |
| `htmlcontent` | HTML (raw text) | Yes | — | Full HTML content of the book. |
| `name` | text | No | `''` | Title of the book. Empty uses the `defaultbookname` language string ("Imported book" in English). |
| `description` | HTML (raw text) | No | `''` | Introduction/description of the book. |
| `sectionnum` | integer | No | `0` | Course section number (0 = general section). |

**How the HTML is split into chapters:**

- Each `<h1>` tag starts a new chapter; the chapter title is the text of the
  `<h1>` (inner tags such as `<strong>`/`<em>` are removed, leaving plain
  text).
- Content **before** the first `<h1>` becomes a first chapter called
  "Introduction".
- If **no** `<h1>` is found, the whole `htmlcontent` becomes a single
  chapter, titled with the book name.

**Returns:**

```json
{
  "bookid": 5,
  "cmid": 21,
  "chapterids": [10, 11, 12]
}
```

`chapterids` comes in creation order (the introduction chapter, if any,
followed by the chapters in the order their `<h1>` appear in the HTML).

**Example:**

```bash
curl -sS "$WWWROOT/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_book_from_html" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=2" \
  --data-urlencode "name=Student handbook" \
  --data-urlencode "description=<p>Imported automatically.</p>" \
  --data-urlencode "htmlcontent=<p>Opening text.</p><h1>Chapter 1</h1><p>Content...</p><h1>Chapter 2</h1><p>More content...</p>"
```

A sample file ready for testing is in
[`tests/fixtures/sample_book.html`](tests/fixtures/sample_book.html), with a
helper script in
[`tests/fixtures/call_import_book_from_html.sh`](tests/fixtures/call_import_book_from_html.sh).

### Possible errors

Every error is returned as a structured Moodle exception (`moodle_exception`
or a subclass), never as a loose/HTML error. In JSON REST responses, the
`errorcode` field identifies the cause:

| `errorcode` | Function(s) | When |
|---|---|---|
| `invalidcourse` | all | `courseid` does not match an existing course. |
| `noscormmodule` | SCORM | `mod_scorm` is uninstalled or disabled on the site. |
| `nobookmodule` | Book | `mod_book` is uninstalled or disabled on the site. |
| `invalidscormsource` | SCORM | Neither `url` nor `draftitemid` was given, or both were given together. |
| `invaliddraftfile` | SCORM | The given `draftitemid` does not point to a draft area holding exactly one file. |
| `invalidscormurl` | SCORM | The `url` does not use HTTPS with the exact host `scormmaker.com.br`. |
| `packagetoolarge` | SCORM | The package (downloaded or uploaded) is larger than the course upload limit — the same as the activity form. The download stops as soon as it goes over the limit. |
| `scormdownloaderror` | SCORM | The ZIP could not be downloaded from the authorised `url` (network, HTTP status other than 200, redirect, or blocked by Moodle's cURL security — see "Security notes"). |
| `invalidzip` | SCORM | The downloaded/uploaded file is not a valid ZIP. |
| `nomanifest` | SCORM | The ZIP has no `imsmanifest.xml` at its root (manifests inside subfolders do not count). |
| `chapterimporterror` | Book | A chapter could not be saved to the database. |
| `required_capability_exception` (core) | all | The token user does not have `moodle/course:manageactivities` in the given course. |
| `invalid_parameter_exception` (core) | all | A required parameter is missing or has an invalid type. |

## Capabilities

| Capability | What it allows | Default roles |
|---|---|---|
| `moodle/course:manageactivities` (Moodle core, reused — not defined by this plugin) | Required, in the target course context, to call either function | Teacher, Manager |

This plugin defines no capability of its own.

## Security notes

- The SCORM function accepts a remote URL only when it uses HTTPS
  and has exactly the host `scormmaker.com.br`. HTTP, subdomains, look-alike
  hosts, embedded credentials and ports other than the default HTTPS port
  are rejected before any network request.
- The download uses Moodle's cURL wrapper with redirects disabled, so a
  response from `scormmaker.com.br` cannot make the Moodle server fetch a
  file from another host. Moodle's cURL security protection against blocked
  addresses stays active.
- The package size follows the course upload limit (the same as the activity
  form). For downloads, `CURLOPT_MAXFILESIZE` and a progress callback stop
  the transfer as soon as it goes over the limit; a draft file is checked
  before it is copied. Temporary files live in a request directory
  (`make_request_directory()`), which Moodle removes at the end of the
  request.
- The `draftitemid` parameter is a local alternative: it uses a file
  uploaded to the user's own draft area and makes no remote download.
- The Book description and the chapter HTML go through Moodle's
  `clean_text()` with `FORMAT_HTML` before being stored.
- Both functions require a web service token of an authenticated user
  (`loginrequired => true`) and are declared with `restrictedusers => 1`, so
  an administrator must explicitly authorise each user who may call them.
  Sites exposing these functions should restrict which users/roles can be
  authorised on the **Scorm Maker Import** service.

## Privacy

This plugin stores no personal data of its own (`null_provider`). The
activities it creates (SCORM instances, Book instances and chapters) are
ordinary `mod_scorm`/`mod_book` content,
already covered by those modules' privacy providers.

## Support / License

Report issues at
<https://github.com/grilo-cricri/moodle-local_scorm_maker_import/issues>.

Licensed under the GNU GPL v3 or later — full text in [`LICENSE`](LICENSE).
`pix/icon.svg` is original ScormMaker.com.br artwork, licensed under the
same terms.
