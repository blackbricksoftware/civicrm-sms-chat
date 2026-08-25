{* The entire tab body: a custom-element mount point. The Vue bundle
   (dist/smschat.js) registers <sms-chat>; the element upgrades on injection
   and unmounts itself when the tab panel is destroyed. Inner content shows
   until the element upgrades (and stands in entirely until the UI milestone
   ships the bundle). *}
<sms-chat contact-id="{$smschatContactId}">
  <div class="crm-loading-element">{ts}Loading SMS Chat…{/ts}</div>
</sms-chat>
