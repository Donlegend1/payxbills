<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';
import { useFilters } from '@/filters';

// One cross-company list for the super admin (facility users, payments, invoices, facilities, categories).
// A cell is text, [main, sub] for two lines, or ['pill', status].
const props = defineProps({ title: String, sub: String, columns: Array, rows: Object, filters: Object, statuses: Object, companies: Array });

const { filters, apply } = useFilters(route().current(), {
    company: props.filters.company ? String(props.filters.company) : '',
    status: props.filters.status ?? '',
    q: props.filters.q ?? '',
});

const open = (companyId) => router.post(route('platform.companies.open', companyId));
const isPill = (c) => Array.isArray(c) && c[0] === 'pill';
</script>

<template>
    <AppLayout>
        <Head :title="`All ${title.toLowerCase()}`" />

        <PageHeader :title="`All ${title.toLowerCase()}`" :sub="sub" />

        <div class="tools">
            <select v-model="filters.company" aria-label="Company" @change="apply()">
                <option value="">All companies</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
            <select v-model="filters.status" aria-label="Status" @change="apply()">
                <option value="">Any status</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <input v-model="filters.q" type="search" placeholder="Search" aria-label="Search" style="min-width: 240px" @input="apply(300)" />
        </div>

        <div class="card scroll">
            <table v-if="rows.data.length" style="min-width: 900px">
                <tr><th>Company</th><th v-for="c in columns" :key="c">{{ c }}</th><th></th></tr>
                <tr v-for="(r, i) in rows.data" :key="i">
                    <td><b>{{ r.company }}</b></td>
                    <td v-for="(cell, j) in r.cells" :key="j">
                        <StatusPill v-if="isPill(cell)" :status="cell[1]" />
                        <template v-else-if="Array.isArray(cell)">{{ cell[0] }}<div v-if="cell[1]" class="sub small">{{ cell[1] }}</div></template>
                        <template v-else>{{ cell }}</template>
                    </td>
                    <td><button class="chip" title="Manage this company" @click="open(r.company_id)">Open company</button></td>
                </tr>
            </table>
            <div v-else class="empty">Nothing matches these filters.</div>
            <Pagination :page="rows" />
        </div>
    </AppLayout>
</template>
