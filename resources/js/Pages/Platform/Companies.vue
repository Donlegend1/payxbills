<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { N } from '@/format';

// Super admin overview: every company on PayXbills and how its billing is going.
const props = defineProps({ companies: Array, totals: Object, monthLabel: String });

const q = ref('');
const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? props.companies.filter((c) => [c.name, c.email, c.slug].some((v) => v?.toLowerCase().includes(s))) : props.companies;
});

const kpis = computed(() => [
    ['Companies', props.totals.companies, `${props.totals.active} active`],
    ['Facility users', props.totals.facility_users.toLocaleString(), 'Across all companies', 'var(--green)'],
    [`Billed in ${props.monthLabel}`, N(props.totals.billed), 'All companies', 'var(--blue)'],
    [`Collected in ${props.monthLabel}`, N(props.totals.collected), 'Confirmed payments', 'var(--amber)'],
    ['Outstanding', N(props.totals.outstanding), 'Due and unpaid', 'var(--red)'],
]);

const lists = [
    ['platform.facility-users', 'Facility users'],
    ['platform.payments', 'Payments'],
    ['platform.invoices', 'Invoices'],
    ['platform.facilities', 'Facilities'],
    ['platform.categories', 'Categories'],
];

function toggle(c) {
    const action = c.is_active ? `Suspend ${c.name}? Its staff and facility users will not be able to log in, and its pay links will stop working.` : `Reactivate ${c.name}?`;
    if (confirm(action)) router.put(route('platform.companies.update', c.id), { is_active: !c.is_active }, { preserveScroll: true });
}

const open = (c) => router.post(route('platform.companies.open', c.id));
const impersonate = (c) => router.post(route('platform.companies.impersonate', c.id));
</script>

<template>
    <AppLayout>
        <Head title="All companies" />

        <PageHeader title="All companies" sub="You oversee every company on PayXbills. Open a company to manage it with full rights.">
            <input v-model="q" type="search" placeholder="Search companies" aria-label="Search companies" style="max-width: 240px" />
        </PageHeader>

        <KpiCards :items="kpis" />

        <div class="tools">
            <span class="sub">Across all companies:</span>
            <Link v-for="[r, label] in lists" :key="r" :href="route(r)" class="chip">{{ label }}</Link>
        </div>

        <div class="card scroll">
            <table v-if="shown.length" style="min-width: 1100px">
                <tr><th>Company</th><th>Facilities</th><th>Facility users</th><th>Staff</th><th>Billed ({{ monthLabel }})</th><th>Collected</th><th>Outstanding</th><th>Status</th><th></th></tr>
                <tr v-for="c in shown" :key="c.id">
                    <td>
                        <div class="brand-head" style="gap: 10px">
                            <img v-if="c.logo" :src="c.logo" alt="" style="max-height: 28px; max-width: 60px; object-fit: contain" />
                            <div>
                                <b>{{ c.name }}</b>
                                <div class="sub small">{{ c.email || '—' }}<template v-if="c.phone"> · {{ c.phone }}</template> · joined {{ c.joined }} · Paystack: {{ c.own_paystack ? 'own account' : 'platform' }}</div>
                            </div>
                        </div>
                    </td>
                    <td>{{ c.facilities }}</td>
                    <td>{{ c.facility_users }}<div class="sub small">{{ c.billed_users }} billed</div></td>
                    <td>{{ c.staff }}</td>
                    <td>{{ N(c.billed) }}</td>
                    <td>{{ N(c.collected) }}<div v-if="c.awaiting" class="sub small">{{ c.awaiting }} awaiting clearance</div></td>
                    <td>{{ N(c.outstanding) }}</td>
                    <td><StatusPill :status="c.is_active ? 'Active' : 'Suspended'" :tone="c.is_active ? 'green' : 'red'" /></td>
                    <td class="actions" style="white-space: nowrap">
                        <button class="chip on" @click="open(c)">Open</button>
                        <button class="chip" @click="impersonate(c)">Log in as admin</button>
                        <button class="chip" @click="toggle(c)">{{ c.is_active ? 'Suspend' : 'Reactivate' }}</button>
                    </td>
                </tr>
            </table>
            <div v-else class="empty">{{ q ? 'No company matches your search.' : 'No companies yet. They appear here when they register.' }}</div>
        </div>
    </AppLayout>
</template>
