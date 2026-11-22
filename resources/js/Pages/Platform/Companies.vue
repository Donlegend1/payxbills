<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { N } from '@/format';

const props = defineProps({ companies: Array, totals: Object, monthLabel: String });

const q = ref('');
const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? props.companies.filter((c) => [c.name, c.email, c.slug].some((v) => v?.toLowerCase().includes(s))) : props.companies;
});

const kpis = computed(() => [
    ['Companies', props.totals.companies, `${props.totals.active} active`],
    ['Facility users', props.totals.facility_users.toLocaleString(), 'Across all companies', 'var(--green)'],
    [`Collected in ${props.monthLabel}`, N(props.totals.collected), 'Confirmed payments', 'var(--amber)'],
]);

function toggle(c) {
    const action = c.is_active ? `Suspend ${c.name}? Its staff and facility users will not be able to log in, and its pay links will stop working.` : `Reactivate ${c.name}?`;
    if (confirm(action)) router.put(route('platform.companies.update', c.id), { is_active: !c.is_active }, { preserveScroll: true });
}

const impersonate = (c) => router.post(route('platform.companies.impersonate', c.id));
</script>

<template>
    <AppLayout>
        <Head title="Companies" />

        <PageHeader title="Companies" sub="Every company using PayXbills. Companies register themselves from the website.">
            <input v-model="q" type="search" placeholder="Search companies" aria-label="Search companies" style="max-width: 240px" />
        </PageHeader>

        <KpiCards :items="kpis" />

        <div class="card scroll">
            <table v-if="shown.length" style="min-width: 860px">
                <tr><th>Company</th><th>Facilities</th><th>Facility users</th><th>Staff</th><th>Collected ({{ monthLabel }})</th><th>Paystack</th><th>Status</th><th></th></tr>
                <tr v-for="c in shown" :key="c.id">
                    <td>
                        <b>{{ c.name }}</b>
                        <div class="sub small">{{ c.email || '—' }}<template v-if="c.phone"> · {{ c.phone }}</template> · joined {{ c.joined }}</div>
                    </td>
                    <td>{{ c.facilities }}</td>
                    <td>{{ c.facility_users }}</td>
                    <td>{{ c.staff }}</td>
                    <td>{{ N(c.collected) }}</td>
                    <td><span class="sub small">{{ c.own_paystack ? 'Own account' : 'Platform' }}</span></td>
                    <td><StatusPill :status="c.is_active ? 'Active' : 'Suspended'" :tone="c.is_active ? 'green' : 'red'" /></td>
                    <td class="actions" style="white-space: nowrap">
                        <button class="chip" @click="impersonate(c)">Log in as admin</button>
                        <button class="chip" @click="toggle(c)">{{ c.is_active ? 'Suspend' : 'Reactivate' }}</button>
                    </td>
                </tr>
            </table>
            <div v-else class="empty">{{ q ? 'No company matches your search.' : 'No companies yet. They appear here when they register.' }}</div>
        </div>
    </AppLayout>
</template>
