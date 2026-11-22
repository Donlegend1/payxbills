<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import InputError from '@/Components/InputError.vue';

// A company's own list of names (types of facility, types of bin): add, rename, hide.
const props = defineProps({
    list: { type: String, required: true }, // URL name: facility-types, bin-types
    title: { type: String, required: true },
    items: { type: Array, required: true },
    placeholder: { type: String, default: '' },
    help: { type: String, default: '' },
});

const form = useForm({ name: '' });
const add = () => form.post(route('lists.store', props.list), { preserveScroll: true, onSuccess: () => form.reset() });

const editing = ref(null);
const editForm = useForm({ name: '', is_active: true });
function edit(item) {
    editing.value = item.id;
    editForm.defaults({ name: item.name, is_active: item.is_active });
    editForm.reset();
    editForm.clearErrors();
}
const save = (item) => editForm.put(route('lists.update', [props.list, item.id]), { preserveScroll: true, onSuccess: () => (editing.value = null) });
</script>

<template>
    <div class="card">
        <h2>{{ title }}</h2>
        <p v-if="help" class="sub small" style="margin-top: -4px">{{ help }}</p>
        <form class="tools" style="margin-bottom: 8px" @submit.prevent="add">
            <input v-model="form.name" required maxlength="100" :placeholder="placeholder" :aria-label="`New ${title.toLowerCase()}`" />
            <button class="chip on" :disabled="form.processing">Add</button>
            <InputError :message="form.errors.name" />
        </form>
        <table v-if="items.length" style="min-width: 0">
            <tr><th>Name</th><th>Clients</th><th>Status</th><th></th></tr>
            <tr v-for="item in items" :key="item.id">
                <template v-if="editing === item.id">
                    <td colspan="2"><input v-model="editForm.name" maxlength="100" required /><InputError :message="editForm.errors.name" /></td>
                    <td><label class="check"><input v-model="editForm.is_active" type="checkbox" /> In use</label></td>
                    <td class="actions"><button class="chip on" :disabled="editForm.processing" @click="save(item)">Save</button><button class="chip" @click="editing = null">Cancel</button></td>
                </template>
                <template v-else>
                    <td>{{ item.name }}</td>
                    <td>{{ item.members }}</td>
                    <td><StatusPill :status="item.is_active ? 'Active' : 'Hidden'" :tone="item.is_active ? 'green' : 'amber'" /></td>
                    <td><button class="chip" @click="edit(item)">Edit</button></td>
                </template>
            </tr>
        </table>
        <div v-else class="empty">None yet.</div>
    </div>
</template>
