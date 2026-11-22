<script setup>
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

// Create a facility, or edit the one passed in
const props = defineProps({ facility: { type: Object, default: null }, supervisors: { type: Array, required: true } });
const emit = defineEmits(['done', 'cancel']);

const form = useForm({
    name: props.facility?.name ?? '',
    address: props.facility?.address ?? '',
    phone: props.facility?.phone ?? '',
    email: props.facility?.email ?? '',
    supervisor_id: props.facility?.supervisor_id ?? '',
    is_active: props.facility?.is_active ?? true,
});

function save() {
    const opts = { preserveScroll: true, onSuccess: () => emit('done') };
    form.transform((d) => ({ ...d, supervisor_id: d.supervisor_id || null }));
    props.facility ? form.put(route('facilities.update', props.facility.id), opts) : form.post(route('facilities.store'), opts);
}
</script>

<template>
    <form class="grid" @submit.prevent="save">
        <label class="field">Name<input v-model="form.name" required /><InputError :message="form.errors.name" /></label>
        <label class="field">Address<input v-model="form.address" /><InputError :message="form.errors.address" /></label>
        <div class="form" style="margin: 0">
            <label class="field">Phone<input v-model="form.phone" type="tel" /><InputError :message="form.errors.phone" /></label>
            <label class="field">Email<input v-model="form.email" type="email" /><InputError :message="form.errors.email" /></label>
        </div>
        <label class="field">Supervisor
            <select v-model="form.supervisor_id">
                <option value="">None yet</option>
                <option v-for="s in supervisors" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <InputError :message="form.errors.supervisor_id" />
        </label>
        <label class="check"><input v-model="form.is_active" type="checkbox" /> Active (billed every month)</label>
        <p class="sub small" style="margin: 0">The address, phone and email are printed on facility users' receipts.</p>
        <div class="modal-foot">
            <button type="button" class="btn ghost" @click="emit('cancel')">Cancel</button>
            <button class="btn" :disabled="form.processing">Save facility</button>
        </div>
    </form>
</template>
