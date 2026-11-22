<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, required: true }, tone: { type: String, default: '' } });

const tones = {
    Paid: 'green', Confirmed: 'green', Delivered: 'green', Active: 'green', Sent: 'green', Approved: 'green',
    Partial: 'amber', Pending: 'amber', 'Under Review': 'amber', Queued: 'amber', 'Ending soon': 'amber', Unpaid: 'amber', 'Not sent': 'amber',
    Overdue: 'red', Failed: 'red', Rejected: 'red',
};

const cls = computed(() => 's-' + (props.tone || tones[props.status] || 'green'));
</script>

<template>
    <span class="pill" :class="cls">{{ status }}</span>
</template>
