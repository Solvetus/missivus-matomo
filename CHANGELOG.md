# Changelog

All notable changes to Missivus for Matomo are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-09-13

First release of the Matomo 6 line. Functionally identical to 0.1.6; the version number
marks the platform, not new behaviour.

### Compatibility

| Matomo version | Missivus release line | Branch | PHP |
| --- | --- | --- | --- |
| Matomo 5.x | 0.1.x | `main` | 7.2.5 or later |
| Matomo 6.x | 1.x | `6.x-dev` | 8.1 or later |

### Fixed

- **The `PIWIK_TEST_MODE` guard in `GraphTransport::send()` made the entire unit suite pass
  vacuously under Matomo's own test runner.** Matomo's `tests/PHPUnit/bootstrap.php` defines that
  constant unconditionally, and the guard checked it before the enabled setting — so under
  `./console tests:run` every send short-circuited to the PHPMailer fallback before the fake Graph
  endpoint the tests inject was ever reached. Caught by the first CI run on this line (all 8 matrix
  cells, 5 errors + 5 failures in `GraphTransportTest`); the standalone runner (`tests/run.php`)
  never defined the constant, which is why it stayed green locally and in every prior release. The
  guard duplicated a short-circuit Matomo's own stock transport already performs
  (`core/Mail/Transport.php:106-113`) and served no purpose once accounted for, so it is removed
  outright — the transport is now decided solely by the enabled setting and the fallback rule.
  `tests/run.php` now defines `PIWIK_TEST_MODE` itself so this class of bug reproduces locally, and
  a regression test guards against it returning.

### Changed

- `plugin.json`: `"matomo": ">=6.0.0-b1,<7.0.0-b1"`, `"php": ">=8.1.0"`. Matomo 5 stays
  on the 0.1.x line (`main`); Matomo 6 is the 1.x line (`6.x-dev`).

### Added

- GitHub Actions plugin tests on Matomo 6 (PHP 8.1 and 8.5; MySQL 8.0 and MariaDB 10.6).
- Compatibility table in the README, installation guide and FAQ.

## [0.1.6] — 2026-09-13

### Fixed

- **The `PIWIK_TEST_MODE` guard in `GraphTransport::send()` made the entire unit suite pass
  vacuously under Matomo's own test runner.** Matomo's `tests/PHPUnit/bootstrap.php` defines that
  constant unconditionally, and the guard checked it before the enabled setting — so under
  `./console tests:run` every send short-circuited to the PHPMailer fallback before the fake Graph
  endpoint the tests inject was ever reached. Caught by the first CI run on the Matomo 6 line (all
  8 matrix cells, 5 errors + 5 failures in `GraphTransportTest`); the standalone runner
  (`tests/run.php`) never defined the constant, which is why it stayed green locally and in every
  prior release. The guard duplicated a short-circuit Matomo's own stock transport already
  performs (`core/Mail/Transport.php:106-113`) and served no purpose once accounted for, so it is
  removed outright — the transport is now decided solely by the enabled setting and the fallback
  rule. `tests/run.php` now defines `PIWIK_TEST_MODE` itself so this class of bug reproduces
  locally, and a regression test guards against it returning.

### Changed

- Compatibility table in the README, installation guide and FAQ (Matomo 5 → 0.1.x on `main`,
  Matomo 6 → 1.x on `6.x-dev`).

## [0.1.5] — 2026-08-19

### Fixed

- docs: correct SMTP retirement timeline; absolute marketplace links; contact addresses; stale
  version strings.

## [0.1.4] — 2026-08-18

### Security

