# Changelog

# v0.0.2 (draft)

# v0.0.1 (2026-08-24)
- SMS Chat tab on the contact record: Google Messages-style thread over native SMS activities (Inbound SMS / SMS delivery), sender names, day grouping, tapback chips, 5s live polling
- API4 `SmsChat.getContext` / `getMessages` / `send`
- Line attribution: `SMS_Chat` custom fields (line/contact number), Twilio line resolver with a `smschat.resolvers` extension point, inbound tagging with optional details preamble
- Per-line color coding and filter; composer with line picker, 460-char counter, optimistic send with retry
- Safety: allowed-recipients list, non-Production lockdown, test mode; all settings env-loadable (`CIVICRM_SMSCHAT_*`) with a settings form (Administer › System Settings › SMS Chat)
- Vue 3 custom-element UI built as an IIFE; `dist/` built by the release workflow and fetched by composer installs via composer-downloads-plugin
