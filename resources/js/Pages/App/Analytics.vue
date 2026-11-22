<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import HBars from '@/Components/HBars.vue';
import { N, NM } from '@/format';

const props = defineProps({ month: String, monthLabel: String, months: Array, kpis: Object, trend: Array, byMethod: Array, byFacility: Array, byCategory: Array, clientStatus: Object });

const kpiCards = computed(() => [
    ['Expected each month', N(props.kpis.expected), 'from active facility users'],
    ['Billed', N(props.kpis.billed), `${props.kpis.billedShare}% of expected`, 'var(--purple)'],
    ['Collected', N(props.kpis.collected), `${props.kpis.rate}% of this month's dues paid`, 'var(--green)'],
    ['Average days to payment', props.kpis.daysToPay !== null ? props.kpis.daysToPay + ' days' : '—', 'across paid invoices', 'var(--amber)'],
]);

const rates = computed(() => props.trend.map((t) => (t.billed ? (t.collected / t.billed) * 100 : 0)));
const x = (i) => 60 + i * 80;
const y = (v) => 150 - (Math.min(v, 100) / 100) * 120;
const points = computed(() => rates.value.map((v, i) => `${x(i)},${y(v)}`).join(' '));

const status = computed(() => [
    ['Up to date', props.clientStatus.green, 'var(--green)'],
    ['1 month overdue', props.clientStatus.amber, 'var(--amber)'],
    ['More than 1 overdue', props.clientStatus.red, 'var(--red)'],
]);

const changeMonth = (e) => router.get(route('analytics'), { month: e.target.value }, { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="Analytics" />

        <PageHeader title="Analytics" sub="How the business is collecting, and from whom.">
            <select :value="month" aria-label="Month" @change="changeMonth">
                <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
        </PageHeader>

        <KpiCards :items="kpiCards" />

        <div class="grid auto">
            <div class="card">
                <h2>Collection rate by month</h2>
                <svg viewBox="0 0 520 190" width="100%" role="img" aria-label="Collection rate by month">
                    <g v-for="g in [0, 50, 100]" :key="g">
                        <line x1="40" x2="510" :y1="y(g)" :y2="y(g)" stroke="var(--line)" />
                        <text x="34" :y="y(g) + 4" font-size="11" text-anchor="end" fill="var(--mute)">{{ g }}%</text>
                    </g>
                    <polyline fill="none" stroke="var(--green)" stroke-width="2.5" :points="points" />
                    <g v-for="(v, i) in rates" :key="i">
                        <circle :cx="x(i)" :cy="y(v)" r="4" fill="var(--green)" />
                        <text :x="x(i)" :y="y(v) - 10" font-size="11" text-anchor="middle" fill="var(--ink)">{{ v.toFixed(0) }}%</text>
                        <text :x="x(i)" y="176" font-size="11" text-anchor="middle" fill="var(--mute)">{{ trend[i].label }}</text>
                    </g>
                </svg>
            </div>
            <div class="card">
                <h2>Where payments come from ({{ monthLabel }})</h2>
                <HBars :items="byMethod" :format="NM" />
            </div>
            <div class="card">
                <h2>Collected by facility ({{ monthLabel }})</h2>
                <HBars :items="byFacility" :format="NM" color="var(--green)" />
            </div>
            <div class="card">
                <h2>Collected by category ({{ monthLabel }})</h2>
                <HBars :items="byCategory" :format="NM" color="var(--purple)" />
            </div>
            <div class="card">
                <h2>Facility users by status ({{ clientStatus.total }} active)</h2>
                <HBars :items="status" :format="(v) => v + ' facility users'" />
            </div>
        </div>
    </AppLayout>
</template>
