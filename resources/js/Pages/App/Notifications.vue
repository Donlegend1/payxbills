<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({ messages: Object, filters: Object, counts: Object });

const can = useCan();
const { filters, apply } = useFilters('notifications', { status: props.filters.status ?? '', channel: props.filters.channel ?? '' });

const kpis = [
    ['Sent', props.counts.sent, 'delivered', 'var(--green)'],
    ['Queued', props.counts.queued, 'going out next run', 'var(--amber)'],
    ['Failed', props.counts.failed, 'needs a retry', 'var(--red)'],
];

const retry = (m) => router.post(route('notifications.retry', m.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <Head title="Notifications" />

        <PageHeader title="Notifications" sub="Every email and text the system sends, with delivery status. Failed messages are retried every hour." />

        <KpiCards :items="kpis" />

        <div class="tools">
            <button v-for="s in [['', 'All'], ['sent', 'Sent'], ['queued', 'Queued'], ['failed', 'Failed']]" :key="s[0]" class="chip" :class="{ on: filters.status === s[0] }" @click="filters.status = s[0]; apply()">{{ s[1] }}</button>
            <select v-model="filters.channel" aria-label="Channel" @change="apply()">
                <option value="">Email and SMS</option>
                <option value="email">Email only</option>
                <option value="sms">SMS only</option>
            </select>
        </div>

        <div class="card scroll">
            <table v-if="messages.data.length" style="min-width: 900px">
                <tr><th>Time</th><th>Facility user</th><th>Channel</th><th>Type</th><th>Message</th><th>Status</th><th></th></tr>
                <tr v-for="m in messages.data" :key="m.id">
                    <td>{{ m.time }}</td>
                    <td>{{ m.client }}<div class="sub small">{{ m.recipient }}</div></td>
                    <td>{{ m.channel }}</td>
                    <td>{{ m.type }}</td>
                    <td style="max-width: 320px">{{ m.message }}<div v-if="m.error" class="error">{{ m.error }}</div></td>
                    <td><StatusPill :status="m.status" /></td>
                    <td><button v-if="m.status !== 'Sent' && can('send-reminders')" class="chip" @click="retry(m)">Retry</button></td>
                </tr>
            </table>
            <div v-else class="empty">No messages yet.</div>
            <Pagination :page="messages" />
        </div>
    </AppLayout>
</template>
