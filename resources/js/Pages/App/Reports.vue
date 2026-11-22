<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { N } from '@/format';

const props = defineProps({ reports: Array, months: Array, occupancies: Array, summary: Array });

const month = ref(props.months[0].value);
const occupancy = ref(props.occupancies[0]?.id ?? '');

const href = (key) => route('reports.download', { report: key, month: month.value, ...(key === 'statement' ? { occupancy: occupancy.value } : {}) });
</script>

<template>
    <AppLayout>
        <Head title="Reports" />

        <PageHeader title="Reports" sub="Download any report as a CSV file for the month you pick. Opens in Excel.">
            <select v-model="month" aria-label="Report month">
                <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
        </PageHeader>

        <div class="card mb">
            <div v-for="r in reports" :key="r.key" class="rep">
                <div>
                    <b>{{ r.title }}</b>
                    <div class="sub">{{ r.description }}</div>
                    <select v-if="r.key === 'statement'" v-model="occupancy" aria-label="Facility user" style="margin-top: 6px; max-width: 100%">
                        <option v-for="o in occupancies" :key="o.id" :value="o.id">{{ o.name }}</option>
                    </select>
                </div>
                <a class="chip" :href="href(r.key)">Download CSV</a>
            </div>
        </div>

        <h2 style="margin: 6px 0 10px">Monthly summary</h2>
        <div class="card scroll">
            <table style="min-width: 560px">
                <tr><th>Month</th><th>Invoices</th><th>Billed</th><th>Collected</th><th>Collection rate</th></tr>
                <tr v-for="t in summary" :key="t.month">
                    <td>{{ t.month }}</td>
                    <td>{{ t.invoices }}</td>
                    <td>{{ N(t.billed) }}</td>
                    <td>{{ N(t.collected) }}</td>
                    <td>{{ t.billed ? Math.round((t.collected / t.billed) * 100) + '%' : '—' }}</td>
                </tr>
            </table>
        </div>
    </AppLayout>
</template>
