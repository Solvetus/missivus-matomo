<p align="center">
  <a href="https://missivus.com"><img src=".github/mark.svg" alt="Missivus" width="40" height="41"></a>
</p>

# Missivus for Matomo

## Description

**Missivus for Matomo — send Matomo email through Microsoft Graph with application permissions and a
shared mailbox. No SMTP, no user login. Free, GPLv3.**

Matomo has no email API. It sends only through PHPMailer, over SMTP or PHP's `mail()`. For Microsoft
365 that means SMTP AUTH with basic authentication — a licensed user's password stored on your
server as a shared credential, on a path Microsoft is retiring: disabled by default for existing
tenants from the end of December 2026, unavailable to new tenants from 2027, with final removal to
follow. Matomo's PHPMailer path cannot do the OAuth2 SMTP that replaces it. Sooner or later the
password resets, scheduled reports and alerts stop.

Missivus replaces Matomo's mail transport with one that posts to the Microsoft Graph API using
**OAuth2 client credentials** and the **Mail.Send application permission**, sending as one shared
mailbox you nominate. Every email Matomo produces goes out that way — there is nothing to switch
over report by report.

### Application permissions, not a delegated login

The usual Microsoft 365 mailers use *delegated* authentication: a multi-tenant app, a redirect URI,
and a human who clicks "Connect" so that mail goes out as their account. That is the wrong shape for
a server. It breaks when that person leaves, when their password changes, and when MFA policy
tightens.

| | Delegated mailers | Missivus |
| --- | --- | --- |
| Who sends | A named person's account | A shared mailbox |
| Setup | A human clicks "Connect" | Nothing to click |
| Survives an employee leaving | No | Yes |
| Mailbox licence needed | Yes | No |
| Scope | Whatever that person can reach | One mailbox, enforced by Exchange |
| Cost | Often a paid extension | Free, GPLv3 |

The scoping is what makes this safe. An **Exchange application access policy** restricts the app
registration to the single shared mailbox, so the `Mail.Send` permission cannot touch any other
mailbox in your tenant — and the install guide treats that step as first-class, with a command to
verify it actually took effect.

### What it does

- **Everything `Piwik\Mail` supports:** HTML and plaintext, multiple recipients, BCC, Reply-To, and
  attachments — including the PDFs that scheduled reports generate.
- **Large attachments never fail on size.** Files under 3 MB go inline; anything larger is uploaded
  through a Graph upload session automatically. There is no setting that can get this wrong.
- **Client secret or certificate.** A client secret is the quickest way in and is the default; a
  certificate is supported as optional hardening.
- **Secrets can stay out of the database.** Any value can come from a `[Missivus]` section of
  `config.ini.php` or from an environment variable, which then wins over the settings UI and is
  never written to the option table.
- **Nothing fails silently.** A Graph failure is logged at error level and, unless you explicitly
  turn on the fallback, raised. Nothing is swallowed.
- **A test-email button** that shows you the exact error Microsoft returned.
- **No third-party runtime dependencies.** Nothing beyond what Matomo already ships, and nothing to
  `composer install`.

### What you need

| Matomo version | Missivus release line | Branch | PHP |
| --- | --- | --- | --- |
| Matomo 5.x | 0.1.x | `main` | 7.2.5 or later |
| Matomo 6.x | 1.x | `6.x-dev` | 8.1 or later |

- PHP with the `openssl` and `json` extensions (PHP 8.1 or later on the 1.x line)
- A Microsoft 365 tenant, and an administrator who can create an app registration, grant admin
  consent, and run one Exchange Online PowerShell command
- A shared mailbox to send from. It needs **no licence**

### Getting started

