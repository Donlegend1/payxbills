<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { N } from '@/format';

// Printable receipt with the facility details. Shown in the app, or on its own from the emailed link.
defineProps({ receipt: Object, inApp: Boolean });
</script>

<template>
    <component :is="inApp ? AppLayout : PublicLayout">
        <Head :title="`Receipt ${receipt.number}`" />

        <div :class="inApp ? '' : 'wrap'" :style="inApp ? 'max-width: 820px' : 'max-width: 820px; padding: 32px 0'">
            <div class="top no-print">
                <div>
                    <Link v-if="inApp" :href="route('dashboard')" class="linkish">← Back</Link>
                    <h1 style="margin-top: 4px">Receipt {{ receipt.number }}</h1>
                </div>
                <button class="btn ghost" onclick="window.print()">Print / save PDF</button>
            </div>

            <div class="card invoice-doc">
                <div class="doc-head">
                    <div class="brand-head">
                        <img v-if="receipt.company.logo" :src="receipt.company.logo" :alt="`${receipt.company.name} logo`" class="brand-logo" />
                        <div>
                            <h1 style="font-size: 20px">{{ receipt.company.name }}</h1>
                            <div class="sub">Payment receipt {{ receipt.number }}</div>
                        </div>
                    </div>
                    <div style="text-align: right">
                        <span class="pill s-green">Paid</span>
                        <div class="sub" style="margin-top: 6px">{{ receipt.date }}</div>
                    </div>
                </div>

                <div class="form">
                    <div>
                        <div class="sub small">Facility</div>
                        <b>{{ receipt.facility.name }}</b>
                        <div class="sub">
                            <div v-if="receipt.facility.address">{{ receipt.facility.address }}</div>
                            <div v-if="receipt.facility.phone">Tel: {{ receipt.facility.phone }}</div>
                            <div v-if="receipt.facility.email">{{ receipt.facility.email }}</div>
                        </div>
                    </div>
                    <div>
                        <div class="sub small">Received from</div>
                        <b>{{ receipt.payer.name }}</b>
                        <div class="sub">
                            <div>Account {{ receipt.payer.account }}</div>
                            <div>{{ receipt.space }} · {{ receipt.category }}</div>
                            <div v-if="receipt.payer.phone">{{ receipt.payer.phone }}</div>
                        </div>
                    </div>
                </div>

                <table style="margin-top: 14px; min-width: 0">
                    <tr><th>Month</th><th>Invoice</th><th style="text-align: right">Amount</th></tr>
                    <tr v-for="l in receipt.lines" :key="l.invoice"><td>{{ l.month }}</td><td class="sub">{{ l.invoice }}</td><td style="text-align: right">{{ N(l.amount) }}</td></tr>
                    <tr v-if="receipt.credit > 0"><td colspan="2">Credit on account (applied to future months)</td><td style="text-align: right">{{ N(receipt.credit) }}</td></tr>
                    <tr><td colspan="2" style="text-align: right"><b>Total paid</b></td><td style="text-align: right" class="total">{{ N(receipt.amount) }}</td></tr>
                </table>

                <dl class="kv" style="margin-top: 14px">
                    <dt>Method</dt><dd>{{ receipt.method }}</dd>
                    <dt v-if="receipt.reference">Reference</dt><dd v-if="receipt.reference">{{ receipt.reference }}</dd>
                    <dt v-if="receipt.paid_through">Paid up to</dt><dd v-if="receipt.paid_through">{{ receipt.paid_through }}</dd>
                </dl>
            </div>
        </div>
    </component>
</template>
