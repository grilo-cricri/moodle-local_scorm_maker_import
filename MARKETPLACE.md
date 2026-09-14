# Moodle Marketplace metadata — Scorm Maker Import

This file contains the copy and technical metadata prepared for the initial
Moodle Marketplace listing. The public repository, tracker, documentation,
screenshots, and maintainer account fields must be completed by the publisher
before submission; this repository currently has no public Git remote.

## Listing fields

| Field | Value |
|---|---|
| Display name | Scorm Maker Import |
| Component | `local_scorm_maker_import` |
| Plugin type | Local plugin |
| Release | 1.2.3 |
| Build | `2026091400` |
| Maturity | Stable |
| License | GNU GPL v3 or later; `pix/icon.svg` is original ScormMaker.com.br artwork explicitly licensed under GPL v3 or later |
| Business model | Select Free or Paid in the Marketplace submission form |

### Short description

Provides authenticated Moodle web services for creating SCORM and Book
activities from external content.

### Full description

Scorm Maker Import is a web-service-only Moodle local plugin for integrations
that need to create course content programmatically. It exposes authenticated
web services that can create a SCORM activity from a package hosted at the
exact HTTPS origin `scormmaker.com.br`, or from a ZIP uploaded to the calling
user's Moodle draft area. It can also create a Book activity from an HTML
document, creating one chapter for each `<h1>` section.

Both services require an authenticated Moodle web-service user with the core
`moodle/course:manageactivities` capability in the destination course. The
plugin has no separate user interface and does not store personal data of its
own; the activities it creates remain managed by Moodle's `mod_scorm` and
`mod_book` privacy providers.

## Features

- Create a SCORM activity from a validated ZIP package.
- Accept a remote SCORM package only from exact HTTPS host `scormmaker.com.br`.
- Reject HTTP, subdomains, host-confusion URLs, embedded credentials,
  non-standard ports, and redirects for remote SCORM downloads.
- Accept a SCORM ZIP through Moodle's standard web-service draft upload flow.
- Validate that `imsmanifest.xml` is present at the ZIP root.
- Create a Book activity from HTML with one chapter per `<h1>` section.
- Sanitize Book descriptions and chapter HTML with Moodle's `clean_text()`.
- Re-check course capability inside each external function.

## Requirements and compatibility

- Minimum Moodle requirement: 4.0 (`$plugin->requires = 2022112800`).
- Declared supported Moodle branches: 4.5 through 5.2
  (`$plugin->supported = [405, 502]`).
- Required Moodle modules: `mod_scorm` and `mod_book`.
- Required PHP extension: `ext-zip`, already required by the Moodle SCORM
  workflow.

The complete plugin PHPUnit suite was run successfully on Moodle 4.5.14 and
Moodle 5.2.3 with PHP 8.3 and PostgreSQL 16.

## Installation and setup

1. Install the ZIP as `local/scorm_maker_import`.
2. Visit Site administration → Notifications, or run
   `php admin/cli/upgrade.php --non-interactive`.
3. Enable Moodle web services and the REST protocol.
4. Enable the **Scorm Maker Import** external service and restrict it to the
   users who should call the functions.
5. Create a web-service token for an authorised user.
6. Ensure `mod_scorm` and/or `mod_book` is enabled according to the endpoint
   being used.

## External functions

| Function | Purpose |
|---|---|
| `local_scorm_maker_import_import_scorm_from_url` | Creates a SCORM activity from an authorised URL or a draft-uploaded ZIP. |
| `local_scorm_maker_import_import_book_from_html` | Creates a Book activity and chapters from HTML. |

## Security and privacy notes

Remote SCORM downloads are restricted to HTTPS host `scormmaker.com.br`, with
TLS certificate verification enabled and redirects disabled. The alternative
`draftitemid` path is a local Moodle upload flow and does not download from a
remote host. The plugin requires login and the course activity-management
capability for both functions.

The plugin does not store personal data in its own tables. Course activities
and Book chapters are stored by Moodle core modules and are covered by those
modules' privacy providers.

## Release notes for 1.2.3

- Fixed missing `cmidnumber` metadata when creating SCORM and Book activities.
- Removed the Moodle 4.5/5.2 undefined-property warnings from the CI run.
- Declared the tested Moodle support range as 4.5 through 5.2.

## Publisher-supplied Marketplace fields

These values cannot be invented from the local checkout and must be filled in
before submitting the listing:

| Marketplace field | Required action |
|---|---|
| Public source repository | Publish the repository with the plugin root at repository root; use the eventual GitHub/GitLab URL. |
| Issue tracker | Provide a public issue tracker URL. |
| Documentation URL | Publish the README or a dedicated documentation page and provide its public URL. |
| Support/discussion URL | Provide a public support or discussion channel. |
| Screenshots | Provide screenshots showing the external-service setup and representative request/response flows; the plugin has no standalone UI. |
| Test SCORM package | Provide a working public ZIP URL under `https://scormmaker.com.br` for the remote-download review flow, or instruct reviewers to use the draft-upload flow. |
| Maintainer/provider details | Complete the publisher and support details in the Marketplace account. |
| Icon licensing | Confirmed: `pix/icon.svg` is original artwork created by ScormMaker.com.br in CorelDRAW and licensed under GPL v3 or later. |

## Submission package

Submit the ZIP whose root directory is `scorm_maker_import/`. Do not submit
the historical `asd_resource_import-master.zip` or `scorm_maker_importOLD.zip`
archives. For a Marketplace API `fileUrl`, the ZIP URL must be public HTTPS;
that Marketplace transport URL is separate from the plugin's runtime
restriction on SCORM package downloads.
