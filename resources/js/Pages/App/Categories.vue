<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import NameList from '@/Components/NameList.vue';
import { N } from '@/format';

defineProps({ categories: Array, binTypes: Array });

const blank = { name: '', monthly_amount: '', description: '', is_active: true };
const form = useForm({ ...blank });
const editing = ref(null);
const show = ref(false);

function open(c = null) {
    editing.value = c;
    form.clearErrors();
    form.defaults(c ? { ...blank, ...c, description: c.description ?? '' } : { ...blank });
    form.reset();
    show.value = true;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('categories.update', editing.value.id), opts) : form.post(route('categories.store'), opts);
}
</script>

<template>
    <AppLayout>
        <Head title="Categories" />

        <PageHeader title="Categories" sub="Each facility user is in one category (e.g. House, School, Shop) and pays its monthly amount. Change an amount here to reprice everyone in that category.">
            <button class="chip on" @click="open()">Add category</button>
        </PageHeader>

        <div class="card scroll">
            <table v-if="categories.length" style="min-width: 600px">
                <tr><th>Category</th><th>Monthly amount</th><th>Facility users</th><th>Status</th><th></th></tr>
                <tr v-for="c in categories" :key="c.id">
                    <td>{{ c.name }}<div v-if="c.description" class="sub small">{{ c.description }}</div></td>
                    <td>{{ N(c.monthly_amount) }}</td>
                    <td>{{ c.members }}</td>
                    <td><StatusPill :status="c.is_active ? 'Active' : 'Inactive'" :tone="c.is_active ? 'green' : 'amber'" /></td>
                    <td><button class="chip" @click="open(c)">Edit</button></td>
                </tr>
            </table>
            <div v-else class="empty">No categories yet. Add your first, e.g. House at ₦8,000 a month.</div>
        </div>

        <div class="grid auto" style="margin-top: 16px">
            <NameList list="bin-types" title="Bin types" :items="binTypes" placeholder="e.g. Wheeler bin, Mammoth bin" help="The bins you collect from facility users." />
        </div>

        <Modal :show="show" :title="editing ? `Edit ${editing.name}` : 'Add category'" @close="show = false">
            <form class="grid" @submit.prevent="save">
                <label class="field">Name<input v-model="form.name" required placeholder="e.g. House, School, Shop" /><InputError :message="form.errors.name" /></label>
                <label class="field">Monthly amount (₦)<input v-model="form.monthly_amount" type="number" min="1" step="0.01" required /><InputError :message="form.errors.monthly_amount" /></label>
                <label class="field">Description (optional)<input v-model="form.description" /><InputError :message="form.errors.description" /></label>
                <label class="check"><input v-model="form.is_active" type="checkbox" /> Available for new facility users</label>
                <p v-if="editing" class="sub small" style="margin: 0">A new amount reprices all {{ editing.members }} facility user{{ editing.members === 1 ? '' : 's' }} in this category from their next bill. Months already billed keep their amount.</p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="show = false">Cancel</button>
                    <button class="btn" :disabled="form.processing">Save category</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
