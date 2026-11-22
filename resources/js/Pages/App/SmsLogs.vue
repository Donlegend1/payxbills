<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';
import { useFilters } from '@/filters';

const props = defineProps({ logs: Object, filters: Object, counts: Object });

const { filters, apply } = useFilters('sms-logs', {
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const kpis = [
    ['Delivered', props.counts.delivered, 'confirmed by the network', 'var(--green)'],
    ['Sent', props.counts.sent, 'accepted, awaiting delivery report', 'var(--blue)'],
    ['Queued', props.counts.queued, 'waiting for the queue worker', 'var(--amber)'],
    ['Failed', props.counts.failed, 'needs a retry', 'var(--red)'],
];

const statuses = [['', 'All'], ['delivered', 'Delivered'], ['sent', 'Sent'], ['queued', 'Queued'], ['failed', 'Failed']];

const filtered = () => Object.values(filters).some((v) => v !== '');
const clear = () => {
    Object.assign(filters, { q: '', status: '', from: '', to: '' });
    apply();
};

const open = ref(null);
const toggle = (id) => (open.value = open.value === id ? null : id);

const retry = (log) => router.post(route('sms-logs.retry', log.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="SMS log" />

        <PageHeader title="SMS log" sub="Every text message the system has sent, one row per recipient, with the provider's status and cost." />

        <KpiCards :items="kpis" />

        <div class="tools">
            <button v-for="s in statuses" :key="s[0]" class="chip" :class="{ on: filters.status === s[0] }" @click="filters.status = s[0]; apply()">{{ s[1] }}</button>
            <input v-model="filters.q" type="search" placeholder="Search number, message, sender or message ID" aria-label="Search SMS" style="min-width: 300px" @input="apply(300)" />
            <label class="sub small">From <input v-model="filters.from" type="date" aria-label="From date" :max="filters.to || undefined" @change="apply()" /></label>
            <label class="sub small">To <input v-model="filters.to" type="date" aria-label="To date" :min="filters.from || undefined" @change="apply()" /></label>
            <button v-if="filtered()" class="chip" @click="clear">Clear filters</button>
        </div>

        <div class="card scroll">
            <table v-if="logs.data.length" style="min-width: 980px">
                <tr><th>Time</th><th>Recipient</th><th>Message</th><th>About</th><th>Provider</th><th>Status</th><th></th></tr>
                <template v-for="l in logs.data" :key="l.id">
                    <tr style="cursor: pointer" @click="toggle(l.id)">
                        <td>{{ l.time }}<div v-if="l.sent_by" class="sub small">by {{ l.sent_by }}</div></td>
                        <td>{{ l.recipient }}<div v-if="l.sender" class="sub small">from {{ l.sender }}</div></td>
                        <td style="max-width: 340px">
                            <span :style="open === l.id ? '' : 'display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden'">{{ l.message }}</span>
                            <div v-if="l.error" class="error">{{ l.error }}</div>
                        </td>
                        <td>{{ l.about ?? '—' }}</td>
                        <td>{{ l.driver }}<div v-if="l.cost" class="sub small">{{ l.cost }}</div></td>
                        <td><StatusPill :status="l.status" /></td>
                        <td><button v-if="l.status === 'Failed' || l.status === 'Queued'" class="chip" @click.stop="retry(l)">Retry</button></td>
                    </tr>
                    <tr v-if="open === l.id">
                        <td colspan="7" class="sub small">
                            Provider status: {{ l.provider_status ?? '—' }}
                            · Message ID: {{ l.provider_message_id ?? '—' }}
                            · Attempts: {{ l.attempts }}
                            · Log #{{ l.id }}
                        </td>
                    </tr>
                </template>
            </table>
            <div v-else class="empty">{{ filtered() ? 'No SMS match these filters.' : 'No SMS sent yet.' }}</div>
            <Pagination :page="logs" />
        </div>
    </AppLayout>
</template>
