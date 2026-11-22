<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';
import { N, today } from '@/format';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({ occupancies: Object, filters: Object, facilities: Array, categories: Array, pendingRemovals: Number });

const can = useCan();
const { filters, apply } = useFilters('facility-users', {
    status: props.filters.status ?? '',
    facility: props.filters.facility ?? '',
    category: props.filters.category ?? '',
    q: props.filters.q ?? '',
});
const chips = [['', 'All active'], ['green', 'Up to date'], ['amber', '1 month overdue'], ['red', 'More than 1 overdue'], ['removal', 'Removal pending'], ['removed', 'Removed']];

const status = (o) => {
    if (!o.is_active) return ['red', 'Removed'];
    if (o.removal) return ['amber', 'Removal pending'];
    if (o.overdue_count === 0) return ['green', o.outstanding > 0 ? 'Not yet due' : 'Up to date'];
    return o.overdue_count === 1 ? ['amber', '1 month overdue'] : ['red', o.overdue_count + ' months overdue'];
};

// Register / edit
const query = new URLSearchParams(window.location.search);
const blank = { name: '', email: '', phone: '', facility_id: query.get('facility') ?? '', space_id: '', category_id: '', start_date: today() };
const form = useForm({ ...blank });
const editing = ref(null);
const show = ref(query.has('new') && can('register-facility-users'));

function open(o = null) {
    editing.value = o;
    form.clearErrors();
    form.defaults(o ? { ...blank, name: o.name, email: o.email, phone: o.phone ?? '', facility_id: o.facility_id, space_id: o.space_id, category_id: o.category_id, start_date: o.start_date } : { ...blank });
    form.reset();
    show.value = true;
}

// Vacant spaces in the chosen facility, plus the user's current space when editing
const spaces = computed(() => {
    const f = props.facilities.find((f) => String(f.id) === String(form.facility_id));
    const list = f ? [...f.vacant] : [];
    if (editing.value && String(editing.value.facility_id) === String(form.facility_id)) {
        list.unshift({ id: editing.value.space_id, name: editing.value.space + ' (current)' });
    }
    return list;
});

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('facility-users.update', editing.value.id), opts) : form.post(route('facility-users.store'), opts);
}

const remind = (o) => router.post(route('facility-users.remind', o.id), {}, { preserveScroll: true });
const resendLogin = (o) => confirm(`Email ${o.name} a new password? Their current password will stop working.`) && router.post(route('facility-users.resend-login', o.id), {}, { preserveScroll: true });

// Removal: request (supervisor / company admin), review (company admin)
const removing = ref(null);
const removalForm = useForm({ reason: '' });
function askRemoval(o) {
    removing.value = o;
    removalForm.reset();
    removalForm.clearErrors();
}
const submitRemoval = () => removalForm.post(route('facility-users.removal', removing.value.id), { preserveScroll: true, onSuccess: () => (removing.value = null) });

const reviewing = ref(null);
const reviewForm = useForm({ note: '' });
function review(o) {
    reviewing.value = o;
    reviewForm.reset();
}
const decide = (action) => reviewForm.post(route(`removals.${action}`, reviewing.value.removal.id), { preserveScroll: true, onSuccess: () => (reviewing.value = null) });
</script>

