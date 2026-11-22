<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ settings: Object, paystack: Object, logo: String });

// Logo: uploaded on its own, as soon as a file is chosen
const logoForm = useForm({ logo: null });
const logoInput = ref(null);
const uploadLogo = (e) => {
    logoForm.logo = e.target.files[0];
    if (logoForm.logo) logoForm.post(route('settings.logo'), { preserveScroll: true, forceFormData: true, onFinish: () => (logoInput.value.value = '') });
};
const removeLogo = () => {
    if (confirm('Remove your logo? Receipts and invoices will show your company name only.')) router.delete(route('settings.logo.destroy'), { preserveScroll: true });
};

const form = useForm({ ...props.settings, paystack_secret_key: '' });
const save = () => form.put(route('settings.update'), { preserveScroll: true, onSuccess: () => form.reset('paystack_secret_key') });
const removeKey = () => {
    form.paystack_secret_key = 'remove';
    save();
};

const fields = [
    ['Company name', 'company_name', 'text'],
    ['Invoice number prefix', 'invoice_prefix', 'text'],
    ['Billing day of the month (1-28)', 'billing_day', 'number'],
    ['Payment due after (days)', 'due_days', 'number'],
    ['Reminders, days after due date (0 = on the due date)', 'reminder_days', 'text'],
    ['Grace days before overdue', 'grace_days', 'number'],
    ['Most months payable at once', 'max_months', 'number'],
];

</script>

<template>
    <AppLayout>
        <Head title="Settings" />

        <PageHeader title="Settings" sub="Your company's details and the rules PayXbills follows for it. Only your company admin can change them.">
            <button class="chip on" :disabled="form.processing" @click="save">Save changes</button>
        </PageHeader>

        <div class="card mb">
            <h2>Company logo</h2>
            <div class="rep">
                <div class="brand-head">
                    <img v-if="logo" :src="logo" alt="Your company logo" class="brand-logo" />
                    <div v-else class="brand-logo empty-logo">No logo</div>
                    <div>
                        <b>{{ form.company_name }}</b>
                        <div class="sub">Shown with your company name on receipts, invoices and the pay page. PNG, JPG or WebP, up to 2 MB. A wide logo on a white or transparent background prints best.</div>
                        <InputError :message="logoForm.errors.logo" />
                    </div>
                </div>
                <div class="actions">
                    <label class="chip on" style="cursor: pointer">
                        {{ logoForm.processing ? 'Uploading…' : logo ? 'Change logo' : 'Upload logo' }}
                        <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" hidden :disabled="logoForm.processing" @change="uploadLogo" />
                    </label>
                    <button v-if="logo" class="chip" @click="removeLogo">Remove</button>
                </div>
            </div>
        </div>

        <div class="card mb">
            <h2>Billing and reminders</h2>
            <div class="form">
                <label v-for="[label, key, type] in fields" :key="key">{{ label }}<input v-model="form[key]" :type="type" /><InputError :message="form.errors[key]" /></label>
            </div>
            <p class="sub small">On the billing day each facility user's month is issued and a payment-due email and SMS with the pay link is sent. Unpaid facility users are then reminded on each of the reminder days.</p>
            <div class="rep">
                <div><b>Automatic monthly billing</b><div class="sub">Issue and send each month's dues on the billing day</div></div>
                <button class="chip" :class="{ on: form.auto_invoicing }" @click="form.auto_invoicing = !form.auto_invoicing">{{ form.auto_invoicing ? 'On' : 'Off' }}</button>
            </div>
        </div>

        <div class="card mb">
            <h2>Payments and messages</h2>
            <div class="rep">
                <div>
                    <b>Paystack</b>
                    <div class="sub">
                        <template v-if="paystack.own">Connected to your company's own Paystack account. Online payments go straight to you.</template>
                        <template v-else-if="paystack.configured">Using the PayXbills platform account. Add your own secret key below so payments go straight to your company.</template>
                        <template v-else>Not connected. Add your Paystack secret key below so facility users can pay online.</template>
                        On your Paystack dashboard, set the webhook URL to <code>{{ paystack.webhook }}</code>
                    </div>
                </div>
                <span class="pill" :class="paystack.configured ? 's-green' : 's-amber'">{{ paystack.configured ? (paystack.test ? 'Test mode' : 'Live') : 'Off' }}</span>
            </div>
            <div class="form" style="margin-top: 10px">
                <label>Paystack secret key {{ paystack.own ? '(saved, paste a new one to replace it)' : '' }}
                    <input v-model="form.paystack_secret_key" type="password" autocomplete="off" placeholder="sk_live_…" />
                    <InputError :message="form.errors.paystack_secret_key" />
                </label>
            </div>
            <button v-if="paystack.own" class="chip" style="margin-top: 8px" :disabled="form.processing" @click="removeKey">Remove our key</button>
            <div class="form" style="margin-top: 10px">
                <label>SMS sender name (max 11)<input v-model="form.sms_sender" maxlength="11" /><InputError :message="form.errors.sms_sender" /></label>
                <label>WhatsApp number for facility users<input v-model="form.whatsapp_number" type="tel" placeholder="e.g. 0803 123 4567" /><InputError :message="form.errors.whatsapp_number" /></label>
            </div>
            <label class="field" style="margin-top: 10px">Bank details for transfers (shown on invoices and the pay page)
                <textarea v-model="form.bank_details" rows="3" placeholder="Bank name, account name and account number"></textarea>
                <InputError :message="form.errors.bank_details" />
            </label>
            <div class="rep">
                <div><b>Copy invoices to the NRS</b><div class="sub">Marks each invoice for a copy to the NRS</div></div>
                <button class="chip" :class="{ on: form.nrs_enabled }" @click="form.nrs_enabled = !form.nrs_enabled">{{ form.nrs_enabled ? 'On' : 'Off' }}</button>
            </div>
        </div>

        <div class="card mb">
            <div class="rep">
                <div><b>Staff and roles</b><div class="sub">Add, edit and log in as your company's admin, management, accountant and supervisor accounts</div></div>
                <div class="actions">
                    <Link class="chip" :href="route('facilities')">Facilities</Link>
                    <Link class="chip" :href="route('categories')">Categories</Link>
                    <Link class="chip on" :href="route('users')">Users</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