- **Endpoint override URLs are no longer repeated back into errors, logs, or the test-email
  response.** `Endpoint::normalise()` refused an unsafe `graph_base_url` / `login_base_url`
  correctly, but ended its message with the rejected value verbatim — and that message was then
  logged by `GraphTransport` and returned to the superuser by `API.sendTestEmail` with no final
  redaction pass. A value set through `MISSIVUS_GRAPH_BASE_URL` or `MISSIVUS_LOGIN_BASE_URL` that
  carried credentials (`https://user:password@host`) or a token (`?access_token=…`) could therefore
  reach a Matomo log file and the settings page. Fixed in three independent layers:
  - `Endpoint` builds every message from scheme, host, port and path only — userinfo, query string
    and fragment are never assembled into a message at all — and reports a value it cannot parse,
    or a host name it cannot accept, by reason rather than by value.
  - `Redactor` gained URL patterns: credentials inside a URL, any `name=value` on the new
    `Redactor::SECRET_PARAMS` list (`access_token`, `client_secret`, `code`, `password`,
    `signature`, `sas`, …), and URL fragments. An ordinary mailbox address is deliberately left
    alone.
  - `GraphTransport::redact()` is now the single final pass on every string the transport logs or
    rethrows, and `API.sendTestEmail` applies the same pass to the message it returns.

  Reported by [@textagroup](https://github.com/textagroup) (Kirk Mayo) —
  [#1](https://github.com/Solvetus/missivus-matomo/issues/1). Thank you. Written up as finding 12 in
  [docs/SECURITY.md](docs/SECURITY.md).

### Added

- Eleven tests covering the above: URLs with userinfo, with `access_token` / `client_secret` /
  `code` query parameters, and with fragments, asserted absent from exception messages, from the
  log, and from the string the API method returns. 75 tests in total, all passing.

## [0.1.3] — 2026-08-17

### Changed

- The test-email success message now reads "Test email sent to %s. Microsoft accepted it — check
  the inbox to confirm delivery.", clearer about what "sent" does and does not guarantee.
- `plugin.json`: `homepage` now points to `https://missivus.com` and `support.docs` to
  `https://missivus.com/matomo/`, moving both off the GitHub repository now that the plugin has a
  dedicated site. `license` stays `GPL-3.0+`.

### Added

- `screenshots/Settings_page.png`, the plugin's settings page for the Marketplace listing.

## [0.1.2] — 2026-08-17

Marketplace readiness and public-repository hygiene. No functional change to the plugin itself.

### Changed

- `plugin.json`: the `license` string is now `GPL-3.0+`, which is the spelling the Marketplace
  accepts (`GPL-3.0-or-later` is correct SPDX but is not on their list); added
  `"category": "integration"`, a `docs` support link, and an `archive.exclude` list so the
  Marketplace-built zip leaves out `/dist`, `/tools`, `/PLAN.md` and `/docs/BRIEF.md`.
- `README.md` is restructured around a `## Description` section, because the Marketplace renders
  everything between that heading and the next `##` as the plugin's page: what it does, why
  application permissions beat a delegated login, what you need, and where the guide is.
- The installation guide moved from `docs/INSTALL.md` to **`docs/index.md`**, which is the path the
  Marketplace turns into the plugin page's Documentation tab. Every internal reference was updated.

### Added

- **`docs/faq.md`** — twelve real questions, and the path they came from: mailbox licensing, why not
  SMTP, secret versus certificate, rotating a secret, attachments over 3 MB, why `Mail.ReadWrite`,
  why the access policy is not optional, the greyed-out test button, the Docker upload flag, what
  happens if you install and do nothing, the Microsoft error codes, and where to report a problem.
  It becomes the FAQ tab.
- **`screenshots/README.md`** — the exact captures the Marketplace page needs, with target
  filenames (the file name becomes the caption), the 880×480 `_cover.png` rule, and a
  before-committing checklist, since a screenshot of a filled-in settings page is a screenshot of
  someone's tenant.

### Removed

- Deployment topology is out of the public tree: host names, orchestration, the private network, and
  local vault paths are gone from `docs/BRIEF.md` and `PLAN.md`, replaced with generic wording. That
  detail belongs in the operator's own runbook. (Note that it remains in the git history of releases
  before this one.)

## [0.1.1] — 2026-08-17

First round of fixes after the first live deployment, plus a security review
([docs/SECURITY.md](docs/SECURITY.md)).

### Fixed

- **The test-email button no longer lies about being ready.** The test sends with the *saved*
  settings, so filling the form in and clicking **Send test email** used to report that Missivus was
  switched off. The button now stays disabled until the stored configuration can actually send, says
  which part is missing, and re-checks itself when a settings save completes — no page reload.
- **The result box is readable in the dark theme.** It used hand-rolled pastel backgrounds that set a
  background colour but not a foreground one, so in dark mode the Graph error — the one thing worth
  reading — was light text on a light panel. It now uses Matomo's own `notification` /
  `notification-success` / `notification-error` / `notification-info` classes.
- A network failure while uploading a large attachment escaped as an unhandled exception, skipping
  the "fall back to Matomo's own transport" setting, and could write the pre-authenticated upload URL
  to the log. It is now a normal, redacted transport failure.

### Security

- A `graph_base_url` / `login_base_url` override is now refused unless it is a bare `https` origin,
  so a mis-set — or hostile — value can no longer send a client secret or a bearer token in clear
  text to another host.
- `Redactor` now also blanks `uploadUrl` values, which are pre-authenticated and therefore credentials.
- Every setting is validated: tenant and client IDs must be a GUID (or a tenant domain), the sender
  mailbox must be an email address, the certificate path must be absolute and readable, and a secret
  carrying whitespace or control characters — always a copy-paste accident — is rejected on entry.
- `Missivus.sendTestEmail` validates the recipient address and refuses anything but an HTTP POST, and
  the recipient now travels in the request body rather than the query string.
- Full audit of secret handling, authentication, CSRF and error-body disclosure written up in
  [docs/SECURITY.md](docs/SECURITY.md), including the three risks that were accepted rather than
  eliminated, with the reasoning.

### Added

- `Missivus.getTestEmailStatus` — superuser-only; reports whether the saved settings can send, and
  why not when they cannot. This is what gates the button.
- `docs/index.md` Part 6 gains **Upload via the Matomo UI (Docker or locked-down installs)**: why
  `enable_plugin_upload` is off by default, the console command to turn it on (with the
  `docker exec` form), the upload and activate steps, and the command to close it again afterwards.
- `docs/SECURITY.md` and this changelog.

### Tests

- 60 unit tests, all passing (was 50): the new `EndpointTest`, plus coverage for the upload-URL leak
  and its redaction.

## [0.1.0] — 2026-08-16

Initial release.

- Replaces Matomo's PHPMailer transport with Microsoft Graph `sendMail`, using OAuth2 client
  credentials and the `Mail.Send` application permission, through the DI seam from
  [matomo-org/matomo#14041](https://github.com/matomo-org/matomo/pull/14041).
- Client secret and certificate authentication (PS256, with an RS256 escape hatch), token caching
  with a five-minute refresh margin, and one retry on a 401.
- HTML and plaintext bodies, multiple recipients, BCC, reply-to, inline images, and attachments —
  automatically switching to the draft → upload session → send path above 3 MB so scheduled-report
  PDFs never fail on size.
- Settings page with a config-file and environment-variable override tier, write-only secrets, and a
  test-email button.
- Optional fallback to Matomo's own transport, off by default; nothing is ever swallowed.

[1.0.0]: https://github.com/Solvetus/missivus-matomo/releases/tag/v1.0.0
[0.1.6]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.6
[0.1.5]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.5
[0.1.4]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.4
[0.1.3]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.3
[0.1.2]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.2
[0.1.1]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.1
[0.1.0]: https://github.com/Solvetus/missivus-matomo/releases/tag/v0.1.0
