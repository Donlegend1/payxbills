<script setup>
import StatusPill from '@/Components/StatusPill.vue';
import { N } from '@/format';

// The printable monthly invoice
defineProps({ invoice: { type: Object, required: true } });
</script>

<template>
    <div class="card invoice-doc">
        <div class="doc-head">
            <div class="brand-head">
                <img v-if="invoice.company.logo" :src="invoice.company.logo" :alt="`${invoice.company.name} logo`" class="brand-logo" />
                <div>
                    <h1 style="font-size: 20px">{{ invoice.company.name }}</h1>
                    <div class="sub">Invoice {{ invoice.number }}</div>
                </div>
            </div>
            <div style="text-align: right">
                <StatusPill :status="invoice.status" :tone="invoice.status === 'Cancelled' ? 'red' : ''" />
                <div class="sub" style="margin-top: 6px">Issued {{ invoice.issue_date }}<br />Due {{ invoice.due_date }}</div>
            </div>
        </div>

        <div class="form">
            <div>
                <div class="sub small">Billed to</div>
                <b>{{ invoice.client.name }}</b>
                <div class="sub">
                    <div>Account {{ invoice.client.code }}</div>
                    <div>{{ invoice.client.space }}, {{ invoice.client.facility }}</div>
                    <div v-if="invoice.client.address">{{ invoice.client.address }}</div>
                </div>
            </div>
            <dl class="kv">
                <dt>Month</dt><dd>{{ invoice.period }}</dd>
                <dt>Category</dt><dd>{{ invoice.client.category }}</dd>
            </dl>
        </div>

        <table style="margin-top: 14px; min-width: 0">
            <tr><th>Description</th><th style="text-align: right">Amount</th></tr>
            <tr><td>{{ invoice.description }}</td><td style="text-align: right">{{ N(invoice.amount) }}</td></tr>
            <tr><td style="text-align: right" class="sub">Paid</td><td style="text-align: right">{{ N(invoice.paid) }}</td></tr>
            <tr><td style="text-align: right"><b>Balance due</b></td><td style="text-align: right" class="total">{{ N(invoice.balance) }}</td></tr>
        </table>

        <div v-if="invoice.company.bank_details" style="margin-top: 14px">
            <div class="sub small">Bank transfer</div>
            <div style="white-space: pre-line">{{ invoice.company.bank_details }}</div>
        </div>
    </div>
</template>
