<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { getContext, getMessages, sendMessage, errorMessage } from './api.js';
import { lineColors } from './colors.js';
import LineFilter from './components/LineFilter.vue';
import MessageList from './components/MessageList.vue';
import Composer from './components/Composer.vue';
import { prettyNumber } from './format.js';

const props = defineProps({ contactId: { type: Number, required: true } });

const context = ref(null);
const messages = ref([]);
const hasMore = ref(false);
const loading = ref(true);
const loadingMore = ref(false);
const error = ref('');
const lineFilter = ref('all');
const lineId = ref(null);
const sending = ref(false);
const list = ref(null);

const PAGE = 50;
const POLL_MS = 5000;

// The line filter is applied server-side (see GetMessages.lineId) so every
// page is a full page; the list is shown as-is. A message just sent from a
// different line than the active filter stays visible until the view reloads.
const filtered = computed(() => messages.value);
const lineParam = computed(() => (lineFilter.value === 'all' ? null : lineFilter.value));

const newestId = computed(() => messages.value.reduce((n, m) => (typeof m.id === 'number' && m.id > n ? m.id : n), 0));
// Every fetch belongs to a "view" (the current line filter). Switching the
// filter bumps the generation, and any in-flight page/poll from the previous
// view is discarded when it lands — otherwise older-page or poll results for
// line A could splice into the list for line B.
let view = 0;
const reloading = ref(false);
const colors = computed(() => lineColors(context.value ? context.value.lines : []));
let tmpSeq = 0;

const primaryPhone = computed(() => {
  const c = context.value; if (!c) return '';
  const p = c.phones[0] || c.allPhones[0];
  return p ? prettyNumber('+1' + String(p.phone).replace(/\D/g, '').replace(/^1/, '')) || p.phone : '';
});

const storageKey = computed(() => `smschat.line.${props.contactId}`);

async function load() {
  loading.value = true; error.value = '';
  try {
    const [ctx, msgs] = await Promise.all([
      getContext(props.contactId),
      getMessages(props.contactId, { limit: PAGE, lineId: lineParam.value }),
    ]);
    context.value = ctx;
    messages.value = msgs;
    hasMore.value = msgs.length >= PAGE;
    // Composer line: remembered per contact, else the default line.
    let remembered = null;
    try { remembered = Number(localStorage.getItem(storageKey.value)) || null; } catch (e) { /* storage unavailable */ }
    const ids = ctx.lines.map(l => l.id);
    lineId.value = ids.includes(remembered) ? remembered : (ctx.lines.find(l => l.isDefault) || ctx.lines[0] || {}).id || null;
  } catch (e) {
    error.value = errorMessage(e);
  } finally {
    loading.value = false;
  }
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value || !messages.value.length) return;
  loadingMore.value = true;
  const v = view;
  try {
    // Infinite scroll upward: the newest PAGE messages strictly before the
    // oldest one we hold, cursored on (date, id) — the list is chronological,
    // so that's the first real (non-optimistic) message.
    const oldest = messages.value.find(m => typeof m.id === 'number');
    if (!oldest) return;
    const older = await getMessages(props.contactId, { before: { id: oldest.id, at: oldest.at }, limit: PAGE, lineId: lineParam.value });
    if (v !== view) return; // filter changed while this page was loading
    hasMore.value = older.length >= PAGE;
    if (older.length) {
      const known = new Set(messages.value.map(m => m.id));
      messages.value = [...older.filter(m => !known.has(m.id)), ...messages.value];
    }
  } catch (e) {
    error.value = errorMessage(e);
  } finally {
    loadingMore.value = false;
  }
}

async function poll() {
  if (document.visibilityState !== 'visible' || loading.value || reloading.value) return;
  const v = view;
  try {
    const fresh = await getMessages(props.contactId, { sinceId: newestId.value || null, limit: PAGE, lineId: lineParam.value });
    if (v !== view) return; // filter changed while polling
    if (fresh.length) {
      const known = new Set(messages.value.map(m => m.id));
      const add = fresh.filter(m => !known.has(m.id));
      if (add.length) messages.value = [...messages.value, ...add];
    }
  } catch (e) { /* transient; next tick retries */ }
}

function localNow() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

