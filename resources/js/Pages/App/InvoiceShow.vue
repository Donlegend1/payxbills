<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceDocument from '@/Components/InvoiceDocument.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { N } from '@/format';
import { toast } from '@/toast';
import { useCan } from '@/auth';

const props = defineProps({ invoice: Object, payments: Array, messages: Array, payUrl: String });
const can = useCan();

const send = () => router.post(route('invoices.send', props.invoice.id), {}, { preserveScroll: true });
const cancel = () => confirm(`Cancel invoice ${props.invoice.number}? It will no longer count as owed.`) && router.post(route('invoices.cancel', props.invoice.id), {}, { preserveScroll: true });
const copyLink = () => navigator.clipboard.writeText(props.payUrl).then(() => toast('Pay link copied'));
</script>

<template>
    <AppLayout>
        <Head :title="invoice.number" />

        <div class="top no-print">
            <div>
                <Link :href="route('invoices')" class="linkish">← Invoices</Link>
                <h1 style="margin-top: 4px">{{ invoice.number }}</h1>
                <div class="sub">{{ invoice.sent_at ? `Sent ${invoice.sent_at}` : 'Not sent yet' }}</div>
            </div>
            <div class="actions">
                <button class="chip" @click="copyLink">Copy pay link</button>
                <button class="chip" onclick="window.print()">Print / PDF</button>
                <template v-if="can('manage-invoices') && invoice.status !== 'Cancelled'">
                    <button class="chip on" @click="send">{{ invoice.sent_at ? 'Send again' : 'Send to facility user' }}</button>
                    <button v-if="invoice.can_cancel" class="chip" @click="cancel">Cancel invoice</button>
                </template>
            </div>
        </div>

        <InvoiceDocument :invoice="invoice" />

        <div class="grid auto no-print" style="margin-top: 14px; max-width: 820px">
            <div class="card scroll">
                <h2>Payments applied</h2>
                <table v-if="payments.length" style="min-width: 0">
                    <tr><th>Date</th><th>Receipt</th><th>Method</th><th>Amount</th></tr>
                    <tr v-for="p in payments" :key="p.id">
                        <td>{{ p.date }}</td>
                        <td><Link v-if="p.receipt" :href="route('receipts.show', p.id)">{{ p.receipt }}</Link></td>
                        <td>{{ p.method }}</td><td>{{ N(p.amount) }}</td>
                    </tr>
                </table>
                <div v-else class="sub">No payments yet.</div>
            </div>
            <div class="card scroll">
                <h2>Messages</h2>
                <table v-if="messages.length" style="min-width: 0">
                    <tr><th>Time</th><th>Channel</th><th>Type</th><th>Status</th></tr>
                    <tr v-for="(m, i) in messages" :key="i"><td>{{ m.time }}</td><td>{{ m.channel }}</td><td>{{ m.type }}</td><td><StatusPill :status="m.status" /></td></tr>
                </table>
                <div v-else class="sub">Nothing sent yet.</div>
            </div>
        </div>
    </AppLayout>
</template>
