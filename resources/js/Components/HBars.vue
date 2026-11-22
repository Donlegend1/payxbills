<script setup>
import { computed } from 'vue';

// items: [label, value, colour?]
const props = defineProps({
    items: { type: Array, required: true },
    format: { type: Function, default: (v) => v },
    color: { type: String, default: 'var(--blue)' },
});

const max = computed(() => Math.max(1, ...props.items.map((i) => i[1])));
const height = computed(() => props.items.length * 30 + 6);
const width = (v) => (v / max.value) * 230;
</script>

<template>
    <svg v-if="items.length" :viewBox="`0 0 520 ${height}`" width="100%" role="img">
        <g v-for="(it, i) in items" :key="it[0]">
            <text x="0" :y="i * 30 + 20" font-size="12" fill="var(--ink)">{{ String(it[0]).length > 22 ? String(it[0]).slice(0, 21) + '…' : it[0] }}</text>
            <rect x="160" :y="i * 30 + 6" :width="width(it[1])" height="18" rx="3" :fill="it[2] || color" />
            <text :x="166 + width(it[1])" :y="i * 30 + 20" font-size="12" fill="var(--mute)">{{ format(it[1]) }}</text>
        </g>
    </svg>
    <div v-else class="empty" style="padding: 24px">Nothing to show for this period.</div>
</template>
