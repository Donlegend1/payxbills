<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';
import { N, today } from '@/format';
import { useCan } from '@/auth';
import { useFilters } from '@/filters';

const props = defineProps({
    occupancies: Object, filters: Object, facilities: Array, categories: Array,
    binTypes: Array, districts: Array, pendingRemovals: Number, pendingChanges: Number, notActivated: Number,
});

const page = usePage();
const can = useCan();
const { filters, apply } = useFilters('facility-users', {
    status: props.filters.status ?? '',
    facility: props.filters.facility ?? '',
    category: props.filters.category ?? '',
    bin: props.filters.bin ?? '',
    district: props.filters.district ?? '',
    q: props.filters.q ?? '',
});
const chips = [
    ['', 'All'], ['billing', 'Being billed'], ['not-activated', 'Not activated'], ['deactivated', 'Deactivated'],
    ['green', 'Up to date'], ['amber', '1 month overdue'], ['red', 'More than 1 overdue'],
    ['changes', 'Changes pending'], ['removal', 'Removal pending'], ['removed', 'Removed'],
];
const billingPill = { active: ['green', 'Billing on'], pending: ['amber', 'Not activated'], deactivated: ['red', 'Deactivated'] };

const status = (o) => {
    if (!o.is_active) return ['red', 'Removed'];
    if (o.removal) return ['amber', 'Removal pending'];
    if (o.overdue_count === 0) return ['green', o.outstanding > 0 ? 'Not yet due' : 'Up to date'];
    return o.overdue_count === 1 ? ['amber', '1 month overdue'] : ['red', o.overdue_count + ' months overdue'];
};

// Register / edit. Every detail except the name is optional.
const query = new URLSearchParams(window.location.search);
const blank = {
    name: '', email: '', phone: '', house_no: '', street: '', district: '',
    bin_type_id: '', bins: '', frequency: '', billing_day: '',
    facility_id: query.get('facility') ?? '', space_id: '', category_id: '', start_date: today(),
};
const form = useForm({ ...blank });
const editing = ref(null);
const show = ref(query.has('new') && can('register-facility-users'));
const fields = Object.keys(blank);

function open(o = null) {
    editing.value = o;
    form.clearErrors();
    form.defaults(o ? Object.fromEntries(fields.map((k) => [k, o[k] ?? ''])) : { ...blank });
    form.reset();
    show.value = true;
}

// Vacant spaces in the chosen facility, plus the client's current space when editing
const spaces = computed(() => {
    const f = props.facilities.find((f) => String(f.id) === String(form.facility_id));
    const list = f ? [...f.vacant] : [];
    if (editing.value?.space_id && String(editing.value.facility_id) === String(form.facility_id)) {
        list.unshift({ id: editing.value.space_id, name: editing.value.space + ' (current)' });
    }
    return list;
});

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    // Empty inputs are sent as null so they clear the detail
    form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
    editing.value ? form.put(route('facility-users.update', editing.value.id), opts) : form.post(route('facility-users.store'), opts);
}

const remind = (o) => router.post(route('facility-users.remind', o.id), {}, { preserveScroll: true });
const resendLogin = (o) => confirm(`Email ${o.name} a temporary password so they can log in and see their transactions?`) && router.post(route('facility-users.resend-login', o.id), {}, { preserveScroll: true });

