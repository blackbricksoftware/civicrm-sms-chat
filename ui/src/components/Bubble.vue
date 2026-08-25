<script setup>
import { computed } from 'vue';
import { timeLabel, prettyNumber } from '../format.js';

const props = defineProps({ m: { type: Object, required: true }, showSender: { type: Boolean, default: true }, color: { type: String, default: '' } });
defineEmits(['retry']);
// e.g. inbound  "5:20 PM · from (555) 010-2345 · Youth line"
//      outbound "5:20 PM · Youth line +15550100000 · to (555) 010-2345"
// The contact-side number matters: contacts carry several mobiles and change
// them over time, so each bubble names the number it actually travelled to/from.
const meta = computed(() => {
  const m = props.m;
  const bits = [timeLabel(m.at)];
  const line = m.lineTitle || (m.line ? prettyNumber(m.line) : '');
  const peer = m.peer ? prettyNumber(m.peer) : '';
  if (m.direction === 'in') {
    if (peer) bits.push(`from ${peer}`);
    if (line) bits.push(line);
  } else {
    if (line) bits.push(line);
    if (peer) bits.push(`to ${peer}`);
  }
  return bits.join(' · ');
});
</script>

<template>
  <div class="sc-row" :class="[m.direction, m.kind, m.state || '']" :style="{ '--c': color }">
    <div class="sc-bubble-wrap">
      <div v-if="showSender && m.senderName" class="sc-sender">{{ m.senderName }}</div>
      <div class="sc-bubble">
        <span class="sc-text">{{ m.body }}</span>
      </div>
      <div class="sc-meta">
        <span v-if="m.state === 'pending'">Sending…</span>
        <span v-else-if="m.state === 'failed'" class="sc-failed">Failed: {{ m.error }} <button type="button" class="sc-link" @click="$emit('retry', m)">Retry</button></span>
        <span v-else-if="m.sid && m.sid.startsWith('TEST-')">{{ meta }} · test mode, not delivered</span>
        <span v-else>{{ meta }}</span>
      </div>
    </div>
  </div>
</template>