async function onSend(text, existing = null) {
  const line = context.value.lines.find(l => l.id === lineId.value) || {};
  const tmp = existing || {
    id: `tmp-${++tmpSeq}`,
    direction: 'out', kind: 'message', body: text,
    lineId: line.id || null, lineTitle: line.title || null, line: (line.numbers || [])[0] || null,
    at: localNow(), sid: null,
    senderContactId: context.value.viewer.contactId, senderName: context.value.viewer.displayName || 'You',
  };
  tmp.state = 'pending'; tmp.error = '';
  if (!existing) messages.value = [...messages.value, tmp];
  else messages.value = [...messages.value];
  sending.value = true;
  try {
    const sent = await sendMessage(props.contactId, lineId.value, text);
    // Reconcile: swap the optimistic bubble for the real DTO unless the poll
    // already delivered it, in which case just drop the placeholder.
    const already = messages.value.some(m => m.id === sent.id);
    messages.value = messages.value
      .filter(m => m.id !== tmp.id)
      .concat(already ? [] : [sent])
      .sort((a, b) => a.at.localeCompare(b.at) || String(a.id).localeCompare(String(b.id)));
  } catch (e) {
    tmp.state = 'failed'; tmp.error = errorMessage(e);
    messages.value = [...messages.value];
  } finally {
    sending.value = false;
  }
}
function onRetry(m) { onSend(m.body, m); }

watch(lineId, (v) => { try { if (v) localStorage.setItem(storageKey.value, String(v)); } catch (e) { /* ignore */ } });

// Changing the line filter is a fresh, correctly-paged view of the thread:
// reload the newest page under the new filter. The header (and the chips you
// just clicked) stay put; only the thread swaps, and MessageList is keyed by
// the filter so each view starts with clean scroll state.
watch(lineFilter, async () => {
  const v = ++view;
  reloading.value = true; error.value = '';
  loadingMore.value = false;
  try {
    const msgs = await getMessages(props.contactId, { limit: PAGE, lineId: lineParam.value });
    if (v !== view) return; // superseded by a newer filter change
    messages.value = msgs;
    hasMore.value = msgs.length >= PAGE;
  } catch (e) {
    if (v === view) error.value = errorMessage(e);
  } finally {
    if (v === view) reloading.value = false;
  }
});

let timer = null;
onMounted(() => {
  load();
  timer = setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', poll);
});
onBeforeUnmount(() => {
  clearInterval(timer);
  document.removeEventListener('visibilitychange', poll);
});
</script>

<template>
  <div class="sc-app">
    <div v-if="loading" class="sc-loading">Loading conversation…</div>
    <div v-else-if="error" class="sc-error">{{ error }} <button type="button" class="sc-link" @click="load">Retry</button></div>
    <template v-else>
      <header class="sc-header">
        <div class="sc-who">
          <div class="sc-name">{{ context.contact.displayName }}</div>
          <div class="sc-number">{{ primaryPhone || 'No phone number on file' }}</div>
        </div>
        <LineFilter v-if="context.lines.length > 1" v-model="lineFilter" :lines="context.lines" :colors="colors" />
      </header>
      <MessageList :key="String(lineFilter)" ref="list" :messages="filtered" :has-more="hasMore" :loading-more="loadingMore" :reloading="reloading" :colors="colors" @load-more="loadMore" @retry="onRetry" />
      <Composer :context="context" v-model:lineId="lineId" :sending="sending" :colors="colors" @send="onSend" />
    </template>
  </div>
</template>

