import { defineCustomElement } from 'vue';
import SmsChat from './SmsChat.ce.vue';

// The bundle executes once per top-level page load (CiviCRM dedupes snippet
// scripts by src). Registering a custom element is what makes that fine: the
// tab's <sms-chat> mounts itself every time its HTML is injected and tears
// down in disconnectedCallback when the tab panel is destroyed.
if (!customElements.get('sms-chat')) {
  customElements.define('sms-chat', defineCustomElement(SmsChat));
}