Install the plugin, then follow **[the installation guide](https://github.com/Solvetus/missivus-matomo/blob/main/docs/index.md)** —
it is written for someone who has never opened Microsoft Entra, and every click is spelled out.
Budget about an hour for the Microsoft side. The
[FAQ](https://github.com/Solvetus/missivus-matomo/blob/main/docs/faq.md) answers the questions that
come up most often, and
[docs/SECURITY.md](https://github.com/Solvetus/missivus-matomo/blob/main/docs/SECURITY.md) is the
standing security review.

Missivus is free and open source (GPLv3). If you would rather not do the Entra and Exchange setup
yourself, [Solvetus](https://solvetus.com) offers paid installation and support.

## Install

1. **Upload and unzip.** Put `Missivus-<version>.zip` on your server and unzip it inside Matomo's
   `plugins/` directory, so the plugin ends up at `plugins/Missivus/` with `plugin.json` directly
   inside it. Make sure the files belong to whichever user your web server runs as (often
   `www-data`).

   ```bash
   cd /path/to/matomo/plugins
   unzip Missivus-<version>.zip
   chown -R www-data:www-data Missivus
   ```

   You can also upload the zip through **Administration → Platform → Plugins → Install a new
   plugin**, if your Matomo allows plugin uploads —
   [the guide explains how to enable that safely](https://github.com/Solvetus/missivus-matomo/blob/main/docs/index.md#upload-via-the-matomo-ui-docker-or-locked-down-installs).

2. **Activate it.** Either tick it under **Administration → Plugins**, or from the command line:

   ```bash
   ./console plugin:activate Missivus
   ```

   Activating changes nothing on its own — Missivus starts switched off and Matomo keeps using
   whatever mail settings it already had.

3. **Configure it.** Go to **Administration → System → General settings** and scroll to
   **Missivus**. Fill in:

   - **Directory (tenant) ID** and **Application (client) ID** — both from your app registration's
     Overview page in Microsoft Entra
   - **Authentication method** — leave it on **Client secret**
   - **Client secret** — the secret **Value** from Certificates & secrets
   - **Sender mailbox** — the shared mailbox Matomo should send from
   - Tick **Send email through Microsoft Graph**

   Click **Save**, then press **Send test email**. The button stays disabled until the saved
   settings are complete, and tells you what is missing. If the send fails, the exact error
   Microsoft returned is shown on the page.

That is the whole installation. **You do not need to edit `config.ini.php`.** The
[`[Missivus]` overrides](https://github.com/Solvetus/missivus-matomo/blob/main/README.md#configuration)
below are entirely optional — they exist for people who prefer credentials to live in a file rather
than the database.

The one Matomo setting worth adding by hand is the From address, under
**Administration → System → General settings → Email server settings**, or in `config.ini.php`:

```ini
[General]
noreply_email_address = "noreply@example.com"
```

Set it to the same shared mailbox. Application-only sending cannot use any other From address, so
Missivus forces it either way — setting it here just keeps a warning out of your log.

### Getting the Microsoft side ready

The plugin needs an app registration before any of the above will work. Full step-by-step
instructions are in
**[docs/index.md](https://github.com/Solvetus/missivus-matomo/blob/main/docs/index.md)**. In
outline:

1. Create an app registration in Microsoft Entra.
2. Grant it the **Mail.Send** application permission — plus **Mail.ReadWrite** if you send
   attachments over 3 MB — and grant admin consent.
3. Add a **client secret** (or a certificate, if you would rather — see the guide). Name it
   `missivus-matomo-<your Matomo hostname>`, so it stays identifiable among the other app
   registrations in your tenant.
4. Create the shared mailbox you want Matomo to send from. Make it a company-wide no-reply address
   — `noreply@yourcompany.com`, display name your company name — rather than a Matomo-specific one.
   Other tools can send from the same mailbox later: each gets its own app registration scoped by
   the same kind of policy, so credentials stay separate while the sender address stays consistent.
5. Create an **application access policy** in Exchange Online scoping the app to that one mailbox.

## Configuration

Everything is set in **Administration → System → General settings → Missivus**. Nothing below is
required.

**Optionally**, credentials can live in `config/config.ini.php` instead. This suits a server you
deploy by pulling code, where you would rather configuration travelled with your files than sat in
the database:

```ini
[Missivus]
tenant_id = "00000000-0000-0000-0000-000000000000"
client_id = "00000000-0000-0000-0000-000000000000"
auth_method = "secret"
client_secret = "the Value from Certificates & secrets"
sender_mailbox = "noreply@example.com"
```

A value set there appears in the settings page as *set in config file*, cannot be edited from the
UI, and is never copied into the database. Each key can also be supplied as an environment variable
— `MISSIVUS_TENANT_ID`, `MISSIVUS_CLIENT_SECRET`, and so on — which takes precedence over both.

If you use a certificate instead of a secret, swap those two lines for
`auth_method = "certificate"` and `certificate_path = "/etc/matomo/secrets/missivus.pem"`.

## Security

[docs/SECURITY.md](https://github.com/Solvetus/missivus-matomo/blob/main/docs/SECURITY.md) is the
standing security review: how secrets are kept out of logs, API responses and page source, how the
test-email API method is authenticated, and the risks that were accepted rather than eliminated,
with the reasoning for each.

Found a vulnerability? Email <security@missivus.com> rather than opening a public issue.

## Development

```
php tests/run.php                       # the unit suite, no Composer or PHPUnit needed
./console tests:run Missivus            # the same tests under Matomo's PHPUnit
./tools/build-zip.sh                    # builds dist/Missivus-<version>.zip for manual install
```

[PLAN.md](https://github.com/Solvetus/missivus-matomo/blob/main/PLAN.md) documents the architecture, the DI seam, and — usefully before a Matomo
upgrade — the exact list of Matomo internals this plugin depends on, with class, method and the
version they were verified against.

The Microsoft Graph transport in [`libs/Solvetus/Missivus/`](libs/Solvetus/Missivus/) is deliberately
free of any Matomo symbol: it depends only on `openssl`, `json`, a two-method HTTP interface and a
three-method cache interface, so the WordPress sibling plugin can vendor it unchanged.

Release history is in [CHANGELOG.md](https://github.com/Solvetus/missivus-matomo/blob/main/CHANGELOG.md).

## Licence

GPLv3 or later. See [LICENSE](https://github.com/Solvetus/missivus-matomo/blob/main/LICENSE).

## Support

Missivus is free and open source. If you would rather not do the Entra and Exchange setup yourself,
[Solvetus](https://solvetus.com) offers paid installation and support.
