<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import HBars from '@/Components/HBars.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';
import { N, sum, today } from '@/format';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({
    memos: Object, filters: Object, statuses: Object, month: String, monthLabel: String, months: Array, categories: Array,
    byCategory: Array, facilitySpend: Array, facilities: Array, kpis: Object,
});

const can = useCan();
const { filters, apply } = useFilters('expenditure', { month: props.month, status: props.filters.status ?? '' });

const kpiCards = computed(() => [
    ['Approved spend', N(props.kpis.approved), `${props.kpis.approvedCount} memos in ${props.monthLabel}`, 'var(--purple)'],
    ['Awaiting a decision', N(props.kpis.pending), `${props.kpis.pendingCount} pending or KIV`, 'var(--amber)'],
    ['Dues billed', N(props.kpis.income), `all facilities, ${props.monthLabel}`, 'var(--green)'],
]);

const margin = (c) => (c.income ? Math.round(((c.income - c.spend) / c.income) * 100) : 0);
const tone = (m) => (m >= 35 ? 'green' : m >= 30 ? 'amber' : 'red');
const statusTone = { pending: 'amber', kiv: 'amber', approved: 'green', partially_approved: 'green', rejected: 'red' };

// New memo: one category unless the memo covers several
const blankLine = (i = 0) => ({ category: props.categories[i] ?? props.categories[0], amount: '' });
const form = useForm({ spent_on: today(), description: '', facility_id: '', lines: [blankLine()], memo: null });
const count = ref(1);
const show = ref(false);
const memoInput = ref(null);
watch(count, (n) => {
    n = Math.max(1, Math.min(10, Number(n) || 1));
    while (form.lines.length < n) form.lines.push(blankLine(form.lines.length));
    form.lines.splice(n);
});
const requested = computed(() => sum(form.lines, (l) => Number(l.amount) || 0));
function openNew() {
    form.reset();
    form.clearErrors();
    count.value = 1;
    show.value = true;
}
const save = () => form.transform((d) => ({ ...d, facility_id: d.facility_id || null }))
    .post(route('expenditure.store'), { preserveScroll: true, forceFormData: true, onSuccess: () => { show.value = false; form.reset(); count.value = 1; } });

// Review: approve, partially approve, KIV or reject
const reviewing = ref(null);
const reviewForm = useForm({ decision: 'approved', note: '', lines: [] });
function review(m) {
    reviewing.value = m;
    reviewForm.clearErrors();
    reviewForm.decision = 'approved';
    reviewForm.note = '';
    reviewForm.lines = m.lines.map((l) => ({ id: l.id, category: l.category, amount: l.amount, approve: true, approved_amount: l.amount }));
}
const approvedTotal = computed(() => sum(reviewForm.lines, (l) => (l.approve ? Number(l.approved_amount) || 0 : 0)));
const decide = () => reviewForm
    .transform((d) => ({ ...d, lines: d.lines.map((l) => ({ id: l.id, approved_amount: l.approve ? l.approved_amount : null })) }))
    .post(route('expenditure.review', reviewing.value.id), { preserveScroll: true, onSuccess: () => (reviewing.value = null) });
</script>

