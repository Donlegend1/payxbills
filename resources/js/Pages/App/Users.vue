<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ roles: Array, users: Array });

// Filter by role: '' shows everyone
const role = ref('');
const shown = computed(() => (role.value ? props.users.filter((u) => u.role === role.value) : props.users));
const count = (r) => props.users.filter((u) => u.role === r).length;

const blankUser = { name: '', email: '', phone: '', role: 'supervisor', password: '', is_active: true };
const userForm = useForm({ ...blankUser });
const editingUser = ref(null);
const showUser = ref(false);
function openUser(u = null) {
    editingUser.value = u;
    userForm.clearErrors();
    userForm.defaults(u ? { ...blankUser, name: u.name, email: u.email, phone: u.phone ?? '', role: u.role, is_active: u.is_active } : { ...blankUser, role: role.value || blankUser.role });
    userForm.reset();
    showUser.value = true;
}
function saveUser() {
    const opts = { preserveScroll: true, onSuccess: () => (showUser.value = false) };
    editingUser.value ? userForm.put(route('users.update', editingUser.value.id), opts) : userForm.post(route('users.store'), opts);
}

function impersonate(u) {
    if (confirm(`Log in as ${u.name}? You can switch back from the banner at the top of the page.`)) {
        router.post(route('users.impersonate', u.id));
    }
}
</script>

<template>
    <AppLayout>
        <Head title="Users" />

        <PageHeader title="Users" sub="Staff accounts for each role. Facility users are managed on the Facility users page.">
            <button class="chip on" @click="openUser()">Add user</button>
        </PageHeader>

        <div class="actions mb" role="group" aria-label="Filter by role" style="display: flex; gap: 6px; flex-wrap: wrap">
            <button class="chip" :class="{ on: role === '' }" :aria-pressed="role === ''" @click="role = ''">All ({{ users.length }})</button>
            <button v-for="r in roles" :key="r.value" class="chip" :class="{ on: role === r.value }" :aria-pressed="role === r.value" @click="role = r.value">
                {{ r.label }} ({{ count(r.value) }})
            </button>
        </div>

        <div class="card scroll mb">
            <table style="min-width: 720px">
                <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Access</th><th></th></tr>
                <tr v-for="u in shown" :key="u.id">
                    <td>{{ u.name }}<div v-if="!u.is_active" class="sub small">Deactivated</div></td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.phone || '—' }}</td>
                    <td>{{ u.role_label }}</td>
                    <td class="sub">{{ u.access }}</td>
                    <td style="white-space: nowrap">
                        <button class="chip" @click="openUser(u)">Edit</button>
                        <button v-if="u.can_impersonate" class="chip" style="margin-left: 6px" @click="impersonate(u)">Impersonate</button>
                    </td>
                </tr>
                <tr v-if="!shown.length"><td colspan="6" class="sub">No users with this role yet.</td></tr>
            </table>
        </div>

        <Modal :show="showUser" :title="editingUser ? `Edit ${editingUser.name}` : 'Add user'" @close="showUser = false">
            <form class="grid" @submit.prevent="saveUser">
                <label class="field">Name<input v-model="userForm.name" required /><InputError :message="userForm.errors.name" /></label>
                <div class="form" style="margin: 0">
                    <label class="field">Email<input v-model="userForm.email" type="email" required /><InputError :message="userForm.errors.email" /></label>
                    <label class="field">Phone<input v-model="userForm.phone" type="tel" /><InputError :message="userForm.errors.phone" /></label>
                </div>
                <label class="field">Role
                    <select v-model="userForm.role"><option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option></select>
                    <span class="sub small">{{ roles.find((r) => r.value === userForm.role)?.description }}</span>
                    <InputError :message="userForm.errors.role" />
                </label>
                <label class="field">{{ editingUser ? 'New password (leave blank to keep)' : 'Password' }}<input v-model="userForm.password" type="password" autocomplete="new-password" :required="!editingUser" /><InputError :message="userForm.errors.password" /></label>
                <label v-if="editingUser" class="check"><input v-model="userForm.is_active" type="checkbox" /> Can log in</label>
                <p class="sub small" style="margin: 0">Supervisors are assigned to facilities on the <Link :href="route('facilities')">Facilities</Link> page.</p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="showUser = false">Cancel</button>
                    <button class="btn" :disabled="userForm.processing">Save</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
