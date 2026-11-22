<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { N } from '@/format';
import { useCan } from '@/auth';

const props = defineProps({
    month: String,
    monthLabel: String,
    months: Array,
    kpis: Object,
    trend: Array,
    aging: Array,
    topClients: Array,
    recentPayments: Array,
    attention: Array,
    clientStatus: Object,
    insights: Object,
    expected: String,
});

const can = useCan();

const kpiCards = computed(() => [
    ['Active facility users', props.kpis.clients, `${props.expected} expected a month`, 'var(--blue)'],
    ['Total billed', N(props.kpis.billed), `${props.kpis.invoices} invoices · ${props.monthLabel}`, 'var(--purple)'],
    ['Total collected', N(props.kpis.collected), `${props.kpis.rate}% collection rate`, 'var(--green)'],
    ['Outstanding receivables', N(props.kpis.outstanding), `${props.kpis.openInvoices} open invoices`, 'var(--red)'],
    ['Overdue receivables', N(props.kpis.overdue), `${props.kpis.overdueInvoices} invoices past due`, 'var(--amber)'],
]);

// Billed vs collected bars (₦ millions) with a collection-rate line
const W = 520, H = 190, P = 36, BW = 16;
const max = computed(() => {
    const top = Math.max(1, ...props.trend.map((t) => t.billed / 1e6));
    const step = top > 20 ? 10 : top > 5 ? 5 : 1;
    return Math.ceil(top / step) * step;
});
const ticks = computed(() => [0, 1, 2, 3].map((i) => (max.value / 3) * i));
const x = (i) => P + 20 + i * ((W - P - 30) / Math.max(1, props.trend.length));
const y = (v) => H - 24 - (v / max.value) * (H - 44);
const rateY = (t) => y(t.billed ? (t.collected / t.billed) * max.value : 0);
const ratePoints = computed(() => props.trend.map((t, i) => `${x(i)},${rateY(t)}`).join(' '));

// Receivables aging donut
const total = computed(() => props.aging.reduce((a, b) => a + b.amount, 0));
const R = 52, C = 2 * Math.PI * R;
const slices = computed(() => {
    let off = 0;
    return props.aging.map((a) => {
        const len = total.value ? (a.amount / total.value) * C : 0;
        const s = { color: a.color, dash: `${len} ${C - len}`, offset: -off };
        off += len;
        return s;
    });
});

