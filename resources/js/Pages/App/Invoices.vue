<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';
import { N } from '@/format';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({ invoices: Object, filters: Object, months: Array, facilities: Array, nrsEnabled: Boolean, kpis: Object });

const can = useCan();
const { filters, apply } = useFilters('invoices', { status: props.filters.status ?? '', period: props.filters.period ?? '', facility: props.filters.facility ?? '', q: props.filters.q ?? '' });

const kpiCards = computed(() => [
    [`Billed (${props.kpis.monthLabel})`, N(props.kpis.billed), `${props.kpis.invoices} monthly invoices`],
    ['Collected', N(props.kpis.collected), `${props.kpis.rate}% of this month's dues paid`, 'var(--green)'],
    ['Unpaid invoices', props.kpis.open, 'across all months', 'var(--red)'],
]);

const generate = () => {
    if (confirm("Issue this month's dues for every active facility user who doesn't have them yet, and send the payment-due email and SMS?")) {
        router.post(route('invoices.generate'), {}, { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout>
        <Head title="Invoices" />

        <PageHeader title="Invoices" sub="One invoice per facility user per month, at their category's amount. Months paid ahead show as paid.">
            <button v-if="can('manage-invoices')" class="chip on" @click="generate">Run monthly billing now</button>
        </PageHeader>

        <KpiCards :items="kpiCards" />

        <div class="tools">
            <button v-for="s in [['', 'All'], ['paid', 'Paid'], ['partial', 'Partial'], ['unpaid', 'Unpaid'], ['overdue', 'Overdue'], ['cancelled', 'Cancelled']]" :key="s[0]" class="chip" :class="{ on: filters.status === s[0] }" @click="filters.status = s[0]; apply()">{{ s[1] }}</button>
            <select v-model="filters.period" aria-label="Month" @change="apply()">
                <option value="">All months</option>
                <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
            <select v-model="filters.facility" aria-label="Facility" @change="apply()">
                <option value="">All facilities</option>
                <option v-for="f in facilities" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
            </select>
            <input v-model="filters.q" type="search" placeholder="Search invoice, name or account" aria-label="Search invoices" @input="apply(300)" />
        </div>

        <div class="card scroll">
            <table v-if="invoices.data.length" style="min-width: 980px">
                <tr><th>Invoice</th><th>Facility user</th><th>Month</th><th>Amount</th><th>Paid</th><th>Due</th><th>Status</th><th>Sent</th><th v-if="nrsEnabled">NRS copy</th></tr>
                <tr v-for="i in invoices.data" :key="i.id" class="clickable" @click="router.get(route('invoices.show', i.id))">
                    <td>{{ i.number }}</td>
                    <td>{{ i.client }}<div class="sub small">{{ i.place }}</div></td>
                    <td>{{ i.month }}</td><td>{{ N(i.amount) }}</td><td>{{ N(i.paid) }}</td><td>{{ i.due }}</td>
                    <td><StatusPill :status="i.status" :tone="i.status === 'Cancelled' ? 'red' : ''" /></td>
                    <td><StatusPill :status="i.sent ? 'Sent' : 'Not sent'" /></td>
                    <td v-if="nrsEnabled">{{ i.nrs_copy ? 'Yes' : '—' }}</td>
                </tr>
            </table>
            <div v-else class="empty">No invoices match these filters.</div>
            <Pagination :page="invoices" />
        </div>
    </AppLayout>
</template>
