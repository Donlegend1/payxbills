<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import FacilityForm from '@/Components/FacilityForm.vue';
import { useCan } from '@/auth';

defineProps({ facilities: Array, supervisors: Array });

const can = useCan();
const show = ref(false);
</script>

<template>
    <AppLayout>
        <Head title="Facilities" />

        <PageHeader title="Facilities" sub="Each facility has numbered rooms or spaces. A facility user is tied to one space.">
            <button v-if="can('manage-facilities')" class="chip on" @click="show = true">Add facility</button>
        </PageHeader>

        <div class="card scroll">
            <table v-if="facilities.length" style="min-width: 760px">
                <tr><th>Facility</th><th>Supervisor</th><th>Spaces</th><th>Occupied</th><th>Vacant</th><th>Status</th><th></th></tr>
                <tr v-for="f in facilities" :key="f.id">
                    <td>
                        <Link :href="route('facilities.show', f.id)">{{ f.name }}</Link>
                        <div v-if="f.address" class="sub small">{{ f.address }}</div>
                    </td>
                    <td>{{ f.supervisor ?? '—' }}</td>
                    <td>{{ f.spaces }}</td>
                    <td>{{ f.occupied }}</td>
                    <td>{{ f.vacant }}</td>
                    <td><StatusPill :status="f.is_active ? 'Active' : 'Inactive'" :tone="f.is_active ? 'green' : 'amber'" /></td>
                    <td><Link class="chip" :href="route('facilities.show', f.id)">Spaces</Link></td>
                </tr>
            </table>
            <div v-else class="empty">No facilities yet.</div>
        </div>

        <Modal :show="show" title="Add facility" @close="show = false">
            <FacilityForm :supervisors="supervisors" @done="show = false" @cancel="show = false" />
        </Modal>
    </AppLayout>
</template>
