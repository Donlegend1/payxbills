<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';
import { N, today } from '@/format';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({ payments: Object, filters: Object, methods: Object, occupancies: Array, kpis: Object });

const can = useCan();
const { filters, apply } = useFilters('payments', { status: props.filters.status ?? '', q: props.filters.q ?? '' });

const kpiCards = computed(() => [
    ['Awaiting clearance', props.kpis.awaiting, 'manual payments to check', 'var(--amber)'],
    ['Confirmed this month', N(props.kpis.confirmed), `${props.kpis.rate}% of this month's dues paid`, 'var(--green)'],
    ['Paid online', props.kpis.onlineShare + '%', "of this month's confirmed payments"],
]);

// Record a manual payment. ?record=<occupancy id> opens the form for that facility user.
const record = new URLSearchParams(window.location.search).get('record');
const form = useForm({ occupancy_id: '', months: 1, amount: '', method: 'cash', reference: '', paid_on: today(), files: [] });
const show = ref(!!record && can('record-payments'));
const chosen = computed(() => props.occupancies.find((o) => o.id === Number(form.occupancy_id)));
const suggest = () => chosen.value && form.months && (form.amount = chosen.value.monthly * form.months);
if (record && props.occupancies.some((o) => o.id === Number(record))) {
    form.occupancy_id = Number(record);
    suggest();
}

