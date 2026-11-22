<script setup>
// items: [label, value, detail, colour]
defineProps({ items: { type: Array, required: true } });
</script>

<template>
    <div class="grid kpis mb" :style="{ gridTemplateColumns: `repeat(${items.length}, 1fr)` }">
        <div v-for="k in items" :key="k[0]" class="card kpi" :style="{ '--c': k[3] || 'var(--blue)' }">
            <div class="l">{{ k[0] }}</div>
            <div class="v">{{ k[1] }}</div>
            <div class="d">{{ k[2] }}</div>
        </div>
    </div>
</template>