<template>
    <AppLayout>
        <Head title="Expenditure" />

        <PageHeader title="Expenditure" sub="Memos are categorised and charged to a facility. Only approved amounts count as spend.">
            <div class="actions">
                <select v-model="filters.month" aria-label="Month" @change="apply()">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <button v-if="can('create-memos')" class="chip on" @click="openNew">New memo</button>
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
            <button class="chip" :class="{ on: filters.status === '' }" @click="filters.status = ''; apply()">All</button>
            <button v-for="(label, key) in statuses" :key="key" class="chip" :class="{ on: filters.status === key }" @click="filters.status = key; apply()">{{ label }}</button>
        </div>

        <div class="card scroll">
            <table v-if="memos.data.length" style="min-width: 1000px">
                <tr><th>Memo</th><th>Date</th><th>Description</th><th>Categories</th><th>Facility</th><th>Requested</th><th>Approved</th><th>Status</th><th></th></tr>
                <tr v-for="m in memos.data" :key="m.id">
                    <td>
                        {{ m.memo }}
                        <div v-if="m.picture" class="small"><a :href="m.picture" target="_blank" rel="noopener">View memo</a></div>
                    </td>
                    <td>{{ m.date }}</td>
                    <td>{{ m.description }}<div class="sub small">by {{ m.requested_by }}</div></td>
                    <td>
                        <div v-for="l in m.lines" :key="l.id" class="small" style="white-space: nowrap">
                            <span :style="m.status_key === 'partially_approved' && l.approved_amount === null ? 'text-decoration: line-through; color: var(--mute)' : ''">{{ l.category }}: {{ N(l.amount) }}</span>
                            <span v-if="m.status_key === 'partially_approved' && l.approved_amount !== null && l.approved_amount !== l.amount" class="sub"> → {{ N(l.approved_amount) }}</span>
                        </div>
                    </td>
                    <td>{{ m.facility }}</td>
                    <td>{{ N(m.amount) }}</td>
                    <td>{{ m.approved_amount === null ? '—' : N(m.approved_amount) }}</td>
                    <td>
                        <StatusPill :status="m.status" :tone="statusTone[m.status_key]" />
                        <div v-if="m.review_note" class="sub small" style="max-width: 200px">"{{ m.review_note }}"<template v-if="m.reviewed_by"> – {{ m.reviewed_by }}</template></div>
                    </td>
                    <td class="actions">
                        <button v-if="m.reviewable && can('approve-memos')" class="chip on" @click="review(m)">{{ m.status_key === 'kiv' ? 'Decide' : 'Review' }}</button>
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
                    <label class="field">Charged to facility
                        <select v-model="form.facility_id"><option value="">Not charged to a facility</option><option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }}</option></select>
                    </label>
                    <label class="field">Date<input v-model="form.spent_on" type="date" :max="today()" required /><InputError :message="form.errors.spent_on" /></label>
                </div>

                <label class="field" style="max-width: 220px">Number of categories<input v-model.number="count" type="number" min="1" max="10" /></label>
                <div v-for="(l, i) in form.lines" :key="i" class="form" style="margin: 0">
                    <label class="field">{{ form.lines.length > 1 ? `Category ${i + 1}` : 'Category' }}
                        <select v-model="l.category"><option v-for="c in categories" :key="c">{{ c }}</option></select>
                        <InputError :message="form.errors[`lines.${i}.category`]" />
                    </label>
                    <label class="field">{{ form.lines.length > 1 ? 'Amount for this category (₦)' : 'Amount requested (₦)' }}
                        <input v-model="l.amount" type="number" min="0.01" step="0.01" required />
                        <InputError :message="form.errors[`lines.${i}.amount`]" />
                    </label>
                </div>
                <p v-if="form.lines.length > 1" class="sub" style="margin: 0">Amount requested: <b>{{ N(requested) }}</b></p>
                <InputError :message="form.errors.lines" />

                <label class="field">Picture of the memo (optional)
                    <input ref="memoInput" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" @change="form.memo = $event.target.files[0]" />
                    <InputError :message="form.errors.memo" />
                </label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="show = false">Cancel</button>
                    <button class="btn" :disabled="form.processing">Submit memo</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!reviewing" :title="`Memo ${reviewing?.memo ?? ''}`" @close="reviewing = null">
            <form v-if="reviewing" class="grid" @submit.prevent="decide">
                <p style="margin: 0">
                    <b>{{ reviewing.description }}</b> – {{ N(reviewing.amount) }} requested by {{ reviewing.requested_by }}
                    <template v-if="reviewing.picture"> · <a :href="reviewing.picture" target="_blank" rel="noopener">View memo</a></template>
                </p>

                <div class="tools" style="margin: 0">
                    <label v-for="[key, label] in [['approved', 'Approve'], ['partially_approved', 'Partially approve'], ['kiv', 'KIV'], ['rejected', 'Reject']]" :key="key" class="chip" :class="{ on: reviewForm.decision === key }">
                        <input v-model="reviewForm.decision" type="radio" :value="key" hidden />{{ label }}
                    </label>
                </div>

                <template v-if="reviewForm.decision === 'partially_approved'">
                    <p class="sub small" style="margin: 0">Tick the categories you approve and adjust the amounts if needed. Only these are recorded as spend.</p>
                    <table style="min-width: 0">
                        <tr><th></th><th>Category</th><th>Requested</th><th>Approved</th></tr>
                        <tr v-for="l in reviewForm.lines" :key="l.id">
                            <td><input v-model="l.approve" type="checkbox" :aria-label="`Approve ${l.category}`" /></td>
                            <td>{{ l.category }}</td>
                            <td>{{ N(l.amount) }}</td>
                            <td><input v-model="l.approved_amount" type="number" min="0" :max="l.amount" step="0.01" :disabled="!l.approve" style="max-width: 140px" /></td>
                        </tr>
                    </table>
                    <p class="sub" style="margin: 0">Approved amount: <b>{{ N(approvedTotal) }}</b> of {{ N(reviewing.amount) }}</p>
                    <InputError :message="reviewForm.errors.lines" />
                </template>
                <p v-else-if="reviewForm.decision === 'kiv'" class="sub small" style="margin: 0">Keep in view: the memo stays open so it can be decided later.</p>

                <label class="field">{{ ['kiv', 'rejected'].includes(reviewForm.decision) ? 'Reason' : 'Note (optional)' }}
                    <input v-model="reviewForm.note" maxlength="255" :required="['kiv', 'rejected'].includes(reviewForm.decision)" />
                    <InputError :message="reviewForm.errors.note" />
                </label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="reviewing = null">Cancel</button>
                    <button class="btn" :disabled="reviewForm.processing">Save decision</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
