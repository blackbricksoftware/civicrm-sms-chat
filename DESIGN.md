# SMS Chat — CiviCRM Extension Design

Real-time SMS conversation view in a tab on the contact record. Google
Messages-style: click a contact, open the SMS Chat tab, see the full thread,
type, send, and watch replies appear without refreshing.

**This is a GENERIC extension for public release into the CiviCRM ecosystem.**
Nothing install-specific goes in: no assumptions about which providers exist,
how many lines there are, or what other extensions are present. Provider
specifics live behind small interfaces with a Twilio implementation shipped
(Twilio being the dominant CiviCRM SMS provider) and an extension point for
others. Install-specific concerns (which lines exist, allowlists, test
contacts) live in each install's own private repo, never here. Config is proper CiviCRM settings (which installs can pin via
`$civicrm_setting` / env in their civicrm.settings.php — an install's
env-driven style composes naturally on top).

Extension key: `sms_chat` (composer `blackbricksoftware/civicrm-sms-chat`,
repo root = extension root). Installs consume it via composer VCS; the
an installer-path mapping lands it at `ext/sms_chat/` (folder = key).

Structural template: the public BlackBrick extensions `civicrm-saml-auth` and
`civicrm-container-logs`, whose conventions are adopted wholesale: short
extension key; code under `src/` in the `BlackBrickSoftware\CiviCRMSmsChat\`
namespace (`Service/`, `Subscriber/`, `Line/`), API4 classes under `Civi/Api4`
(where API4 discovery requires them), civix `CRM_SmsChat_*` for pages/forms;
`declare(strict_types = 1)` everywhere; the hooks file carries ONLY
`hook_civicrm_container` (autowired services + one `addSubscriber` line per
feature, comment a line to disable it) plus civix-delegated hooks; every
setting is env-loadable (`is_env_loadable` + `global_name`
`CIVICRM_SMSCHAT_*`) with a settings form whose env-managed fields are frozen
by a subscriber; PHP 8.1+; a tag-triggered GitHub release workflow producing
the zip.

---

## 1. What the research established (the facts we build on)

### The data model (verified against the live community DB, core 6.17)

Three activity types matter (always resolve by `name`, never `value`):

| name | label | role |
|---|---|---|
| `SMS` | "Outbound SMS" | parent activity of an outbound send; ONE per send job, N target contacts (a mass blast in the live DB has 137 targets on one activity); `details` = un-tokenized template text |
| `SMS delivery` | per-recipient outbound record | created by the Twilio provider's `send()` (`CRM_SMS_Provider::createActivity`); one per recipient; `details` = the rendered text actually sent; `result` = Twilio SID; target = the recipient |
| `Inbound SMS` | inbound message | created by `CRM_SMS_Provider::processInbound`; `details` = message body; `phone_number` = sender; `result` = Twilio SmsSid; **source == target == the sender** (Twilio passes `$to = NULL`, so the activity is self-referential — never infer direction from source/target, infer it from the type) |

Statuses are `Completed` for both directions. No custom groups extend any SMS
activity type in the live DB.

**Consequence — the thread query renders `Inbound SMS` + `SMS delivery`,
NOT the parent `SMS`.** The delivery activity is the per-recipient truth:
rendered text, per-message timestamp, Twilio SID for dedup, exactly one
target. Bonus: mass blasts naturally appear in each recipient's thread, like
a real phone's SMS history. The parent `SMS` still gets created on send (it's
what the activity list shows and what core expects); the chat just doesn't
render it (it would duplicate the delivery row).

### Live environment facts

- **Several active Twilio lines** in `civicrm_sms_provider`, one per
  program. All `name = org.civicrm.sms.twilio`; the From number lives in
  `api_params` (newline `key = value` text: `From=+1...`). More than one row
  can carry `is_default = 1` (data quirk — don't trust is_default alone).
- Real volume: 292 Inbound SMS, 326 SMS, 6,643 SMS delivery activities.
- iPhone tapback reactions arrive as ordinary inbound messages
  ("👍 to «…»", "Removed 👍 from «…»").
- Inbound `details` may carry install-specific decoration (some installs'
  customization extensions prepend a `From/To + <hr>` preamble). The renderer treats
  `details` defensively: strip a leading from/to preamble block when present,
  display the rest. Generic across installs; no format is assumed.
- Activity contact roles: 1=Assignees, 2=Source ("Added by"), 3=Targets.
  The client is addressed as the TARGET — queries and UI key off target
  containment, never source (core's Twilio inbound is self-referential,
  source==target==sender; direction always comes from activity type).
  Historical data is left exactly as-is.

### Sending (core primitives)

- `CRM_Activity_BAO_Activity::sendSMS()` is **deprecated since 5.71** — not
  used.
- The real primitive is `sendSMSMessage($toID, &$text, $smsProviderParams,
  $activityID, $sourceContactID)` — but it swallows provider errors: Twilio's
  `send()` returns a **PEAR_Error instead of throwing**, so `sendSMSMessage`
  reports success on Twilio API failure. A chat UI must surface real failures,
  so our send action talks to the provider directly (see §4) and replicates
  the 3 things `sendSMSMessage` does around it: recipient phone resolution,
  provider param injection (`contact_id`, `parent_activity_id`), and the
  `ActivityContact` target row.
- Recipient eligibility (mirrors core `SMSTrait`): mobile phone
  (`phone_type_id:name = Mobile`), `phone_numeric` non-empty,
  `contact.do_not_sms = 0`, `contact.is_deceased = 0`. Primary mobile wins.
- **There is NO core API (v3 or v4) for sending SMS.** We write our own API4
  actions — which also satisfies "API4 exclusively": custom actions ARE API4.
- Permission: core gates sending on `send SMS`.
- Token rendering: chat messages are literal text — no tokens, no Smarty. We
  send exactly what the user typed (`disableSmarty` semantics by construction).
- 460-char core limit (`CRM_SMS_Provider::MAX_SMS_CHAR`).

### Inbound + "real-time"

- Inbound arrives via the **unauthenticated** public route
  `civicrm/sms/callback?provider_id=N` → `processInbound()`: fuzzy
  `phone_numeric LIKE` match (US-centric, strips leading 1s, first row wins),
  and **creates a junk Individual contact** (`{digits}@mobile.sms`) when no
  match. These are pre-existing core behaviors; the chat consumes their
  output.
- `hook_civicrm_inboundSMS` fires pre-activity (no activity id);
  `hook_civicrm_postCommit` on Activity create is the reliable "new inbound
  exists" signal server-side.
- **CiviCRM has zero push infrastructure** (no SSE/WebSocket anywhere in core
  or contrib here). Core's own precedent for live updates is polling with a
  focus guard (civiimport's Monitor.tpl: `setInterval` + `document.hasFocus()`).
  So: the chat polls. See §6.

### Frontend wiring (the load-bearing details)

- `hook_civicrm_tabset` on `civicrm/contact/view`; tab entry MUST have an
  `id` (org.civicrm.contactlayout does `array_column(..., 'id')` and silently
  drops id-less tabs). The hook is also invoked by ContactLayout's editor with
  `contact_id = 0, caller = 'ContactLayout'` — handler must tolerate that and
  skip per-contact work.
- Tab content = jQuery-UI AJAX load of our route with `snippet=json`. Scripts
  reach the response ONLY from the `ajax-snippet` resource region
  (`CRM_Core_Page::addAjaxResources`), and `scriptUrls` are deduped by src —
  the bundle executes once per page load even if the tab is closed/reopened.
  `addScriptFile` does NOT auto-pick the region: pass
  `region = CRM_Core_Resources::isAjaxMode() ? 'ajax-snippet' : 'html-header'`.
- **ESM bundles do not load through the AJAX tab path** (core TODO skips
  `esm` snippets). The Vue build must be **IIFE**, not ESM.
- Tab close destroys the panel DOM (`crmSnippet destroy` → `crmUnload`).
  **Solution to both the once-only-script and the teardown problem: Vue 3
  `defineCustomElement`.** The bundle registers `<sms-chat>` once;
  the custom element auto-upgrades every time the tab panel HTML is
  (re)injected, and `disconnectedCallback` gives us free cleanup (stop the
  poll timer) when the tab is torn down. This is also the closest thing to a
  core precedent (chart_kit's native custom elements).
- `CRM.api4()` JS client: session-authenticated, requires
  `X-Requested-With: XMLHttpRequest` (jQuery sets it; raw fetch doesn't), has
  a **batch form** (`CRM.api4({a: [...], b: [...]})` = one HTTP request),
  `checkPermissions` cannot be disabled by the client. We use it for
  everything — no bespoke JSON routes.
- No Vue anywhere in this stack today; we're the first, with a committed
  `dist/` so installs need no node.

---

## 2. Extension layout

```
civicrm-sms-chat/                       (repo root = ext root)
├── info.xml                            key sms_chat, file "sms_chat"
│                                       mixins: menu-xml@1.0.0, scan-classes@1.0.0, smarty@1.0.3
│                                       <upgrader>CRM_SmsChat_Upgrader</upgrader>
├── sms_chat.php                        container hook only (community style)
├── sms_chat.civix.php
├── CRM/SmsChat/
│   ├── Page/Chat.php                   CRM_Core_Page: cid retrieve, resources, vars
│   └── Upgrader.php                    empty modern-base stub
├── Civi/Smschat/
│   ├── Tab/ChatTab.php                 hook_civicrm_tabset subscriber
│   └── Service/
│       ├── Lines.php                   provider rows -> [{id, title, from}] (parses api_params)
│       ├── Conversation.php            thread query + message DTO normalization
│       ├── Composer/Sender.php         the actual send (provider direct, PEAR_Error surfaced)
│       └── DevGuard.php                env-driven dev send protection (§7)
├── Civi/Api4/
│   ├── SmsChat.php                     AbstractEntity
│   └── Action/SmsChat/
│       ├── GetContext.php              phones/consent/lines/permissions for the header state
│       ├── GetMessages.php             thread page + poll cursor
│       └── Send.php                    send one message
├── xml/Menu/sms_chat.xml               civicrm/contact/view/sms_chat -> CRM_SmsChat_Page_Chat
├── templates/CRM/SmsChat/Page/Chat.tpl  <sms-chat ...></sms-chat> mount point only
├── ui/                                 Vue 3 + Vite source (not shipped to prod path)
│   ├── package.json / vite.config.js   build -> ../dist, format IIFE, custom element mode
│   └── src/…                           SmsChat.ce.vue, MessageList, Bubble, Composer, LinePicker
├── dist/                               committed build artifacts (smschat.js, smschat.css)
├── tests/phpunit/                      community-style e2e bootstrap (cv php:boot full)
├── composer.json                       type civicrm-ext, metadata + test script
└── README.md
```

## 3. The tab

`Civi\Smschat\Tab\ChatTab` subscribes to `hook_civicrm_tabset`:

- `$tabsetName === 'civicrm/contact/view'` only.
- Tolerates ContactLayout's editor call (`contact_id = 0`): registers the tab
  entry with no per-contact work, skips when `!empty($context['caller'])`
  where expensive.
- Entry: `id: 'sms_chat'`, `title: 'SMS Chat'`, `icon: 'crm-i fa-comments'`,
  `weight` near Activities, `url: CRM_Utils_System::url(
  'civicrm/contact/view/sms_chat', "reset=1&cid={$cid}")`, no `class` (plain
  `CRM.loadPage`), `contact_type` unset (all types — orgs text too).
- Visibility: shown when the user can view the contact. **The tab always
  shows** — "no valid mobile number" is a state the UI explains, not a reason
  to hide the tab (explicit requirement). No count badge in v1 (a per-contact
  unread query on every summary load isn't worth it yet).
- Send capability is a separate, in-tab concern (`send SMS` permission +
  consent checks) reported by `SmsChat.getContext`.

## 4a. Line attribution — first-class, owned by this extension

Requirement: staff must be able to view ONE line's conversation with the
contact (a program's staff care about their own line) AND the full merged feed
(client navigation wants everything). Parsing `details` HTML forever is not a
data model, so:

**Storage: a managed custom group `SMS_Chat` on activities** (extends
`Inbound SMS`, `SMS delivery`, `SMS` — by `:name`), fields:
- `line_number` (the org-side number: To for inbound, From for outbound)
- `peer_number` (the contact-side number actually used)

**Line identity (generic):** a "line" is an active `SmsProvider` row. Its
display name is the provider's `title` — that IS the generic "name of the
phone number" (installs already label their providers: "Youth line +1555…",
"Support line", etc.). Its number(s) are resolved by a `LineResolver`:

```php
interface LineResolver {
  public function providerNumbers(array $providerRow): array;  // config -> numbers
  public function inboundNumbers(): ?array;                    // webhook ctx -> [to, from]
}
```

Shipped: `TwilioLineResolver` (parses `From=` out of `api_params`, including
`|`-separated pools; reads To/From from the inbound webhook request). Other
providers: a dispatched event (`sms_chat.resolvers`) lets any extension
supply a resolver for its provider; unresolvable providers degrade gracefully
(line = provider title, no number badge, inbound untagged). Adding provider
support never touches this extension's core.

Written by:
- **Inbound**: two-step within the webhook request:
  `hook_civicrm_inboundSMS` recovers To/From via the resolver and stashes
  them (the hook fires pre-activity, no id yet); then `hook_civicrm_post` on
  the just-created `Inbound SMS` activity writes the custom fields.
  Optionally (setting `sms_chat_details_preamble`, default ON) also prepends
  the human-readable `From/To + <hr>` preamble to `details`, so the plain
  activity list stays informative for staff who never open the chat tab.
- **Outbound via chat**: `SmsChat.send` knows the provider — writes the
  custom fields on the parent `SMS` and on the provider-created
  `SMS delivery` (located by SID) directly.
- **Outbound via core mass/send forms**: not attributable at creation time
  from a post hook (the provider picks From internally, possibly from a
  pool, and nothing observable records it) — stays untagged, shown as
  line-unknown. Accepted.
- **No backfill.** Historical data renders as-is; whatever attribution can be
  read at display time is shown, unknown stays unknown. (Install-specific
  detail formats are not this extension's business.)

**Sender display (product decision):** every bubble shows WHO — outbound bubbles
carry the sending staffer's display name (the activity's source contact),
inbound carries the contact's name. `getMessages` joins the source contact
display name into the DTO (`senderName`).

**UI**: header gains a line filter — `All | <provider title> | ...` chips
built from `getContext.lines`, with an implicit "unknown" bucket in All.
Filter is a VIEW control, not a security boundary (decided: no new
permission model — activity visibility and `send SMS` are the whole story).
The composer's line picker and the filter are independent controls.

## 4. The API4 surface (server)

All chat traffic is three custom API4 actions on a `SmsChat` entity. Internal
reads use `Activity::get(TRUE)` etc. **with permission checks on**, so ACLs —
including any install-level activity ACL extensions — apply to chat
exactly as they do to the activity list.

### `SmsChat.getContext` (contactId)
One call that tells the UI everything it needs to render the header and
decide composer state:
- contact display name + all mobile phones (`Phone.get`: id, phone,
  phone_numeric, is_primary), plus consent flags (`do_not_sms`, `is_deceased`)
  and a computed `canSms` + machine-readable `blockers[]`
  (`no_mobile` | `do_not_sms` | `deceased` | `no_permission` | `no_provider`).
- lines: active providers as `[{id, title, from}]` — `from` parsed from
  `api_params` (`From=+1...`; `|`-separated pools returned as arrays). Sorted
  default-first; remembers nothing server-side (the UI persists last-used line
  per contact in localStorage).
- `maxChars` (460), current user's `send SMS` permission.

### `SmsChat.getMessages` (contactId, sinceId = null, limit = 50)
The thread, oldest-first, as normalized DTOs:

```
{ id, direction: 'in'|'out',
  body,                       // cleaned text (defensive preamble strip, HTML-decoded)
  line,                       // org-side number: SMS_Chat.line_number, else null
  lineTitle,                  // provider title when the number maps to a known line
  peer,                       // contact-side number when known
  at,                         // activity_date_time
  sid,                        // provider message id from result, when present
  senderContactId,            // 'out': the staff source contact; 'in': the contact
  senderName,                 // display name of the above — every bubble shows WHO
  kind: 'message'|'tapback'   // tapback detection: "👍 to «…»" / "Removed … from «…»"
}
```

Optional `line` param filters server-side (matches `SMS_Chat.line_number`;
`line = null` + `onlyUnknown` flag covers the unknown bucket). The UI's All
feed is the unfiltered call.

Query: `Activity.get` where `activity_type_id:name IN ('Inbound SMS',
'SMS delivery')`, target CONTAINS contactId (`CONTAINS`, not `=` — API4
deprecation), `is_deleted = 0`, order `activity_date_time, id`. With `sinceId`
set, only rows `id > sinceId` — the poll is a cheap incremental fetch, not a
re-download.

Normalization handles the two inbound `details` formats (bare body vs
`From/To + <hr>` preamble from `InboundMessageDetails`) and extracts the To
number as the inbound `line`. Outbound line attribution for history is
unknown (nothing records which provider sent an existing `SMS delivery`);
going forward our own sends stamp it (below).

### `SmsChat.send` (contactId, providerId, text)
- Permission: `send SMS` (also declared in the action's `getPermissions`).
- Validates: text non-empty, ≤460 chars; consent + mobile via the same
  eligibility rules as core (`do_not_sms`, `is_deceased`, mobile with
  phone_numeric; primary mobile first). We do NOT pass a raw `To` that
  bypasses consent — the number always comes from the contact's validated
  mobile.
- DevGuard check (§7) before anything touches Twilio.
- Creates the parent activity via API4: `Activity.create` with
  `activity_type_id:name = 'SMS'`, source = logged-in contact,
  `status_id:name = 'Completed'`, `details` = text, `subject` =
  `SMS Chat via {line title} ({from})` — the subject is our forward-going
  line attribution AND makes chat sends self-documenting in the activity list.
- Sends via the provider directly (`CRM_SMS_Provider::singleton(['provider_id'
  => ...])->send(...)`) with the params `sendSMSMessage` would have injected
  (`To`, `contact_id`, `parent_activity_id`), then **checks
  `is_a($result, 'PEAR_Error')`** and throws a real API4 exception with the
  Twilio message on failure — the one behavior core's path can't give us.
  On failure the parent activity is deleted again (no phantom "sent" record;
  the UI shows the error on the bubble instead).
- Adds the `ActivityContact` target row (record type Targets) exactly as
  `sendSMSMessage` does.
- Returns the new message DTO (from the `SMS delivery` activity the provider
  created — fetched by SID) so the UI can reconcile its optimistic bubble.

## 5. The Vue app

- **Vue 3 + Vite**, `ui/` source, built as **IIFE** (`format: 'iife'`, ESM is
  a dead end through the AJAX tab pipeline) in **custom element mode**:
  `defineCustomElement(SmsChat)` → `customElements.define('sms-chat', ...)`.
  Shadow DOM gives us style isolation from crm-* CSS both ways; the component
  carries its own styles (injected into the shadow root by the CE build).
- `dist/` is committed (marked `linguist-generated -diff` so it stays out of
  diffs), because Packagist packages a tag's tree: a tag without `dist/`
  installs without the UI. Release = build, commit, bump, tag, push — the
  same flow as every other extension. No node in any deploy path.
- Mounting: `Chat.tpl` is one line — `<sms-chat contact-id="{$contactId}">`
  — so remounting is automatic on every tab open, and the poll timer dies in
  `disconnectedCallback` on tab close. No `crmLoad` choreography needed.
- Data via `window.CRM.api4` (already on the page; sets the required
  X-Requested-With header, returns a real Promise). Initial load is one
  batched call: `{context: ['SmsChat','getContext',...], messages:
  ['SmsChat','getMessages',...]}`.

UI (the Google Messages feel):
- Header: contact name + mobile number; line picker (one entry per active
  provider, shows `title (number)` when the resolver knows the number),
  collapsed when only one line exists; last-used line per contact remembered
  in localStorage.
- Thread: day separator chips; inbound bubbles left/neutral, outbound right/
  accent; timestamp + line badge subtle under each bubble; tapbacks rendered
  as small inline chips rather than full bubbles; auto-scroll to bottom,
  preserved scroll position when reading history; "new messages" pill when
  scrolled up and something arrives.
- Composer: autogrow textarea, Enter sends / Shift+Enter newline, live
  460-char counter (turns warning near limit), disabled with an explanatory
  banner when `canSms` is false ("No mobile number on file", "Contact has
  Do Not SMS set", "You don't have the send SMS permission", "SMS is not
  configured"), per requirement: the tab is always there, the UI explains
  the blocker.
- Sending: optimistic bubble in `pending` state → reconciled with the real
  DTO on success → `failed` state with the Twilio error + retry affordance on
  failure.

## 6. Real-time = polling (deliberately)

- `SmsChat.getMessages(contactId, sinceId)` every **5s while
  `document.visibilityState === 'visible'`**, paused otherwise (core's own
  civiimport pattern, upgraded from hasFocus to visibility). Incremental by
  `sinceId` — each tick is one tiny query returning usually zero rows.
- Poll lifecycle owned by the custom element: start on `connectedCallback`,
  stop on `disconnectedCallback`.
- Each poll is a full Civi bootstrap (~the same cost as any admin AJAX);
  5s × one open contact tab is well inside comfortable territory. If this
  ever needs to get cheaper: a `getLatestId` micro-action, or long-poll. SSE/
  WebSockets stay out of scope — there is no precedent or infrastructure for
  them in this stack, and polling at 5s IS the messages-web experience in
  practice.
- Future (noted, not v1): `hook_civicrm_postCommit` listener on Inbound SMS
  writing a per-contact "last inbound id" cache to make the poll a
  Redis-cache hit instead of a DB query; browser Notification API for
  background pings.

## 7. Dev safety (non-negotiable, learned from the mail work)

A dev/staging install restored from production carries **live SMS provider
credentials**, and nothing in CiviCRM core redirects SMS the way dev mail
gets redirected. Generic solution — proper extension settings (usable by any
install, pinnable via `$civicrm_setting`/env by installs that do
config-as-code):

1. **Send guard in `SmsChat.send`** (our path, always), driven by two
   settings:
   - `sms_chat_allowed_recipients` (comma-separated E.164 numbers and/or
     prefixes): when non-empty, sends to anything else are refused with a
     clear error.
   - `sms_chat_environment_lockdown` (default TRUE): when
     `CRM_Core_Config::environment()` is not 'Production' (core's own
     environment setting), deny-all unless the recipient matches the
     allowlist — dev can send, but only to allowlisted numbers.
   - Both env-loadable (`CIVICRM_SMSCHAT_ALLOWED_RECIPIENTS`,
     `CIVICRM_SMSCHAT_ENVIRONMENT_LOCKDOWN`) for installs that pin config
     from the environment.
2. **Test mode** (`sms_chat_test_mode`): the send pipeline records the `SMS`
   and `SMS delivery` activities exactly as a real send would (result
   `TEST-…`) and never calls the provider — the full UI loop is testable with
   zero provider traffic. (Replaces the earlier "mock provider row" idea:
   CiviCRM resolves provider classes from extension keys, which would have
   forced a class named after our key inside the hooks file.)

**Standing rule: no text messages are sent — by test, by accident, by any
path — until the safeguards above exist; live-send testing then targets an
allowlisted test number only.** Inbound flow is testable safely today by POSTing to
`civicrm/sms/callback?provider_id=N` locally (it's an unauthenticated route —
which is itself a pre-existing exposure worth noting for a future hardening
pass: Twilio signature validation ships in the twilio ext but is never
called).

## 8. Known constraints, accepted

- **Outbound history has no line attribution** (pre-extension data), and
  core mass sends stay untagged going forward (§4a). The thread shows line
  badges when known; unknown-line messages appear in the All feed.
- **Inbound contact matching is core's fuzzy LIKE** — wrong-suffix matches
  and junk `{digits}@mobile.sms` contacts are upstream behaviors the chat
  displays but doesn't cause. A proper E.164 `hook_civicrm_inboundSMS`
  matcher is a candidate follow-up, not v1.
- Twilio delivery-status callbacks are discarded by the twilio ext
  (`callback()` is a no-op), so "delivered/failed after accept" isn't
  knowable; our send-time PEAR_Error check catches API-level rejects, which
  is what actually bites.
- One thread per contact with a line FILTER (§4a), not separate threads.
- No read/unread state in v1 (activities have no read flag; inventing one
  means schema + cross-user semantics — a follow-up if the tab needs a badge).

## 9. Build order

1. **Scaffold** — info.xml, civix file, hooks file, empty subscribers, menu
   route, page + tpl, tab appears with a static mount point. Installable via
   `./infra/scripts/ext install`.
2. **Read-only thread** — GetContext + GetMessages actions, Vue app renders
   the real history for a contact (verifiable against any contact with a long
   real conversation, tapbacks and all), with sender names and the
   line filter over whatever attribution exists.
3. **Line attribution** — SMS_Chat custom group (managed), LineResolver
   (Twilio impl + resolver event), inbound tagging subscribers, optional
   details preamble setting.
4. **Send** — Sender service + Send action + send guard settings + mock
   provider; composer goes live in dev against test mode. First live send
   only to an allowlisted test number.
5. **Real-time + polish** — polling, optimistic sends, failure states, new
   message affordances, day grouping, blocker banners.
6. **Hardening + ship** — e2e tests (community-style bootstrap), README,
   review, tag; publish toward the civicrm.org extension directory.

## 10. Roadmap (explicitly not v1)

- **Global inbox** — `civicrm/sms_chat` page: recent conversations across all
  contacts, filterable by line (the Google Messages sidebar, for the client
  navigation "see everything" use case at org level, not per contact).
- Unread tracking + tab count badge; browser notifications.
- E.164 inbound matcher; Twilio webhook signature validation.
- Delivery-status capture (implement the twilio ext's no-op `callback()`).
