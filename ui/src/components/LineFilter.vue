<script setup>
defineProps({ lines: { type: Array, default: () => [] }, modelValue: { type: [String, Number], default: 'all' }, colors: { type: Map, default: () => new Map() } });
defineEmits(['update:modelValue']);
</script>

<template>
  <div class="sc-filter" role="tablist" aria-label="Filter by line">
    <button type="button" class="sc-chip" :class="{ active: modelValue === 'all' }" @click="$emit('update:modelValue', 'all')">All</button>
    <button v-for="l in lines" :key="l.id" type="button" class="sc-chip sc-chip-line" :class="{ active: modelValue === l.id }" :style="{ '--c': colors.get(l.id) }" :title="l.numbers.join(', ')" @click="$emit('update:modelValue', l.id)"><span class="sc-dot"></span>{{ l.title }}</button>
    <button type="button" class="sc-chip sc-chip-line" :class="{ active: modelValue === 0 }" style="--c: #7f8c8d" title="Messages with no line recorded (older history, mass sends)" @click="$emit('update:modelValue', 0)"><span class="sc-dot"></span>Unknown</button>
  </div>
</template>