// Billing on / off (admin)
const activate = (o) => confirm(`Start billing ${o.name}? They will get their pay link now${o.monthly > 0 ? ` and this month's bill of ${N(o.monthly)}` : ''}, then a bill every month.`)
    && router.post(route('facility-users.activate', o.id), {}, { preserveScroll: true });
const deactivate = (o) => confirm(`Stop billing ${o.name}? No more monthly bills or reminders until activated again. Unpaid months ahead are cancelled; what they owe now stays owed.`)
    && router.post(route('facility-users.deactivate', o.id), {}, { preserveScroll: true });

// Changes waiting for the admin
const changing = ref(null);
const changeForm = useForm({ note: '' });
function reviewChange(o) {
    changing.value = o;
    changeForm.reset();
}
const decideChange = (action) => changeForm.post(route(`changes.${action}`, changing.value.change.id), { preserveScroll: true, onSuccess: () => (changing.value = null) });

// Add a bin type without leaving the form
const adding = ref(null); // 'bin-types'
const chosenCategory = computed(() => props.categories.find((c) => String(c.id) === String(form.category_id)));
const newName = ref('');
function addItem() {
    const name = newName.value.trim();
    const list = adding.value;
    if (!name) return;
    router.post(route('lists.store', list), { name }, {
        preserveScroll: true,
        preserveState: true,
        only: ['binTypes', 'flash', 'errors'],
        onSuccess: () => {
            const item = props.binTypes.find((i) => i.name.toLowerCase() === name.toLowerCase());
            if (item) form.bin_type_id = item.id;
            adding.value = null;
            newName.value = '';
        },
    });
}

// Import from Excel
const importing = ref(false);
const importForm = useForm({ file: null, activate: false });
const importErrors = computed(() => page.props.flash.importErrors ?? []);
const submitImport = () => importForm.post(route('facility-users.import'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { importForm.reset(); importing.value = importErrors.value.length > 0; },
});

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

        <PageHeader title="Facility users" sub="Everyone you bill each month. Billing starts when the admin activates them; they then get their pay link every month.">
            <div v-if="can('register-facility-users')" class="actions">
                <a class="chip" :href="route('facility-users.template')">Download sample sheet</a>
                <button class="chip" @click="importing = true">Upload sheet</button>
                <button class="chip on" @click="open()">Register facility user</button>
            </div>
        </PageHeader>

        <div v-if="(can('activate-billing') && notActivated > 0 && filters.status !== 'not-activated') || (can('approve-changes') && pendingChanges > 0 && filters.status !== 'changes')" class="notice" style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            <template v-if="can('activate-billing') && notActivated > 0">
                {{ notActivated }} facility user{{ notActivated > 1 ? 's are' : ' is' }} not being billed yet.
                <button class="chip" @click="filters.status = 'not-activated'; apply()">Activate</button>
            </template>
            <template v-if="can('approve-changes') && pendingChanges > 0">
                {{ pendingChanges }} change request{{ pendingChanges > 1 ? 's' : '' }} waiting for your approval.
                <button class="chip" @click="filters.status = 'changes'; apply()">Review</button>
            </template>
        </div>

        <div v-if="can('approve-removals') && pendingRemovals > 0 && filters.status !== 'removal'" class="notice" style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            {{ pendingRemovals }} removal request{{ pendingRemovals > 1 ? 's' : '' }} waiting for your approval.
            <button class="chip" @click="filters.status = 'removal'; apply()">Review</button>
        </div>

        <div class="tools">
            <button v-for="c in chips" :key="c[0]" class="chip" :class="{ on: filters.status === c[0] }" @click="filters.status = c[0]; apply()">{{ c[1] }}</button>
            <select v-if="facilities.length" v-model="filters.facility" aria-label="Facility" @change="apply()">
                <option value="">All facilities</option>
                <option v-for="f in facilities" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
            </select>
            <select v-if="binTypes.length" v-model="filters.bin" aria-label="Bin type" @change="apply()">
                <option value="">All bin types</option>
                <option v-for="t in binTypes" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
            </select>
            <select v-if="districts.length" v-model="filters.district" aria-label="District" @change="apply()">
                <option value="">All districts</option>
                <option v-for="d in districts" :key="d" :value="d">{{ d }}</option>
            </select>
            <select v-if="categories.length" v-model="filters.category" aria-label="Billing category" @change="apply()">
                <option value="">All categories</option>
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
            <input v-model="filters.q" type="search" placeholder="Search by name, phone, account, address, category, facility, bin type…" aria-label="Search facility users" style="min-width: 260px" @input="apply(300)" />
        </div>

        <div class="card scroll">
            <table v-if="occupancies.data.length" style="min-width: 1000px">
                <tr><th>Account</th><th>Facility user</th><th>Place</th><th>Service</th><th>Monthly</th><th>Paid up to</th><th>Outstanding</th><th>Status</th><th></th></tr>
                <tr v-for="o in occupancies.data" :key="o.id">
                    <td>{{ o.code }}</td>
                    <td>{{ o.name }}<div class="sub small">{{ o.email }}<template v-if="o.email && o.phone"> · </template>{{ o.phone }}</div></td>
                    <td>{{ o.place }}<div v-if="o.category" class="sub small">{{ o.category }}</div></td>
                    <td><span :class="{ sub: !o.service }">{{ o.service ?? '—' }}</span></td>
                    <td>{{ N(o.monthly) }}<div v-if="o.billing_day" class="sub small">billed on day {{ o.billing_day }}</div></td>
                    <td>{{ o.paid_through ?? '—' }}</td>
                    <td>{{ N(o.outstanding) }}</td>
                    <td>
                        <StatusPill v-if="o.is_active" :status="billingPill[o.billing][1]" :tone="billingPill[o.billing][0]" />
                        <StatusPill v-if="!o.is_active || o.billing === 'active'" :status="status(o)[1]" :tone="status(o)[0]" />
                        <StatusPill v-if="o.change" status="Changes pending" tone="amber" />
                        <div v-if="o.removal" class="sub small" style="max-width: 220px">"{{ o.removal.reason }}" – {{ o.removal.by }}, {{ o.removal.at }}</div>
                        <div v-if="o.ended" class="sub small">Removed {{ o.ended }}</div>
                    </td>
                    <td class="actions">
                        <template v-if="o.is_active">
                            <button v-if="o.billing !== 'active' && can('activate-billing')" class="chip on" @click="activate(o)">Activate</button>
                            <button v-if="o.change && can('approve-changes')" class="chip on" @click="reviewChange(o)">Review changes</button>
                            <button v-if="o.removal && can('approve-removals')" class="chip on" @click="review(o)">Review removal</button>
                            <button v-if="can('send-reminders') && o.outstanding > 0" class="chip" @click="remind(o)">Remind</button>
                            <Link v-if="can('record-payments')" class="chip" :href="route('payments', { record: o.id })">Record payment</Link>
                            <button v-if="can('register-facility-users')" class="chip" @click="open(o)">Edit</button>
                            <button v-if="can('register-facility-users') && o.email" class="chip" @click="resendLogin(o)">Send login details</button>
                            <button v-if="o.billing === 'active' && can('activate-billing')" class="chip" @click="deactivate(o)">Deactivate</button>
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
                <p v-if="editing && !can('approve-changes')" class="notice" style="margin: 0">Your changes are sent to the admin and take effect once approved.</p>
                <p v-else-if="editing?.change" class="notice" style="margin: 0">This facility user has changes waiting for your approval from {{ editing.change.by }}.</p>
                <p class="sub small" style="margin: 0">Only the name is required. Fill in what you know; you can edit the rest later.</p>
                <label class="field">Full name<input v-model="form.name" required /><InputError :message="form.errors.name" /></label>
                <div class="form" style="margin: 0">
                    <label class="field">Email<input v-model="form.email" type="email" /><InputError :message="form.errors.email" /></label>
                    <label class="field">Phone<input v-model="form.phone" type="tel" placeholder="0801 234 5678" /><InputError :message="form.errors.phone" /></label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">House no.<input v-model="form.house_no" maxlength="50" /><InputError :message="form.errors.house_no" /></label>
                    <label class="field">Street<input v-model="form.street" /><InputError :message="form.errors.street" /></label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">District<input v-model="form.district" list="districts" /><InputError :message="form.errors.district" /></label>
                    <label class="field">
                        <span style="display: flex; justify-content: space-between">Category <Link v-if="can('manage-categories')" :href="route('categories')" class="linkish">Manage categories</Link></span>
                        <select v-model="form.category_id">
                            <option value="">Not set (not billed)</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <span v-if="chosenCategory" class="sub small">They pay this category's monthly amount. Changing the category's amount reprices everyone in it.</span>
                        <InputError :message="form.errors.category_id" />
                    </label>
                </div>
                <datalist id="districts"><option v-for="d in districts" :key="d" :value="d" /></datalist>
                <div v-if="adding" class="tools" style="margin: 0; padding: 10px; border: 1px dashed var(--line); border-radius: 10px">
                    <input v-model="newName" maxlength="100" placeholder="New bin type, e.g. Mammoth bin" @keydown.enter.prevent="addItem" />
                    <button type="button" class="chip on" @click="addItem">Add</button>
                    <button type="button" class="chip" @click="adding = null; newName = ''">Cancel</button>
                    <InputError :message="$page.props.errors.name" />
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">
                        <span style="display: flex; justify-content: space-between">Type of bins <button v-if="can('add-list-items')" type="button" class="linkish" @click="adding = 'bin-types'">+ New</button></span>
                        <select v-model="form.bin_type_id">
                            <option value="">Not set</option>
                            <option v-for="t in binTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <InputError :message="form.errors.bin_type_id" />
                    </label>
                    <label class="field">No. of bins<input v-model="form.bins" type="number" min="0" /><InputError :message="form.errors.bins" /></label>
                </div>
                <div class="form" style="margin: 0">
                    <label class="field">Frequency (times per week)<input v-model="form.frequency" type="number" min="0" max="21" /><InputError :message="form.errors.frequency" /></label>
                    <label class="field">Billing day of the month (1-28)<input v-model="form.billing_day" type="number" min="1" max="28" placeholder="Company default" /><InputError :message="form.errors.billing_day" /></label>
                </div>
                <label v-if="!editing" class="field" style="max-width: 50%">Billing starts<input v-model="form.start_date" type="date" /><InputError :message="form.errors.start_date" /></label>

                <details :open="!!form.facility_id">
                    <summary class="sub">Facility and space (optional)</summary>
                    <div class="grid" style="margin-top: 8px">
                        <div class="form" style="margin: 0">
                            <label class="field">Facility
                                <select v-model="form.facility_id" @change="form.space_id = ''">
                                    <option value="">None</option>
                                    <option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }} ({{ f.vacant.length }} vacant)</option>
                                </select>
                                <InputError :message="form.errors.facility_id" />
                            </label>
                            <label class="field">Room / space
                                <select v-model="form.space_id" :disabled="!form.facility_id">
                                    <option value="">None</option>
                                    <option v-for="s in spaces" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                                <InputError :message="form.errors.space_id" />
                            </label>
                        </div>
                    </div>
                </details>

                <p class="sub small" style="margin: 0">
                    <template v-if="!editing">Nothing is sent and nothing is billed until the admin activates this facility user. They then get their pay link and a bill every month; they do not need to log in.</template>
                    <template v-else>A new category applies from the next month billed.</template>
                </p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="show = false">Cancel</button>
                    <button class="btn" :disabled="form.processing">{{ editing ? (can('approve-changes') ? 'Save' : 'Send for approval') : 'Register facility user' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="importing" title="Upload facility users from Excel" @close="importing = false">
            <form class="grid" @submit.prevent="submitImport">
                <ol class="sub" style="margin: 0; padding-left: 18px">
                    <li>Download the <a :href="route('facility-users.template')">sample sheet</a>.</li>
                    <li>Fill in one facility user per row. Only <b>Full name</b> is required.</li>
                    <li>Upload it here.</li>
                </ol>
                <p class="sub small" style="margin: 0">
                    To update facility users you already have, <a :href="route('facility-users.export')">download your facility users</a>, edit them and upload the sheet again:
                    rows with an <b>Account code</b> update that facility user (only filled-in cells change<template v-if="!can('approve-changes')">, after the admin approves</template>).
                    New bin types are added to your list; a new category needs its monthly amount in the sheet.
                </p>
                <label class="field">Excel or CSV file
                    <input type="file" accept=".xlsx,.csv" required @change="importForm.file = $event.target.files[0]" />
                    <InputError :message="importForm.errors.file" />
                </label>
                <label v-if="can('activate-billing')" class="check"><input v-model="importForm.activate" type="checkbox" /> Activate new facility users now (start billing and send their pay link)</label>
                <div v-if="importErrors.length" class="notice" style="background: #d6454522; color: var(--red); max-height: 220px; overflow: auto">
                    <b>Rows not imported</b>
                    <div v-for="e in importErrors" :key="e" class="small">{{ e }}</div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" @click="importing = false">Close</button>
                    <button class="btn" :disabled="importForm.processing || !importForm.file">{{ importForm.processing ? 'Importing…' : 'Import' }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!changing" :title="`Changes to ${changing?.name ?? ''}`" @close="changing = null">
            <div v-if="changing" class="grid">
                <p class="sub" style="margin: 0">Requested by {{ changing.change.by }} on {{ changing.change.at }}. Nothing changes until you approve.</p>
                <table style="min-width: 0">
                    <tr><th>Detail</th><th>Now</th><th>Change to</th></tr>
                    <tr v-for="[label, from, to] in changing.change.fields" :key="label"><td>{{ label }}</td><td class="sub">{{ from }}</td><td><b>{{ to }}</b></td></tr>
                </table>
                <label class="field">Note (optional)<input v-model="changeForm.note" maxlength="255" /></label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" :disabled="changeForm.processing" @click="decideChange('reject')">Reject</button>
                    <button type="button" class="btn" :disabled="changeForm.processing" @click="decideChange('approve')">Approve changes</button>
                </div>
            </div>
        </Modal>

        <Modal :show="!!removing" :title="`Remove ${removing?.name ?? ''}`" @close="removing = null">
            <form class="grid" @submit.prevent="submitRemoval">
                <p class="sub" style="margin: 0">
                    {{ removing?.name }} ({{ removing?.place }}) will be removed and no longer billed.
                    <template v-if="!can('approve-removals')">Your company admin must approve the removal first.</template>
                    <template v-else>Their login is disabled, any space becomes vacant and unpaid months ahead are cancelled.</template>
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
                    <b>{{ reviewing.removal.by }}</b> asked on {{ reviewing.removal.at }} to remove {{ reviewing.name }} ({{ reviewing.place }}):
                    <br /><i>"{{ reviewing.removal.reason }}"</i>
                </p>
                <p v-if="reviewing.outstanding > 0" class="error" style="margin: 0">They still owe {{ N(reviewing.outstanding) }}.</p>
                <p class="sub small" style="margin: 0">Approving disables their login, frees any space and cancels unpaid months ahead.</p>
                <label class="field">Note (optional)<input v-model="reviewForm.note" maxlength="255" /></label>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" :disabled="reviewForm.processing" @click="decide('reject')">Reject</button>
                    <button type="button" class="btn" :disabled="reviewForm.processing" @click="decide('approve')">Approve removal</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