const save = () => form.post(route('payments.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { show.value = false; form.reset(); } });

// Accountant clears with a reference and proof
const clearing = ref(null);
const clearForm = useForm({ reference: '', files: [] });
function openClear(p) {
    clearing.value = p;
    clearForm.reset();
    clearForm.clearErrors();
    clearForm.reference = p.reference ?? '';
}
const clear = () => clearForm.post(route('payments.clear', clearing.value.id), { forceFormData: true, preserveScroll: true, onSuccess: () => (clearing.value = null) });

const rejecting = ref(null);
const rejectForm = useForm({ reason: '' });
const reject = () => rejectForm.post(route('payments.reject', rejecting.value.id), { preserveScroll: true, onSuccess: () => { rejecting.value = null; rejectForm.reset(); } });

const statuses = [['', 'All'], ['under_review', 'Awaiting clearance'], ['pending', 'Online, not paid yet'], ['confirmed', 'Confirmed'], ['rejected', 'Rejected']];
</script>

<template>
    <AppLayout>
        <Head title="Payments" />

        <PageHeader title="Payments" sub="Online payments confirm automatically. Manual payments count only after an accountant clears them with a reference and proof.">
            <button v-if="can('record-payments')" class="chip on" @click="show = true">Record manual payment</button>
        </PageHeader>

        <KpiCards :items="kpiCards" />

        <div class="tools">
            <button v-for="s in statuses" :key="s[0]" class="chip" :class="{ on: filters.status === s[0] }" @click="filters.status = s[0]; apply()">{{ s[1] }}</button>
            <input v-model="filters.q" type="search" placeholder="Search name, account, receipt or reference" aria-label="Search payments" style="min-width: 260px" @input="apply(300)" />
        </div>

        <div class="card scroll">
            <table v-if="payments.data.length" style="min-width: 1000px">
                <tr><th>Date</th><th>Facility user</th><th>Amount</th><th>Method</th><th>Recorded by</th><th>Covers</th><th>Proof</th><th>Status</th><th></th></tr>
                <tr v-for="p in payments.data" :key="p.id">
                    <td>{{ p.date }}</td>
                    <td>{{ p.client }}<div class="sub small">{{ p.place }}</div></td>
                    <td>{{ N(p.amount) }}<div v-if="p.months" class="sub small">{{ p.months }} month{{ p.months > 1 ? 's' : '' }}</div></td>
                    <td>{{ p.method }}<div v-if="p.reference" class="sub small">Ref {{ p.reference }}</div></td>
                    <td>{{ p.source_label }}</td>
                    <td class="small">{{ p.covers.join(', ') || '—' }}</td>
                    <td class="small">
                        <div v-for="a in p.attachments" :key="a.id" style="white-space: nowrap">
                            <a :href="route('payments.attachment', a.id)" target="_blank" rel="noopener">{{ a.name }}</a>
                            <a :href="route('payments.attachment', { attachment: a.id, download: 1 })" class="sub small" style="margin-left: 6px">Download</a>
                        </div>
                        <span v-if="!p.attachments.length" class="sub">—</span>
                    </td>
                    <td>
                        <StatusPill :status="p.status" :tone="p.status_key === 'pending' ? 'amber' : ''" />
                        <div v-if="p.rejection_reason" class="sub small">{{ p.rejection_reason }}</div>
                    </td>
                    <td class="actions">
                        <template v-if="p.status_key === 'under_review' && can('clear-payments')">
                            <button class="chip on" @click="openClear(p)">Clear</button>
                            <button class="chip" @click="rejecting = p">Reject</button>
                        </template>
                        <Link v-if="p.receipt" class="chip" :href="route('receipts.show', p.id)">{{ p.receipt }}</Link>
                    </td>
                </tr>
            </table>
            <div v-else class="empty">No payments match these filters.</div>
            <Pagination :page="payments" />
        </div>

        <Modal :show="show" title="Record manual payment" @close="show = false">
            <form class="grid" @submit.prevent="save">
                <label class="field">Facility user
                    <select v-model="form.occupancy_id" required @change="suggest"><option value="" disabled>Choose…</option><option v-for="o in occupancies" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <InputError :message="form.errors.occupancy_id" />
                </label>
                <div v-if="chosen" class="sub small">{{ N(chosen.monthly) }} a month · {{ chosen.outstanding > 0 ? N(chosen.outstanding) + ' outstanding' : 'nothing outstanding' }}</div>
                <div class="form" style="margin: 0">
                    <label class="field">Months paid for<input v-model.number="form.months" type="number" min="1" max="120" @input="suggest" /><InputError :message="form.errors.months" /></label>
                    <label class="field">Amount (₦)<input v-model="form.amount" type="number" min="0.01" step="0.01" required /><InputError :message="form.errors.amount" /></label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">Method
                        <select v-model="form.method"><option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option></select>
                    </label>
                    <label class="field">Date paid<input v-model="form.paid_on" type="date" :max="today()" required /><InputError :message="form.errors.paid_on" /></label>
                </div>
                <label class="field">Reference (teller, transfer or cheque no.)<input v-model="form.reference" maxlength="100" /><InputError :message="form.errors.reference" /></label>
                <label class="field">Proof of payment (optional now, required to clear)
                    <input type="file" multiple accept="image/*,application/pdf" @change="form.files = [...$event.target.files]" />
                    <InputError :message="form.errors.files || form.errors['files.0']" />
                </label>
                <p class="sub small" style="margin: 0">The payment counts, and a receipt is sent, once an accountant clears it.</p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="show = false">Cancel</button>
                    <button class="btn" :disabled="form.processing">Submit for clearance</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!clearing" :title="`Clear ${clearing ? N(clearing.amount) : ''} from ${clearing?.client ?? ''}`" @close="clearing = null">
            <form v-if="clearing" class="grid" @submit.prevent="clear">
                <p class="sub" style="margin: 0">{{ clearing.method }} on {{ clearing.date }}<template v-if="clearing.months"> for {{ clearing.months }} month(s)</template>. Check it against the bank statement before clearing.</p>
                <label class="field">Bank / teller reference<input v-model="clearForm.reference" required maxlength="100" /><InputError :message="clearForm.errors.reference" /></label>
                <div v-if="clearing.attachments.length" class="sub small">On file: <span v-for="a in clearing.attachments" :key="a.id" style="margin-right: 10px"><a :href="route('payments.attachment', a.id)" target="_blank" rel="noopener">{{ a.name }}</a> (<a :href="route('payments.attachment', { attachment: a.id, download: 1 })">download</a>)</span></div>
                <label class="field">{{ clearing.attachments.length ? 'Add more proof (optional)' : 'Attach proof (required)' }}
                    <input type="file" multiple accept="image/*,application/pdf" :required="!clearing.attachments.length" @change="clearForm.files = [...$event.target.files]" />
                    <InputError :message="clearForm.errors.files || clearForm.errors['files.0']" />
                </label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="clearing = null">Cancel</button>
                    <button class="btn" :disabled="clearForm.processing">Clear and send receipt</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!rejecting" :title="`Reject payment from ${rejecting?.client ?? ''}`" @close="rejecting = null">
            <form class="grid" @submit.prevent="reject">
                <label class="field">Reason (shown to the facility user and whoever recorded it)<input v-model="rejectForm.reason" required placeholder="e.g. Not found on the bank statement" /><InputError :message="rejectForm.errors.reason" /></label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="rejecting = null">Cancel</button>
                    <button class="btn" :disabled="rejectForm.processing">Reject payment</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
