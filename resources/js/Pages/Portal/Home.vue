<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import PayBox from '@/Components/PayBox.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { N, today } from '@/format';

const props = defineProps({ account: Object, options: Array, invoices: Array, payments: Array, bankDetails: String, methods: Object });

const tab = ref('pending');
const pendingInvoices = computed(() => props.invoices.filter((i) => i.balance > 0));
const pendingPayments = computed(() => props.payments.filter((p) => ['pending', 'under_review'].includes(p.status_key)));
const completed = computed(() => props.payments.filter((p) => ['confirmed', 'rejected'].includes(p.status_key)));

const kpis = computed(() => [
    ['Outstanding', N(props.account.outstanding), props.account.overdue ? `${props.account.overdue} month(s) overdue` : 'nothing overdue', props.account.overdue ? 'var(--red)' : 'var(--green)'],
    ['Paid up to', props.account.paid_through ?? '—', props.account.credit > 0 ? `${N(props.account.credit)} credit on account` : 'last month fully paid', 'var(--blue)'],
    ['Monthly amount', N(props.account.monthly), props.account.category, 'var(--purple)'],
]);

// Paid by transfer / POS / cheque: upload proof for the accountant
const showManual = ref(false);
const manual = useForm({ months: 1, amount: '', method: 'bank_transfer', reference: '', paid_on: today(), files: [] });
const manualOption = computed(() => props.options.find((o) => o.months === Number(manual.months)));
function openManual() {
    manual.reset();
    manual.clearErrors();
    manual.amount = props.options[0]?.amount ?? '';
    showManual.value = true;
}
const pickMonths = () => (manual.amount = manualOption.value?.amount ?? manual.amount);
const submitManual = () => manual.post(route('portal.payments.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => (showManual.value = false) });
</script>

<template>
    <AppLayout>
        <Head title="My account" />

        <template v-if="account">
            <PageHeader :title="`Hello ${account.name}`" :sub="`${account.space} · Account ${account.code}`">
                <button class="chip" @click="openManual">I paid by transfer</button>
            </PageHeader>

            <KpiCards :items="kpis" />

            <div class="grid auto">
                <div class="card grid">
                    <h2>Pay online</h2>
                    <PayBox :options="options" :action="route('portal.pay')" :outstanding-months="pendingInvoices.length" />
                </div>
                <div class="card">
                    <h2>Your facility</h2>
                    <dl class="kv">
                        <dt v-if="account.facility.name">Facility</dt><dd v-if="account.facility.name">{{ account.facility.name }}</dd>
                        <dt v-if="account.facility.address">Address</dt><dd v-if="account.facility.address">{{ account.facility.address }}</dd>
                        <dt v-if="account.facility.phone">Phone</dt><dd v-if="account.facility.phone">{{ account.facility.phone }}</dd>
                        <dt>Place</dt><dd>{{ account.space }}</dd>
                        <dt v-if="account.service">Service</dt><dd v-if="account.service">{{ account.service }}</dd>
                        <dt>Monthly amount</dt><dd><template v-if="account.category">{{ account.category }} – </template>{{ N(account.monthly) }}</dd>
                    </dl>
                    <template v-if="bankDetails">
                        <h2 style="margin-top: 14px">Bank transfer</h2>
                        <div style="white-space: pre-line" class="sub">{{ bankDetails }}</div>
                        <p class="sub small">Quote {{ account.code }}, then click "I paid by transfer" to upload your proof.</p>
                    </template>
                </div>
            </div>

            <div class="tools" style="margin-top: 14px">
                <button class="chip" :class="{ on: tab === 'pending' }" @click="tab = 'pending'">Pending ({{ pendingInvoices.length + pendingPayments.length }})</button>
                <button class="chip" :class="{ on: tab === 'completed' }" @click="tab = 'completed'">Completed ({{ completed.length }})</button>
                <button class="chip" :class="{ on: tab === 'months' }" @click="tab = 'months'">All months</button>
            </div>

            <div v-if="tab === 'pending'" class="grid">
                <div class="card scroll">
                    <h2>Unpaid months</h2>
                    <table v-if="pendingInvoices.length" style="min-width: 520px">
                        <tr><th>Month</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Due</th><th>Status</th></tr>
                        <tr v-for="i in pendingInvoices" :key="i.id"><td>{{ i.month }}</td><td>{{ N(i.amount) }}</td><td>{{ N(i.paid) }}</td><td>{{ N(i.balance) }}</td><td>{{ i.due }}</td><td><StatusPill :status="i.status" /></td></tr>
                    </table>
                    <p v-else class="sub">You are up to date. Thank you!</p>
                </div>
                <div v-if="pendingPayments.length" class="card scroll">
                    <h2>Payments being processed</h2>
                    <table style="min-width: 520px">
                        <tr><th>Date</th><th>Amount</th><th>Months</th><th>Method</th><th>Status</th></tr>
                        <tr v-for="p in pendingPayments" :key="p.id">
                            <td>{{ p.date }}</td><td>{{ N(p.amount) }}</td><td>{{ p.months ?? '—' }}</td><td>{{ p.method }}<div v-if="p.reference" class="sub small">{{ p.reference }}</div></td>
                            <td><StatusPill :status="p.status" tone="amber" /></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div v-else-if="tab === 'completed'" class="card scroll">
                <table v-if="completed.length" style="min-width: 640px">
                    <tr><th>Date</th><th>Amount</th><th>Covers</th><th>Method</th><th>Status</th><th></th></tr>
                    <tr v-for="p in completed" :key="p.id">
                        <td>{{ p.date }}</td>
                        <td>{{ N(p.amount) }}</td>
                        <td>{{ p.covers.join(', ') || '—' }}</td>
                        <td>{{ p.method }}</td>
                        <td><StatusPill :status="p.status" /><div v-if="p.rejection_reason" class="error">{{ p.rejection_reason }}</div></td>
                        <td><Link v-if="p.receipt" class="chip" :href="route('receipts.show', p.id)">Receipt {{ p.receipt }}</Link></td>
                    </tr>
                </table>
                <p v-else class="sub">No completed payments yet.</p>
            </div>

            <div v-else class="card scroll">
                <table v-if="invoices.length" style="min-width: 520px">
                    <tr><th>Month</th><th>Invoice</th><th>Amount</th><th>Balance</th><th>Status</th></tr>
                    <tr v-for="i in invoices" :key="i.id"><td>{{ i.month }}</td><td class="sub">{{ i.number }}</td><td>{{ N(i.amount) }}</td><td>{{ N(i.balance) }}</td><td><StatusPill :status="i.status" /></td></tr>
                </table>
                <p v-else class="sub">Nothing billed yet.</p>
            </div>

            <Modal :show="showManual" title="I paid by transfer, POS or cheque" @close="showManual = false">
                <form class="grid" @submit.prevent="submitManual">
                    <div class="form" style="margin: 0">
                        <label class="field">Months paid for
                            <select v-model.number="manual.months" @change="pickMonths">
                                <option v-for="o in options" :key="o.months" :value="o.months">{{ o.months }} – up to {{ o.through }}</option>
                            </select>
                        </label>
                        <label class="field">Amount paid (₦)<input v-model="manual.amount" type="number" min="1" step="0.01" required /><InputError :message="manual.errors.amount" /></label>
                    </div>
                    <div class="form" style="margin: 0">
                        <label class="field">How you paid
                            <select v-model="manual.method"><option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option></select>
                        </label>
                        <label class="field">Date paid<input v-model="manual.paid_on" type="date" :max="today()" required /><InputError :message="manual.errors.paid_on" /></label>
                    </div>
                    <label class="field">Transaction / teller reference<input v-model="manual.reference" required maxlength="100" /><InputError :message="manual.errors.reference" /></label>
                    <label class="field">Proof of payment (photo or PDF, up to 5 files)
                        <input type="file" multiple accept="image/*,application/pdf" required @change="manual.files = [...$event.target.files]" />
                        <InputError :message="manual.errors.files || manual.errors['files.0']" />
                    </label>
                    <p class="sub small" style="margin: 0">The accountant checks it against the bank statement. It shows as confirmed, with a receipt, once cleared.</p>
                    <div class="modal-foot">
                        <button type="button" class="btn ghost" @click="showManual = false">Cancel</button>
                        <button class="btn" :disabled="manual.processing">Submit</button>
                    </div>
                </form>
            </Modal>
        </template>

        <template v-else>
            <PageHeader title="My account" sub="You are not assigned to a facility at the moment." />
            <div class="card empty">Please contact your facility supervisor.</div>
        </template>
    </AppLayout>
</template>