const pct = (n) => (props.clientStatus.total ? (n / props.clientStatus.total) * 100 : 0) + '%';
const changeMonth = (e) => router.get(route('dashboard'), { month: e.target.value }, { preserveScroll: true });
const go = (name, data = {}) => router.get(route(name), data);
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />

        <div class="top">
            <div>
                <h1>Management dashboard</h1>
                <div class="sub">Overview of billing, payments, receivables and key performance indicators.</div>
            </div>
            <div class="tools" style="margin: 0">
                <select :value="month" aria-label="Month" @change="changeMonth">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <a v-if="can('view-reports')" class="chip on" :href="route('reports.download', { report: 'collection', month })">Download report</a>
            </div>
        </div>

        <div class="grid kpis">
            <div v-for="k in kpiCards" :key="k[0]" class="card kpi" :style="{ '--c': k[3] }">
                <div class="l">{{ k[0] }}</div>
                <div class="v">{{ k[1] }}</div>
                <div class="d">{{ k[2] }}</div>
            </div>
        </div>

        <div class="grid row2">
            <div class="card">
                <h2>Revenue collection trend</h2>
                <svg :viewBox="`0 0 ${W} ${H}`" width="100%" role="img" aria-label="Billed versus collected, last six months">
                    <g v-for="t in ticks" :key="t">
                        <line :x1="P" :x2="W - 6" :y1="y(t)" :y2="y(t)" stroke="var(--line)" />
                        <text :x="P - 5" :y="y(t) + 4" font-size="10" text-anchor="end" fill="var(--mute)">{{ Math.round(t * 10) / 10 }}M</text>
                    </g>
                    <g v-for="(d, i) in trend" :key="d.month">
                        <rect :x="x(i) - BW" :y="y(d.billed / 1e6)" :width="BW - 1" :height="H - 24 - y(d.billed / 1e6)" fill="var(--blue)" rx="2"><title>Billed {{ N(d.billed) }}</title></rect>
                        <rect :x="x(i)" :y="y(d.collected / 1e6)" :width="BW - 1" :height="H - 24 - y(d.collected / 1e6)" fill="var(--green)" rx="2"><title>Collected {{ N(d.collected) }}</title></rect>
                        <text :x="x(i)" :y="H - 8" font-size="10.5" text-anchor="middle" fill="var(--mute)">{{ d.label }}</text>
                    </g>
                    <polyline :points="ratePoints" fill="none" stroke="var(--ink)" stroke-width="1.5" />
                    <circle v-for="(d, i) in trend" :key="'c' + i" :cx="x(i)" :cy="rateY(d)" r="2.5" fill="var(--ink)" />
                </svg>
                <div class="legend">
                    <span><i class="dot" style="background: var(--blue)"></i>Billed</span>
                    <span><i class="dot" style="background: var(--green)"></i>Collected</span>
                    <span><i class="dot" style="background: var(--ink)"></i>Collection rate</span>
                </div>
            </div>
            <div class="card">
                <h2>Receivables aging</h2>
                <div class="donutwrap">
                    <svg viewBox="0 0 140 140" width="150" role="img" aria-label="Receivables aging">
                        <g transform="rotate(-90 70 70)">
                            <circle cx="70" cy="70" :r="R" fill="none" stroke="var(--line)" stroke-width="18" />
                            <circle v-for="(s, i) in slices" :key="i" cx="70" cy="70" :r="R" fill="none" :stroke="s.color" stroke-width="18" :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset" />
                        </g>
                        <text x="70" y="68" text-anchor="middle" font-size="12" font-weight="700" fill="var(--ink)">{{ N(total) }}</text>
                        <text x="70" y="82" text-anchor="middle" font-size="9" fill="var(--mute)">Total outstanding</text>
                    </svg>
                    <div style="display: grid; gap: 6px">
                        <div v-for="a in aging" :key="a.label" style="font-size: 12px">
                            <i class="dot" :style="{ background: a.color }"></i>{{ a.label }}<br />
                            <span class="sub" style="font-size: 12px">{{ N(a.amount) }} · {{ total ? Math.round((a.amount / total) * 100) : 0 }}%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid row3">
            <div class="card scroll">
                <h2>Top 5 outstanding facility users</h2>
                <table>
                    <tr><th>Facility user</th><th>Outstanding</th><th>Days overdue</th><th>Status</th></tr>
                    <tr v-for="t in topClients" :key="t.occupancy_id">
                        <td>{{ t.name }}<div class="sub small">{{ t.place }}</div></td><td>{{ N(t.total) }}</td><td>{{ t.days_overdue }}</td>
                        <td><StatusPill :status="t.days_overdue > 0 ? 'Overdue' : 'Unpaid'" /></td>
                    </tr>
                    <tr v-if="!topClients.length"><td colspan="4" class="sub">Nothing outstanding.</td></tr>
                </table>
            </div>
            <div class="card scroll">
                <h2>Recent payments</h2>
                <table>
                    <tr><th>Date</th><th>Facility user</th><th>Amount</th><th>Method</th><th>Status</th></tr>
                    <tr v-for="p in recentPayments" :key="p.id"><td>{{ p.date }}</td><td>{{ p.client }}</td><td>{{ N(p.amount) }}</td><td>{{ p.method }}</td><td><StatusPill :status="p.status" /></td></tr>
                    <tr v-if="!recentPayments.length"><td colspan="5" class="sub">No payments yet.</td></tr>
                </table>
            </div>
            <div class="card">
                <h2>Quick actions</h2>
                <div class="qa">
                    <button v-if="can('register-facility-users')" @click="go('facility-users', { new: 1 })">Register facility user</button>
                    <button v-if="can('record-payments')" @click="go('payments', { record: 1 })">Record payment</button>
                    <button @click="go('reports')">View reports</button>
                    <button @click="go('receivables')">Chase debtors</button>
                    <button @click="go('facilities')">Facilities</button>
                </div>
                <h2 style="margin-top: 14px">Attention required</h2>
                <ul class="att">
                    <li v-for="a in attention" :key="a[0]"><Link :href="route(a[2])" style="text-decoration: none">{{ a[0] }}</Link><b>{{ a[1] }}</b></li>
                </ul>
            </div>
        </div>

        <div class="card" style="margin-top: 14px">
            <h2>Payment status ({{ clientStatus.total }} active facility users)</h2>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                <div class="bars" style="width: min(100%, 320px)">
                    <i :style="{ width: pct(clientStatus.green), background: 'var(--green)' }"></i>
                    <i :style="{ width: pct(clientStatus.amber), background: 'var(--amber)' }"></i>
                    <i :style="{ width: pct(clientStatus.red), background: 'var(--red)' }"></i>
                </div>
                <div class="legend">
                    <span><i class="dot" style="background: var(--green)"></i>Up to date {{ clientStatus.green }}</span>
                    <span><i class="dot" style="background: var(--amber)"></i>1 month overdue {{ clientStatus.amber }}</span>
                    <span><i class="dot" style="background: var(--red)"></i>More than 1 overdue {{ clientStatus.red }}</span>
                </div>
                <button class="chip" @click="go('facility-users', { status: 'red' })">See who to chase</button>
            </div>
        </div>

        <div class="ins">
            <div class="card"><div class="sub">Collection rate</div><div class="v">{{ insights.rate }}%</div></div>
            <div class="card"><div class="sub">Average days to payment</div><div class="v">{{ insights.daysToPay ?? '—' }} days</div></div>
            <div class="card"><div class="sub">Unpaid invoices</div><div class="v">{{ insights.unpaid }}</div></div>
            <div class="card"><div class="sub">At-risk facility users</div><div class="v">{{ insights.atRisk }}</div></div>
        </div>
    </AppLayout>
</template>
