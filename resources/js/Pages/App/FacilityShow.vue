<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import KpiCards from '@/Components/KpiCards.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import FacilityForm from '@/Components/FacilityForm.vue';
import { useCan } from '@/auth';

const props = defineProps({ facility: Object, spaces: Array, supervisors: Array });

const can = useCan();
const filter = ref('');
const editingFacility = ref(false);

const occupied = computed(() => props.spaces.filter((s) => s.occupant).length);
const vacant = computed(() => props.spaces.filter((s) => !s.occupant && s.is_active).length);
const kpis = computed(() => [
    ['Spaces', props.spaces.length, 'rooms, shops or stalls', 'var(--blue)'],
    ['Occupied', occupied.value, 'tied to a facility user', 'var(--green)'],
    ['Vacant', vacant.value, 'ready for a new facility user', 'var(--amber)'],
]);

const shown = computed(() => {
    const q = filter.value.toLowerCase();
    return props.spaces.filter((s) => !q || s.number.toLowerCase().includes(q) || (s.label ?? '').toLowerCase().includes(q) || (s.occupant?.name ?? '').toLowerCase().includes(q));
});

// Add spaces: one number, or a range like 101 to 120
const adding = ref(false);
const mode = ref('range');
const addForm = useForm({ label: '', number: '', prefix: '', from: '', to: '' });
function add() {
    addForm.transform((d) => (mode.value === 'one' ? { label: d.label, number: d.number } : { label: d.label, prefix: d.prefix, from: d.from, to: d.to }));
    addForm.post(route('spaces.store', props.facility.id), { preserveScroll: true, onSuccess: () => { adding.value = false; addForm.reset(); } });
}

// Edit one space
const editing = ref(null);
const spaceForm = useForm({ number: '', label: '', is_active: true });
function edit(s) {
    editing.value = s;
    spaceForm.clearErrors();
    spaceForm.defaults({ number: s.number, label: s.label ?? '', is_active: s.is_active });
    spaceForm.reset();
}
const saveSpace = () => spaceForm.put(route('spaces.update', editing.value.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
</script>

<template>
    <AppLayout>
        <Head :title="facility.name" />

        <PageHeader :title="facility.name" :sub="[facility.address, facility.supervisor ? 'Supervisor: ' + facility.supervisor : 'No supervisor assigned'].filter(Boolean).join(' · ')">
            <div class="actions">
                <Link class="chip" :href="route('facilities')">All facilities</Link>
                <button v-if="can('manage-facilities')" class="chip" @click="editingFacility = true">Edit facility</button>
                <button v-if="can('manage-facilities')" class="chip on" @click="adding = true">Add spaces</button>
            </div>
        </PageHeader>

        <KpiCards :items="kpis" />

        <div class="tools">
            <input v-model="filter" type="search" placeholder="Search space number or facility user" aria-label="Search spaces" style="min-width: 260px" />
            <Link v-if="can('register-facility-users') && vacant" class="chip" :href="route('facility-users', { new: 1, facility: facility.id })">Register facility user</Link>
        </div>

        <div class="card scroll">
            <table v-if="shown.length" style="min-width: 640px">
                <tr><th>Space</th><th>Facility user</th><th>Category</th><th>Status</th><th></th></tr>
                <tr v-for="s in shown" :key="s.id">
                    <td><b>{{ s.number }}</b><div v-if="s.label" class="sub small">{{ s.label }}</div></td>
                    <td>
                        <template v-if="s.occupant">{{ s.occupant.name }}<div class="sub small">{{ s.occupant.code }}</div></template>
                        <span v-else class="sub">—</span>
                    </td>
                    <td>{{ s.occupant?.category ?? '—' }}</td>
                    <td><StatusPill :status="!s.is_active ? 'Inactive' : s.occupant ? 'Occupied' : 'Vacant'" :tone="!s.is_active ? 'red' : s.occupant ? 'green' : 'amber'" /></td>
                    <td><button v-if="can('manage-facilities')" class="chip" @click="edit(s)">Edit</button></td>
                </tr>
            </table>
            <div v-else class="empty">{{ spaces.length ? 'No spaces match.' : 'No spaces yet. Add the rooms or spaces in this facility.' }}</div>
        </div>

        <Modal :show="editingFacility" :title="`Edit ${facility.name}`" @close="editingFacility = false">
            <FacilityForm :facility="facility" :supervisors="supervisors" @done="editingFacility = false" @cancel="editingFacility = false" />
        </Modal>

        <Modal :show="adding" title="Add spaces" @close="adding = false">
            <form class="grid" @submit.prevent="add">
                <div class="tools" style="margin: 0">
                    <button type="button" class="chip" :class="{ on: mode === 'range' }" @click="mode = 'range'">A numbered range</button>
                    <button type="button" class="chip" :class="{ on: mode === 'one' }" @click="mode = 'one'">One space</button>
                </div>
                <label class="field">Type (optional)<input v-model="addForm.label" placeholder="Room, Shop, Stall…" /><InputError :message="addForm.errors.label" /></label>
                <template v-if="mode === 'one'">
                    <label class="field">Space number<input v-model="addForm.number" required placeholder="e.g. A12" /><InputError :message="addForm.errors.number" /></label>
                </template>
                <div v-else class="form" style="margin: 0">
                    <label class="field">Prefix (optional)<input v-model="addForm.prefix" placeholder="e.g. B-" /></label>
                    <label class="field">From<input v-model="addForm.from" type="number" min="0" required /><InputError :message="addForm.errors.from" /></label>
                    <label class="field">To<input v-model="addForm.to" type="number" min="0" required /><InputError :message="addForm.errors.to" /></label>
                </div>
                <p class="sub small" style="margin: 0">Numbers must be unique in this facility. Existing numbers are skipped.</p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="adding = false">Cancel</button>
                    <button class="btn" :disabled="addForm.processing">Add</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!editing" :title="`Edit space ${editing?.number ?? ''}`" @close="editing = null">
            <form class="grid" @submit.prevent="saveSpace">
                <div class="form" style="margin: 0">
                    <label class="field">Number<input v-model="spaceForm.number" required /><InputError :message="spaceForm.errors.number" /></label>
                    <label class="field">Type<input v-model="spaceForm.label" placeholder="Room, Shop…" /></label>
                </div>
                <label class="check"><input v-model="spaceForm.is_active" type="checkbox" /> Available for facility users</label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="editing = null">Cancel</button>
                    <button class="btn" :disabled="spaceForm.processing">Save space</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
