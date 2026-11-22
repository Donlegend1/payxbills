<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PayBox from '@/Components/PayBox.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { N } from '@/format';

const props = defineProps({ account: Object, options: Array, invoices: Array, bankDetails: String, company: String, logo: String, action: String });

const flash = computed(() => usePage().props.flash);
const unpaid = computed(() => props.invoices.filter((i) => i.balance > 0).length);
</script>

<template>
    <PublicLayout :whatsapp="$page.props.currentCompany?.whatsapp ?? ''" :whatsapp-message="`Hello ${company}, I need help with my account ${account.code}.`">
        <Head title="Pay your dues" />

        <section class="block" style="padding-top: 32px">
            <div class="wrap" style="max-width: 820px">
                <div class="brand-head" style="margin-bottom: 16px">
                    <img v-if="logo" :src="logo" :alt="`${company} logo`" class="brand-logo" />
                    <b style="font-size: 17px">{{ company }}</b>
                </div>
                <h1>Hello {{ account.name }}</h1>
                <p class="sub">{{ account.space }}<template v-if="account.category"> · {{ account.category }}</template> ({{ N(account.monthly) }} a month) · Account {{ account.code }}</p>

                <div v-if="flash.error" class="notice" style="background: #d6454522; color: var(--red); margin-bottom: 12px">{{ flash.error }}</div>
                <div v-if="flash.success" class="notice" style="margin-bottom: 12px">{{ flash.success }}</div>

                <div class="grid auto">
                    <div class="card grid">
                        <dl class="kv">
                            <dt>Outstanding</dt><dd><b>{{ N(account.outstanding) }}</b></dd>
                            <dt>Paid up to</dt><dd>{{ account.paid_through ?? 'Nothing paid yet' }}</dd>
                            <dt v-if="account.credit > 0">Credit</dt><dd v-if="account.credit > 0">{{ N(account.credit) }}</dd>
                        </dl>
                        <PayBox v-if="account.active" :options="options" :action="action" :outstanding-months="unpaid" />
                        <p v-else class="sub">This account is closed.</p>
                    </div>

                    <div class="card scroll">
                        <h2>Recent months</h2>
                        <table v-if="invoices.length" style="min-width: 0">
                            <tr><th>Month</th><th>Amount</th><th>Status</th></tr>
                            <tr v-for="i in invoices" :key="i.id"><td>{{ i.month }}</td><td>{{ N(i.amount) }}</td><td><StatusPill :status="i.status" /></td></tr>
                        </table>
                        <p v-else class="sub">Nothing billed yet.</p>
                    </div>
                </div>

                <div v-if="bankDetails" class="card" style="margin-top: 14px">
                    <h2>Prefer to pay by bank transfer?</h2>
                    <div style="white-space: pre-line">{{ bankDetails }}</div>
                    <p class="sub small">Quote your account number <b>{{ account.code }}</b>, then log in to upload your proof of payment.</p>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
