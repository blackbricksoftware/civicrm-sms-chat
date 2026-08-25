# SMS Chat for CiviCRM

A Google Messages-style, real-time SMS conversation tab on the contact
record. Click a contact, open **SMS Chat**, see the whole thread (inbound and
outbound, across all of your SMS lines), type, send, and watch replies appear
without refreshing.

Built on CiviCRM's native SMS machinery — messages are ordinary `Inbound
SMS` / `SMS delivery` activities, so everything remains visible in the
activity list, reports, and SearchKit. No parallel message store.

- **Provider-agnostic** with Twilio support included; other providers plug in
  via a small line-resolver extension point.
- **Multi-line aware**: each active SMS provider is a "line" (named by its
  provider title); conversations can be filtered per line or viewed merged.
- **No new permission model**: if you can view the contact's activities you
  can read the thread; if you hold `send SMS` you can send.
- **Safety-conscious**: non-production environments deny sending except to an
  allowlist (settings, pinnable via `$civicrm_setting`).

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/),
licensed under [AGPL-3.0](LICENSE.txt).

## Status

Early development. See [DESIGN.md](DESIGN.md) for the full architecture,
research notes, and build plan.

## Requirements

- CiviCRM 6.10+ (PHP 8.1+)
- A configured SMS provider (e.g. org.civicrm.sms.twilio) for sending;
  the tab works read-only without one

## Configuration

Administer › System Settings › SMS Chat. Every setting can also be pinned by
an environment variable (env wins over the database, and the form shows the
field read-only):

| Env var | Setting | Notes |
|---|---|---|
| `CIVICRM_SMSCHAT_ENVIRONMENT_LOCKDOWN` | `sms_chat_environment_lockdown` | Default on. Outside a `Production` CiviCRM environment, only allowed recipients can be texted (deny-all when the list is empty). |
| `CIVICRM_SMSCHAT_ALLOWED_RECIPIENTS` | `sms_chat_allowed_recipients` | Comma-separated E.164 numbers or prefixes. Always enforced when set. |
| `CIVICRM_SMSCHAT_TEST_MODE` | `sms_chat_test_mode` | Record sends as activities but never hand them to the provider. |
| `CIVICRM_SMSCHAT_DETAILS_PREAMBLE` | `sms_chat_details_preamble` | Default on. Prepend "From/To" to inbound activity details. |

Set the CiviCRM environment (Administer › System Settings › Misc, or
`CIVICRM_ENVIRONMENT`) to anything but `Production` on dev/staging copies of
production data — that is what arms the lockdown.

## Releasing

1. `cd ui && npm run build` and commit `dist/` (it is committed so release
   tags — and therefore Packagist/composer installs — carry the bundle).
2. Bump `<version>` in `info.xml`, note it in `CHANGELOG.md`, commit.
3. Tag `vX.Y.Z` and push. The workflow builds the release zip.

## Development

The UI lives in `ui/` (Vue 3 + Vite) and builds to `dist/smschat.js` (an
IIFE — CiviCRM's AJAX tab pipeline cannot load ES modules). `dist/` is
committed; rebuild and commit it with any UI change.

    cd ui && npm install && npm run build

Extensions live outside the web root; static assets are served from the
published copy. With the extension installed through composer
(`civicrm/civicrm-asset-plugin`), `composer civicrm:publish` copies `dist/`
to `web/assets/sms_chat/`. While iterating from a plain checkout, copy `dist/`
there yourself after each build.