<style>
:host { display: block; }
.sc-app { display: flex; flex-direction: column; height: min(70vh, 720px); min-height: 420px; font: 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1f2933; background: #fff; border: 1px solid #dfe3e8; border-radius: 8px; overflow: hidden; position: relative; }
.sc-loading, .sc-error, .sc-empty { padding: 24px; color: #6b7280; text-align: center; }
.sc-error { color: #b42318; }
.sc-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; border-bottom: 1px solid #eceff3; background: #f9fafb; flex-wrap: wrap; }
.sc-name { font-weight: 600; font-size: 15px; }
.sc-number { color: #6b7280; font-size: 12px; }
.sc-filter { display: flex; gap: 6px; flex-wrap: wrap; }
.sc-chip { border: 1px solid #d0d5dd; background: #fff; border-radius: 999px; padding: 3px 10px; font-size: 12px; cursor: pointer; color: #344054; }
.sc-chip.active { background: #1a73e8; border-color: #1a73e8; color: #fff; }
.sc-chip-line { --c: #7f8c8d; display: inline-flex; align-items: center; gap: 6px; }
.sc-chip-line.active { background: var(--c); border-color: var(--c); color: #fff; }
.sc-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--c, #7f8c8d); flex: none; }
.sc-chip-line.active .sc-dot { background: #fff; }
.sc-line-wrap { display: inline-flex; align-items: center; gap: 6px; }
.sc-thread { flex: 1; overflow-y: auto; padding: 12px 14px; display: flex; flex-direction: column; gap: 2px; background: #fff; }
.sc-day { align-self: center; margin: 12px 0 6px; font-size: 11px; color: #6b7280; background: #f2f4f7; padding: 2px 10px; border-radius: 999px; }
.sc-row { display: flex; margin: 1px 0; }
.sc-row.in { justify-content: flex-start; }
.sc-row.out { justify-content: flex-end; }
.sc-bubble-wrap { max-width: min(78%, 560px); display: flex; flex-direction: column; }
.sc-row.out .sc-bubble-wrap { align-items: flex-end; }
.sc-sender { font-size: 11px; color: #6b7280; margin: 6px 6px 2px; }
.sc-bubble { padding: 8px 12px; border-radius: 18px; white-space: pre-wrap; word-break: break-word; }
.sc-row.in .sc-bubble { background: #f1f3f4; color: #1f2933; border-bottom-left-radius: 6px; }
.sc-row.out .sc-bubble { background: #1a73e8; color: #fff; border-bottom-right-radius: 6px; }
/* Line color coding: outbound bubbles take the line color; inbound keep a
   readable neutral fill with a tinted edge in the line color. */
.sc-row.out.has-line .sc-bubble { background: var(--c); }
.sc-row.in.has-line .sc-bubble { background: color-mix(in srgb, var(--c) 12%, #f1f3f4); border-left: 3px solid var(--c); }
.sc-row.tapback .sc-bubble { background: transparent; border: 1px dashed #d0d5dd; color: #6b7280; font-size: 12px; padding: 4px 10px; }
.sc-row.pending .sc-bubble { opacity: .6; }
.sc-row.failed .sc-bubble { background: #fee4e2; color: #b42318; }
.sc-meta { font-size: 10.5px; color: #98a2b3; margin: 2px 6px 4px; }
.sc-failed { color: #b42318; }
.sc-new { position: absolute; left: 50%; transform: translateX(-50%); bottom: 118px; background: #1a73e8; color: #fff; border: 0; border-radius: 999px; padding: 6px 14px; font-size: 12px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
.sc-composer { border-top: 1px solid #eceff3; padding: 8px 12px 6px; background: #f9fafb; }
.sc-blocked { background: #fffaeb; border: 1px solid #fedf89; color: #93370d; border-radius: 6px; padding: 8px 12px; margin-bottom: 8px; font-size: 13px; }
.sc-blocked ul { margin: 4px 0 0 18px; padding: 0; }
.sc-blocked.sc-soft { background: #eff8ff; border-color: #b2ddff; color: #175cd3; }
.sc-compose-row { display: flex; gap: 8px; align-items: flex-end; }
.sc-compose-row.disabled { opacity: .55; }
.sc-line { font-size: 12px; padding: 6px 8px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; max-width: 200px; }
.sc-input { flex: 1; resize: none; border: 1px solid #d0d5dd; border-radius: 18px; padding: 8px 14px; font: inherit; min-height: 38px; max-height: 160px; background: #fff; }
.sc-input:focus { outline: 2px solid #1a73e8; outline-offset: -1px; }
.sc-send { border: 0; background: #1a73e8; color: #fff; border-radius: 999px; padding: 8px 16px; font-weight: 600; cursor: pointer; }
.sc-send:disabled { background: #b2c8e8; cursor: default; }
.sc-counter { text-align: right; font-size: 11px; color: #98a2b3; margin-top: 4px; }
.sc-counter.warn { color: #b54708; }
.sc-counter.over { color: #b42318; font-weight: 600; }
.sc-link { background: none; border: 0; color: #1a73e8; cursor: pointer; font: inherit; padding: 0; }
.sc-more { background: transparent; }
</style>
