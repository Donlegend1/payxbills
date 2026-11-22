<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import HBars from '@/Components/HBars.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';
import { N, today } from '@/format';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({
    memos: Object, filters: Object, month: String, monthLabel: String, months: Array, categories: Array,
    byCategory: Array, facilitySpend: Array, facilities: Array, kpis: Object,
});

const can = useCan();
const { filters, apply } = useFilters('expenditure', { month: props.month, status: props.filters.status ?? '' });

const kpiCards = computed(() => [
    ['Approved memos', N(props.kpis.approved), `${props.kpis.approvedCount} memos in ${props.monthLabel}`, 'var(--purple)'],
    ['Awaiting approval', N(props.kpis.pending), `${props.kpis.pendingCount} pending memos`, 'var(--amber)'],
    ['Dues billed', N(props.kpis.income), `all facilities, ${props.monthLabel}`, 'var(--green)'],
]);

const margin = (c) => (c.income ? Math.round(((c.income - c.spend) / c.income) * 100) : 0);
const tone = (m) => (m >= 35 ? 'green' : m >= 30 ? 'amber' : 'red');

const form = useForm({ spent_on: today(), description: '', category: props.categories[0], facility_id: '', amount: '' });
const show = ref(false);
const save = () => form.transform((d) => ({ ...d, facility_id: d.facility_id || null }))
    .post(route('expenditure.store'), { preserveScroll: true, onSuccess: () => { show.value = false; form.reset(); } });

const review = (m, action) => router.post(route(`expenditure.${action}`, m.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="Expenditure" />

        <PageHeader title="Expenditure" sub="Memos are categorised and charged to a facility, so spending shows against the dues it brings in.">
            <div class="actions">
                <select v-model="filters.month" aria-label="Month" @change="apply()">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <button v-if="can('create-memos')" class="chip on" @click="show = true">New memo</button>
            </div>
        </PageHeader>

        <KpiCards :items="kpiCards" />

        <div class="grid auto mb">
            <div class="card">
                <h2>Approved spend by category</h2>
                <HBars :items="byCategory" :format="N" color="var(--purple)" />
            </div>
            <div class="card scroll">
                <h2>Dues and spend by facility ({{ monthLabel }})</h2>
                <table style="min-width: 420px">
                    <tr><th>Facility</th><th>Dues billed</th><th>Spend</th><th>Margin</th></tr>
                    <tr v-for="c in facilitySpend" :key="c.name">
                        <td>{{ c.name }}</td><td>{{ N(c.income) }}</td><td>{{ N(c.spend) }}</td>
                        <td><span class="pill" :class="'s-' + tone(margin(c))">{{ margin(c) }}%</span></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="tools">
            <button v-for="s in [['', 'All'], ['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected']]" :key="s[0]" class="chip" :class="{ on: filters.status === s[0] }" @click="filters.status = s[0]; apply()">{{ s[1] }}</button>
        </div>

        <div class="card scroll">
            <table v-if="memos.data.length" style="min-width: 920px">
                <tr><th>Memo</th><th>Date</th><th>Description</th><th>Category</th><th>Charged to facility</th><th>Amount</th><th>Status</th><th></th></tr>
                <tr v-for="m in memos.data" :key="m.id">
                    <td>{{ m.memo }}</td><td>{{ m.date }}</td>
                    <td>{{ m.description }}<div class="sub small">by {{ m.requested_by }}</div></td>
                    <td>{{ m.category }}</td><td>{{ m.facility }}</td><td>{{ N(m.amount) }}</td>
                    <td><StatusPill :status="m.status" /></td>
                    <td class="actions">
                        <template v-if="m.status === 'Pending' && can('approve-memos')">
                            <button class="chip on" @click="review(m, 'approve')">Approve</button>
                            <button class="chip" @click="review(m, 'reject')">Reject</button>
                        </template>
                    </td>
                </tr>
            </table>
            <div v-else class="empty">No memos for {{ monthLabel }}.</div>
            <Pagination :page="memos" />
        </div>

        <Modal :show="show" title="New expense memo" @close="show = false">
            <form class="grid" @submit.prevent="save">
                <label class="field">Description<input v-model="form.description" required /><InputError :message="form.errors.description" /></label>
                <div class="form" style="margin: 0">
                    <label class="field">Category
                        <select v-model="form.category"><option v-for="c in categories" :key="c">{{ c }}</option></select>
                    </label>
                    <label class="field">Amount (₦)<input v-model="form.amount" type="number" min="0.01" step="0.01" required /><InputError :message="form.errors.amount" /></label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">Charged to facility
                        <select v-model="form.facility_id"><option value="">Not charged to a facility</option><option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }}</option></select>
                    </label>
                    <label class="field">Date<input v-model="form.spent_on" type="date" :max="today()" required /><InputError :message="form.errors.spent_on" /></label>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="show = false">Cancel</button>
                    <button class="btn" :disabled="form.processing">Submit memo</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
