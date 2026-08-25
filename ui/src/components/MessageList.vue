<script setup>
import { computed, ref, watch, nextTick, onMounted } from 'vue';
import Bubble from './Bubble.vue';
import { dayKey, dayLabel } from '../format.js';

const props = defineProps({
  messages: { type: Array, default: () => [] },
  hasMore: { type: Boolean, default: false },
  loadingMore: { type: Boolean, default: false },
  colors: { type: Map, default: () => new Map() },
});
const emit = defineEmits(['load-more', 'retry']);

const scroller = ref(null);
const unseen = ref(0);
const atBottom = ref(true);

// Group into days; collapse the sender label when consecutive bubbles share
// direction + sender (the chat-app "run" convention).
const groups = computed(() => {
  const out = [];
  let cur = null;
  let prev = null;
  for (const m of props.messages) {
    const k = dayKey(m.at);
    if (!cur || cur.key !== k) { cur = { key: k, label: dayLabel(m.at), items: [] }; out.push(cur); prev = null; }
    const showSender = !prev || prev.direction !== m.direction || prev.senderContactId !== m.senderContactId;
    cur.items.push({ m, showSender });
    prev = m;
  }
  return out;
});

function isNearBottom() {
  const el = scroller.value; if (!el) return true;
  return el.scrollHeight - el.scrollTop - el.clientHeight < 40;
}
function scrollToBottom(smooth = false) {
  const el = scroller.value; if (!el) return;
  el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
  unseen.value = 0;
}
function onScroll() {
  atBottom.value = isNearBottom();
  if (atBottom.value) unseen.value = 0;
  if (scroller.value.scrollTop < 30 && props.hasMore && !props.loadingMore) emit('load-more');
}

let lastCount = 0;
let lastFirstId = null;
watch(() => props.messages, async (list) => {
  const wasNearBottom = isNearBottom();
  const el = scroller.value;
  const prevHeight = el ? el.scrollHeight : 0;
  const prevTop = el ? el.scrollTop : 0;
  const grewAtTop = list.length && lastFirstId !== null && list[0].id !== lastFirstId;
  const added = list.length - lastCount;
  await nextTick();
  if (grewAtTop && el) {
    // Older history prepended: keep the viewport anchored.
    el.scrollTop = prevTop + (el.scrollHeight - prevHeight);
  } else if (lastCount === 0 || wasNearBottom) {
    scrollToBottom(lastCount !== 0);
  } else if (added > 0) {
    unseen.value += added;
  }
  lastCount = list.length;
  lastFirstId = list.length ? list[0].id : null;
  // A short thread (or a heavily filtered one) may not be scrollable at all,
  // which would leave the scroll-to-top trigger unreachable: keep paging
  // until there is something to scroll or history runs out.
  if (el && props.hasMore && !props.loadingMore && el.scrollHeight <= el.clientHeight + 4) emit('load-more');
}, { deep: false });

onMounted(() => nextTick(() => scrollToBottom()));
defineExpose({ scrollToBottom });
</script>

<template>
  <div class="sc-thread" ref="scroller" @scroll="onScroll">
    <div v-if="loadingMore" class="sc-day">Loading older messages…</div>
    <div v-else-if="hasMore" class="sc-day sc-more"><button type="button" class="sc-link" @click="$emit('load-more')">Load older messages</button></div>
    <template v-for="g in groups" :key="g.key">
      <div class="sc-day"><span>{{ g.label }}</span></div>
      <Bubble v-for="it in g.items" :key="it.m.id" :m="it.m" :show-sender="it.showSender" :color="it.m.lineId ? colors.get(it.m.lineId) : ''" @retry="emit('retry', $event)" />
    </template>
    <div v-if="!messages.length" class="sc-empty">No text messages with this contact yet.</div>
  </div>
  <button v-if="unseen" type="button" class="sc-new" @click="scrollToBottom(true)">↓ {{ unseen }} new message{{ unseen > 1 ? 's' : '' }}</button>
</template>
