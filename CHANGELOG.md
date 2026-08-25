# Changelog

# v0.0.6 (draft)

# v0.0.5 (2026-08-25)
- Switching the line filter clears the thread immediately and shows "Loading…" until the new view arrives
- Messages with no line attribution use the same line-color treatment in gray (no more default blue on old outbound, which broke inbound/outbound pairing and clashed with the palette); UI chrome (send button, active All chip, new-message pill) moved off blue so palette colors mean "line" only

# v0.0.4 (2026-08-25)
- Inbound tagging: resolve the SMS provider from the webhook request the way core does (`provider_id=N`, `provider=<key>`, or `mailing_id=N`) — previously only `provider_id` worked, so by-name webhooks got no preamble and no line attribution
- Custom-field availability now requires the SMS_Chat group and fields to be ACTIVE (API4 only publishes active fields); sending and tagging degrade gracefully instead of failing with "Invalid field"

# v0.0.3 (2026-08-24)
- Extension key renamed `smschat` → `sms_chat`; settings are now `sms_chat_*`, routes `civicrm/contact/view/sms_chat` and `civicrm/admin/sms_chat`; env var names unchanged (`CIVICRM_SMSCHAT_*`)
- Line filter is applied server-side, so a filtered view pages exactly like the full thread; new "Unknown" chip for messages with no line attribution

# v0.0.2 (2026-08-24)
- Commit the built `dist/` so tags (and Packagist/composer installs) carry the UI bundle; release workflow back to plain tag-push; drop the composer-downloads pin
- README: correct PHP requirement (8.1+); document the release steps
- Remove install-specific notes from DESIGN.md

# v0.0.1 (2026-08-24)
- SMS Chat tab on the contact record: Google Messages-style thread over native SMS activities (Inbound SMS / SMS delivery), sender names, day grouping, tapback chips, 5s live polling
- API4 `SmsChat.getContext` / `getMessages` / `send`
- Line attribution: `SMS_Chat` custom fields (line/contact number), Twilio line resolver with a `sms_chat.resolvers` extension point, inbound tagging with optional details preamble
- Per-line color coding and filter; composer with line picker, 460-char counter, optimistic send with retry
- Infinite scroll back through history (pages of 50, cursored on date + id)
- Every bubble names the contact-side number it travelled to/from and the line, when known
- Safety: allowed-recipients list, non-Production lockdown, test mode; all settings env-loadable (`CIVICRM_SMSCHAT_*`) with a settings form (Administer › System Settings › SMS Chat)
- Vue 3 custom-element UI built as an IIFE; `dist/` built by the release workflow and fetched by composer installs via composer-downloads-plugin
