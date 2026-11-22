<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { N } from '@/format';

// "Pay for N months" with Paystack. `action` is the URL the form posts to.
const props = defineProps({ options: { type: Array, required: true }, action: { type: String, required: true }, outstandingMonths: { type: Number, default: 0 } });

const months = ref(Math.max(1, Math.min(props.outstandingMonths || 1, props.options.length)));
const chosen = computed(() => props.options.find((o) => o.months === months.value) ?? props.options[0]);
const form = useForm({ months: months.value });

const pay = () => {
    form.months = months.value;
    form.post(props.action, { preserveScroll: true });
};
</script>

<template>
    <form v-if="options.length" class="grid" @submit.prevent="pay">
        <label class="field">How many months do you want to pay for?
            <select v-model.number="months">
                <option v-for="o in options" :key="o.months" :value="o.months">{{ o.months }} month{{ o.months > 1 ? 's' : '' }} – up to {{ o.through }}</option>
            </select>
        </label>
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap">
            <span class="sub">Covers up to <b>{{ chosen.through }}</b></span>
            <span style="font-size: 22px; font-weight: 700">{{ N(chosen.amount) }}</span>
        </div>
        <button class="btn block" :disabled="form.processing || chosen.amount <= 0">{{ form.processing ? 'Opening Paystack…' : `Pay ${N(chosen.amount)} online` }}</button>
        <p class="sub small" style="margin: 0">Card, bank transfer or USSD through Paystack. Unpaid months are paid first, oldest first.</p>
    </form>
</template>
