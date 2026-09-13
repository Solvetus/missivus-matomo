# Frequently asked questions

## Does the shared mailbox need a Microsoft 365 licence?

No. An Exchange Online **shared mailbox** is free, up to 50 GB, and needs no licence assigned to it —
which is the point. Missivus authenticates as an *application*, not as the mailbox, so nobody has to
sign in as it and nothing has to be paid for it. (A licence is only required if you want to convert
it to a user mailbox, put it under litigation hold, or give it an archive.)

## Why not just use SMTP? Matomo already supports it.

Because it is on borrowed time, and because of what it costs you today. Microsoft is retiring
basic-authentication SMTP AUTH for Microsoft 365: it is disabled by default for existing tenants
from the end of December 2026, unavailable to new tenants from 2027, and the final removal date
will be announced in 2027 (see Microsoft's
[announcement](https://techcommunity.microsoft.com/blog/exchange/exchange-online-to-retire-basic-auth-for-client-submission-smtp-auth/4114750)
and [updated timeline](https://techcommunity.microsoft.com/blog/exchange/updated-exchange-online-smtp-auth-basic-authentication-deprecation-timeline/4489835)).
What remains after that is SMTP AUTH with OAuth2, which Matomo's PHPMailer path does not speak. And
even while it still works, SMTP means a licensed user account whose password sits on your server as
a shared credential, with no way to scope what it can do.

Graph with application permissions has neither problem: there is no password, no user, no licence,
and the credential is scoped by Exchange to one mailbox.

## Client secret or certificate — which should I use?

Start with a **client secret**. It is two clicks in Entra, there is nothing to manage on the
filesystem, and it is the documented route throughout the guide.

A **certificate** is stronger, because the secret never travels in a request body: Missivus signs a
short-lived assertion with the private key instead. Use it if your security policy asks for it, or if
you would rather rotate a file on disk than a value in a database. The trade is that you now have a
PEM file to keep readable by the web server and nobody else, and to replace before it expires.

Both are fully implemented and tested. You can switch between them at any time from the settings
page.

## How do I rotate the client secret?

Entra secrets expire — 24 months at most, and 6 months is the default. Rotation is deliberately
boring:

1. In your app registration, **Certificates & secrets → New client secret**. The old one keeps
   working until it expires, so there is no outage window.
2. Copy the new secret **Value** (not the Secret ID).
3. Paste it into **Administration → System → General settings → Missivus → Client secret**, and
   click **Save**. If you keep credentials in `config.ini.php` or an environment variable instead,
   change it there and reload PHP.
4. Press **Send test email** to confirm.
5. Delete the old secret in Entra.

Missivus caches the *access token*, not the secret, for at most 55 minutes — so the changeover is
immediate for new tokens and nothing needs restarting.

## What happens with attachments over 3 MB?

They still send. Graph's inline attachment limit is 3 MB, and the whole `sendMail` request is bounded
at 4 MB, so above that ceiling Missivus automatically switches to Graph's large-file path: it creates
a draft, uploads each large file in chunks through an upload session, then sends the draft. The
decision is made per message and is total-aware, so several medium files that add up also take the
safe path.

There is no setting for this and no way to configure it wrongly — scheduled-report PDFs must never
fail on size.

## Why does the guide ask for Mail.ReadWrite as well as Mail.Send?

Only for that large-attachment path. Creating a draft message and opening an attachment upload
session are not covered by `Mail.Send`; Microsoft requires `Mail.ReadWrite` for them.

If you never send attachments over 3 MB you can grant `Mail.Send` alone. If you do, and
`Mail.ReadWrite` is missing, the failure is loud and names the permission rather than being
mysterious.

Both permissions are scoped by the same application access policy, so `Mail.ReadWrite` grants nothing
outside the one shared mailbox.

## Is the application access policy really necessary?

Yes — treat it as part of the installation, not as hardening you might do later.

Without it, `Mail.Send` as an application permission means the app can send as **any mailbox in your
tenant**. The policy narrows it to one. That single step is what turns "an app that can impersonate
everybody" into "an app that can send as noreply@". The guide gives you the exact commands, including
`Test-ApplicationAccessPolicy` to prove that another mailbox comes back **Denied** before you go any
further.

Note that Exchange scopes a policy to a *group*, not a mailbox, so the guide has you create a
security group whose only member is the shared mailbox. Nobody ever sends to that group; it exists so
the policy has something to point at.

## The test email button is greyed out. What now?

The test sends with the settings that are **saved**, not with what is currently typed on screen, so
the button stays disabled until the stored configuration is complete and Missivus is switched on. The
note under the button says which of those is missing.

Fill in the fields, tick **Send email through Microsoft Graph**, and click **Save**. The button
enables itself a moment later — no page reload needed.

## My Matomo runs in Docker and I cannot drop files into `plugins/`. Can I upload the zip?

Yes, with one temporary setting change. Matomo ships `enable_plugin_upload` **off** because it lets
any superuser upload a zip that Matomo then unpacks and executes — arbitrary PHP running as your web
server user.

The guide's [Upload via the Matomo UI](https://github.com/Solvetus/missivus-matomo/blob/main/docs/index.md#upload-via-the-matomo-ui-docker-or-locked-down-installs)
section walks through turning it on from inside the container, uploading and activating the plugin,
and — importantly — turning it back off again afterwards. Leaving it on is a standing
remote-code-execution path for anyone who ever gets a superuser session.

## Will Missivus break my email if I install it and do nothing?

No. It ships switched off. Activating the plugin changes nothing at all: `Piwik\Mail` keeps using
whatever transport Matomo was already using until you tick **Send email through Microsoft Graph**.
Deactivating the plugin restores the stock transport with no cleanup.

The optional **fall back to Matomo's own mail settings** switch is also off by default, deliberately:
a failure you can see beats an email that quietly goes nowhere. Either way, every Graph failure is
written to Matomo's log at error level.

## I got an error code from Microsoft. What does it mean?

The common ones, in full in the [installation guide's troubleshooting table](https://github.com/Solvetus/missivus-matomo/blob/main/docs/index.md#when-it-does-not-work):

| Code | Usually means |
| --- | --- |
| `AADSTS7000215` | Wrong client secret — often the Secret **ID** was copied instead of the **Value** |
| `AADSTS900023` | Wrong Directory (tenant) ID |
| `AADSTS700027` | The certificate Matomo is using is not the one uploaded to Entra |
| `ErrorAccessDenied` | The application access policy does not cover this mailbox, or admin consent was never granted |
| `ErrorAccessDenied` only on big attachments | `Mail.ReadWrite` is missing |
| `MailboxNotEnabledForRESTAPI` | The sender address is not a real Exchange Online mailbox |
| "mailbox is either inactive, soft-deleted…" | The mailbox has not finished provisioning — wait and retry |

Secrets are redacted before anything is logged or shown, so you can paste these errors into an issue
safely — but do check them over first.

## Does it work with Matomo for WordPress, or with other Matomo plugins that send email?

Any email that goes through `Piwik\Mail` goes through Missivus — that includes password resets,
scheduled report PDFs, alerts, and third-party plugins that use Matomo's mail layer properly. A
plugin that opens its own SMTP connection instead is untouched by any of this.

Missivus for Matomo has not been tested running under **Matomo for WordPress** specifically. If you
run Matomo on WordPress, the supported route is the sibling **missivus-wordpress** plugin, which
vendors the same Microsoft Graph transport for WordPress's own mail layer directly, rather than
this Matomo plugin running inside a WordPress-hosted Matomo.

The WordPress sibling has shipped — along with **missivus-nextcloud** and **missivus-ghost**, which
send Nextcloud's and Ghost's own outbound email the same way. See
[missivus.com](https://missivus.com) for all four.

## Which version do I install — Matomo 5 or Matomo 6?

| Matomo version | Missivus release line | Branch | PHP |
| --- | --- | --- | --- |
| Matomo 5.x | 0.1.x | `main` | 7.2.5 or later |
| Matomo 6.x | 1.x | `6.x-dev` | 8.1 or later |

The Marketplace offers the right line automatically, based on the Matomo version you have
installed. A manual install from GitHub takes the latest tag on the branch matching your Matomo.

## Where do I report a bug, or a security problem?

Bugs and feature requests: the
[issue tracker](https://github.com/Solvetus/missivus-matomo/issues).

Security problems: email <security@missivus.com> instead, and please give us a chance to fix it
before it becomes public.
[docs/SECURITY.md](https://github.com/Solvetus/missivus-matomo/blob/main/docs/SECURITY.md)
documents what has already been reviewed.