<template>
    <AppLayout>
        <Head title="Facility users" />

        <PageHeader title="Facility users" sub="People tied to a room or space in a facility. They pay their category's amount every month.">
            <button v-if="can('register-facility-users')" class="chip on" @click="open()">Register facility user</button>
        </PageHeader>

        <div v-if="can('approve-removals') && pendingRemovals > 0 && filters.status !== 'removal'" class="notice" style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            {{ pendingRemovals }} removal request{{ pendingRemovals > 1 ? 's' : '' }} waiting for your approval.
            <button class="chip" @click="filters.status = 'removal'; apply()">Review</button>
        </div>

        <div class="tools">
            <button v-for="c in chips" :key="c[0]" class="chip" :class="{ on: filters.status === c[0] }" @click="filters.status = c[0]; apply()">{{ c[1] }}</button>
            <select v-model="filters.facility" aria-label="Facility" @change="apply()">
                <option value="">All facilities</option>
                <option v-for="f in facilities" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
            </select>
            <select v-model="filters.category" aria-label="Category" @change="apply()">
                <option value="">All categories</option>
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
            <input v-model="filters.q" type="search" placeholder="Search name, email, phone, account or space" aria-label="Search facility users" style="min-width: 260px" @input="apply(300)" />
        </div>

        <div class="card scroll">
            <table v-if="occupancies.data.length" style="min-width: 980px">
                <tr><th>Account</th><th>Facility user</th><th>Facility · space</th><th>Category</th><th>Paid up to</th><th>Outstanding</th><th>Status</th><th></th></tr>
                <tr v-for="o in occupancies.data" :key="o.id">
                    <td>{{ o.code }}</td>
                    <td>{{ o.name }}<div class="sub small">{{ o.email }}<template v-if="o.phone"> · {{ o.phone }}</template></div></td>
                    <td>{{ o.facility }}<div class="sub small">{{ o.space }}</div></td>
                    <td>{{ o.category }}<div class="sub small">{{ N(o.monthly) }}/month</div></td>
                    <td>{{ o.paid_through ?? '—' }}</td>
                    <td>{{ N(o.outstanding) }}</td>
                    <td>
                        <StatusPill :status="status(o)[1]" :tone="status(o)[0]" />
                        <div v-if="o.removal" class="sub small" style="max-width: 220px">"{{ o.removal.reason }}" – {{ o.removal.by }}, {{ o.removal.at }}</div>
                        <div v-if="o.ended" class="sub small">Removed {{ o.ended }}</div>
                    </td>
                    <td class="actions">
                        <template v-if="o.is_active">
                            <button v-if="o.removal && can('approve-removals')" class="chip on" @click="review(o)">Review removal</button>
                            <button v-if="can('send-reminders') && o.outstanding > 0" class="chip" @click="remind(o)">Remind</button>
                            <Link v-if="can('record-payments')" class="chip" :href="route('payments', { record: o.id })">Record payment</Link>
                            <button v-if="can('register-facility-users')" class="chip" @click="open(o)">Edit</button>
                            <button v-if="can('register-facility-users')" class="chip" @click="resendLogin(o)">Resend login</button>
                            <button v-if="can('request-removals') && !o.removal" class="chip" @click="askRemoval(o)">Remove</button>
                        </template>
                    </td>
                </tr>
            </table>
            <div v-else class="empty">No facility users match these filters.</div>
            <Pagination :page="occupancies" />
        </div>

        <Modal :show="show" :title="editing ? `Edit ${editing.name}` : 'Register facility user'" @close="show = false">
            <form class="grid" @submit.prevent="save">
                <label class="field">Full name<input v-model="form.name" required /><InputError :message="form.errors.name" /></label>
                <div class="form" style="margin: 0">
                    <label class="field">Email<input v-model="form.email" type="email" required /><InputError :message="form.errors.email" /></label>
                    <label class="field">Phone<input v-model="form.phone" type="tel" placeholder="0801 234 5678" /><InputError :message="form.errors.phone" /></label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">Facility
                        <select v-model="form.facility_id" required @change="form.space_id = ''">
                            <option value="" disabled>Choose…</option>
                            <option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }} ({{ f.vacant.length }} vacant)</option>
                        </select>
                    </label>
                    <label class="field">Room / space
                        <select v-model="form.space_id" required :disabled="!form.facility_id">
                            <option value="" disabled>{{ spaces.length ? 'Choose…' : 'No vacant spaces' }}</option>
                            <option v-for="s in spaces" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <InputError :message="form.errors.space_id" />
                    </label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">Category
                        <select v-model="form.category_id" required>
                            <option value="" disabled>Choose…</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <InputError :message="form.errors.category_id" />
                    </label>
                    <label v-if="!editing" class="field">Billing starts<input v-model="form.start_date" type="date" required /><InputError :message="form.errors.start_date" /></label>
                </div>
                <p class="sub small" style="margin: 0">
                    <template v-if="!editing">A random password is emailed to the facility user. They choose their own when they first log in. The first month is billed straight away.</template>
                    <template v-else>A new category applies from the next month billed.</template>
                </p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="show = false">Cancel</button>
                    <button class="btn" :disabled="form.processing">{{ editing ? 'Save' : 'Register and email login' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!removing" :title="`Remove ${removing?.name ?? ''}`" @close="removing = null">
            <form class="grid" @submit.prevent="submitRemoval">
                <p class="sub" style="margin: 0">
                    {{ removing?.name }} will be removed from {{ removing?.space }}, {{ removing?.facility }}.
                    <template v-if="!can('approve-removals')">Your company admin must approve the removal first.</template>
                    <template v-else>Their login is disabled, the space becomes vacant and unpaid months ahead are cancelled.</template>
                </p>
                <label class="field">Reason<input v-model="removalForm.reason" required maxlength="255" /><InputError :message="removalForm.errors.reason" /></label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="removing = null">Cancel</button>
                    <button class="btn" :disabled="removalForm.processing">{{ can('approve-removals') ? 'Remove now' : 'Request removal' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!reviewing" :title="`Removal of ${reviewing?.name ?? ''}`" @close="reviewing = null">
            <div v-if="reviewing" class="grid">
                <p style="margin: 0">
                    <b>{{ reviewing.removal.by }}</b> asked on {{ reviewing.removal.at }} to remove {{ reviewing.name }} from {{ reviewing.space }}, {{ reviewing.facility }}:
                    <br /><i>"{{ reviewing.removal.reason }}"</i>
                </p>
                <p v-if="reviewing.outstanding > 0" class="error" style="margin: 0">They still owe {{ N(reviewing.outstanding) }}.</p>
                <p class="sub small" style="margin: 0">Approving disables their login, frees the space and cancels unpaid months ahead.</p>
                <label class="field">Note (optional)<input v-model="reviewForm.note" maxlength="255" /></label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" :disabled="reviewForm.processing" @click="decide('reject')">Reject</button>
                    <button type="button" class="btn" :disabled="reviewForm.processing" @click="decide('approve')">Approve removal</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
