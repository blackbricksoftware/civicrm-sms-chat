<script setup>
import { ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
  context: { type: Object, required: true },
  lineId: { type: [Number, null], default: null },
  sending: { type: Boolean, default: false },
  colors: { type: Map, default: () => new Map() },
});
const emit = defineEmits(['send', 'update:lineId']);

const text = ref('');
const ta = ref(null);
const max = computed(() => props.context.maxChars || 460);
const remaining = computed(() => max.value - text.value.length);
const canSend = computed(() => props.context.canSms && text.value.trim().length > 0 && remaining.value >= 0 && !props.sending);
const lineColor = computed(() => props.colors.get(props.lineId) || '#7f8c8d');
const lockdown = computed(() => props.context.lockdown || {});

const BLOCKERS = {
  no_mobile: null, // composed below from the contact's actual phones
  do_not_sms: 'This contact has "Do not SMS" set.',
  deceased: 'This contact is marked deceased.',
  no_permission: 'You do not have the "send SMS" permission.',
  no_provider: 'No SMS provider is configured (Administer › System Settings › SMS Providers).',
};
function noMobileText() {
  const phones = props.context.allPhones || [];
  if (!phones.length) return 'No phone number on file for this contact. Add a phone of type Mobile to enable texting.';
  const list = phones.map(p => `${p.phone} (${p.type}${p.location ? ', ' + p.location : ''})`).join('; ');
  return `No phone typed "Mobile" on file — only ${list}. CiviCRM texts Mobile numbers only; change the phone type to Mobile to enable texting.`;
}
const blockerText = computed(() => (props.context.blockers || []).map(b => b === 'no_mobile' ? noMobileText() : (BLOCKERS[b] || b)));

function autogrow() {
  const el = ta.value; if (!el) return;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 160) + 'px';
}
watch(text, () => nextTick(autogrow));

function onKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
}
function submit() {
  if (!canSend.value) return;
  emit('send', text.value.trim());
  text.value = '';
  nextTick(autogrow);
}
</script>

<template>
  <div class="sc-composer">
    <div v-if="!context.canSms" class="sc-blocked">
      <strong>Can't text this contact.</strong>
      <ul><li v-for="b in blockerText" :key="b">{{ b }}</li></ul>
    </div>
    <div v-if="context.sendMode === 'test'" class="sc-blocked sc-soft"><strong>Test mode:</strong> messages are recorded as activities but never handed to the SMS provider.</div>
    <div v-else-if="lockdown.active" class="sc-blocked sc-soft"><strong>{{ lockdown.environment }} environment:</strong> sending is limited to {{ lockdown.allowed.length ? lockdown.allowed.join(', ') : 'nobody (no allowed recipients configured)' }}.</div>
    <div class="sc-compose-row" :class="{ disabled: !context.canSms }">
      <label v-if="context.lines.length > 1" class="sc-line-wrap" title="Send from line">
        <span class="sc-dot" :style="{ background: lineColor }"></span>
        <select class="sc-line" :value="lineId" @change="$emit('update:lineId', Number($event.target.value))">
          <option v-for="l in context.lines" :key="l.id" :value="l.id">{{ l.title }}</option>
        </select>
      </label>
      <textarea ref="ta" v-model="text" rows="1" class="sc-input" placeholder="Text message" :disabled="!context.canSms" :maxlength="max + 50" @keydown="onKey"></textarea>
      <button type="button" class="sc-send" :disabled="!canSend" @click="submit" title="Send (Enter)">Send</button>
    </div>
    <div class="sc-counter" :class="{ warn: remaining < 40, over: remaining < 0 }">{{ text.length }} / {{ max }}</div>
  </div>
</template>
