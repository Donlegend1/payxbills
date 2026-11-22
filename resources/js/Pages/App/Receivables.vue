<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import { N } from '@/format';
import { useCan } from '@/auth';

const props = defineProps({ rows: Array, totals: Array, colors: Array, kpis: Object });
const can = useCan();

const kpiCards = computed(() => [
    ['Total outstanding', N(props.kpis.outstanding), 'all open invoices', 'var(--red)'],
    ['Older than 60 days', N(props.kpis.olderThan60), 'more than two months past due', 'var(--purple)'],
    ['Unpaid invoices', props.kpis.open, props.kpis.daysToPay !== null ? `average ${props.kpis.daysToPay} days to pay` : '', 'var(--amber)'],
]);

// Darker cells mean more money in that age band
const heat = (v, k) => (v <= 0 ? 'transparent' : props.colors[k] + (v >= 300000 ? '66' : v >= 100000 ? '33' : '15'));
const remind = (r) => router.post(route('facility-users.remind', r.occupancy_id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="Receivables" />

        <PageHeader title="Receivables" sub="Who owes us, how much, and for how long past the due date. Darker cells mean more money in that age band.">
            <a class="chip on" :href="route('reports.download', { report: 'aged-receivables' })">Download CSV</a>
        </PageHeader>

        <KpiCards :items="kpiCards" />

        <div class="card scroll">
            <table v-if="rows.length" style="min-width: 880px">
                <tr><th>Facility user</th><th>0-30 days</th><th>31-60</th><th>61-90</th><th>91-180</th><th>180+</th><th>Total</th><th></th></tr>
                <tr v-for="r in rows" :key="r.name">
                    <td>{{ r.name }}<div v-if="r.place" class="sub small">{{ r.place }}</div></td>
                    <td v-for="(v, k) in r.bands" :key="k">
                        <span :style="{ display: 'block', padding: '2px 8px', borderRadius: '6px', background: heat(v, k) }">{{ N(v) }}</span>
                    </td>
                    <td><b>{{ N(r.total) }}</b></td>
                    <td><button v-if="r.occupancy_id && can('send-reminders')" class="chip" @click="remind(r)">Remind</button></td>
                </tr>
                <tr>
                    <td><b>Total</b></td>
                    <td v-for="(t, i) in totals" :key="i"><b>{{ N(t) }}</b></td>
                    <td><b>{{ N(kpis.outstanding) }}</b></td>
                    <td></td>
                </tr>
            </table>
            <div v-else class="empty">Nobody owes anything. Every month is paid.</div>
        </div>
    </AppLayout>
</template>
