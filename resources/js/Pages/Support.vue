<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Icon from '@/Components/Icon.vue';

// Help for company staff, facility users and visitors. Inside the app when logged in.
const page = usePage();
const user = computed(() => page.props.auth.user);
const company = computed(() => page.props.currentCompany);
const platform = computed(() => page.props.platform);
const isFacilityUser = computed(() => page.props.auth.can.includes('use-portal'));

const wa = (number, text) => {
    let digits = (number ?? '').replace(/\D/g, '');
    if (!digits) return null;
    if (digits.startsWith('0')) digits = '234' + digits.slice(1);
    return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
};

// Facility users get help from their company; everyone else from PayXbills
const contact = computed(() => isFacilityUser.value && company.value
    ? { name: company.value.name, whatsapp: wa(company.value.whatsapp, `Hello ${company.value.name}, I need help with my account.`), email: null, phone: null }
    : { name: `${platform.value.name} support`, whatsapp: wa(platform.value.whatsapp, 'Hello PayXbills support, I need help.'), email: platform.value.email, phone: platform.value.phone });

const companyFaqs = [
    ['How do I add my facility users?', 'Go to Facility users and click "Register facility user", or download the sample Excel sheet, fill it in and upload it to add many at once.'],
    ['When does billing start?', 'Only when your admin clicks "Activate" on a facility user. They then receive their pay link straight away and their bill every month on their billing day. "Deactivate" stops billing.'],
    ['Why have my changes not appeared?', 'Changes made by staff other than the admin (category, amount, address, contact details) wait for the admin to approve them. You will see "Changes pending" on the facility user until then.'],
    ['How do facility users pay?', 'Each bill comes by email and SMS with a secure link. They can pay by card or transfer online, or pay you by cash or bank and you record the payment with proof.'],
    ['Where does online money go?', 'To your own Paystack account once your admin adds your secret key under Settings. Until then the PayXbills account is used.'],
    ['Can I change our logo and WhatsApp number?', 'Yes. Your admin can upload your logo and set the WhatsApp number your facility users see under Settings.'],
];

const userFaqs = [
    ['Do I need to log in?', 'No. Pay from the link in your monthly bill. Log in only if you want to see all your payments and receipts.'],
    ['How do I see my transactions?', 'On the log in page, click "Forgot password?", enter the email your company has for you and choose a password. You can then log in any time.'],
    ['I paid but it shows unpaid', 'Online payments show within minutes. Cash, bank or POS payments show once your company\'s accountant has confirmed them. If it takes longer, contact your company.'],
    ['I lost my pay link', 'Contact your company on WhatsApp or by phone and they can send it again.'],
];

const faqs = computed(() => (isFacilityUser.value ? userFaqs : user.value ? companyFaqs : [...companyFaqs.slice(0, 3), ...userFaqs.slice(0, 2)]));
const openFaq = ref(0);
</script>

<template>
    <component :is="user ? AppLayout : PublicLayout">
        <Head title="Help & support" />

        <div :class="user ? '' : 'wrap'" :style="user ? 'max-width: 900px' : 'max-width: 900px; padding: 32px 0'">
            <div class="top">
                <div>
                    <h1>Help & support</h1>
                    <p class="sub" style="margin: 4px 0 0">
                        <template v-if="isFacilityUser">Questions about your bill or payments? {{ company?.name }} can help.</template>
                        <template v-else>We're here to help you get the most out of {{ platform.name }}.</template>
                    </p>
                </div>
            </div>

            <div class="grid auto mb">
                <a v-if="contact.whatsapp" :href="contact.whatsapp" target="_blank" rel="noopener" class="feature" style="text-decoration: none; color: inherit">
                    <span class="ico g"><Icon name="phone" /></span>
                    <h3>Chat on WhatsApp</h3>
                    <p>Message {{ contact.name }}. We usually reply within working hours.</p>
                </a>
                <a v-if="contact.email" :href="`mailto:${contact.email}`" class="feature" style="text-decoration: none; color: inherit">
                    <span class="ico"><Icon name="mail" /></span>
                    <h3>Email</h3>
                    <p>{{ contact.email }}</p>
                </a>
                <a v-if="contact.phone" :href="`tel:${contact.phone.replace(/\s/g, '')}`" class="feature" style="text-decoration: none; color: inherit">
                    <span class="ico a"><Icon name="phone" /></span>
                    <h3>Call us</h3>
                    <p>{{ contact.phone }}</p>
                </a>
                <Link v-if="!isFacilityUser" :href="route('contact')" class="feature" style="text-decoration: none; color: inherit">
                    <span class="ico p"><Icon name="bell" /></span>
                    <h3>Send a message</h3>
                    <p>Tell us what you need and we'll get back to you within one working day.</p>
                </Link>
                <div v-if="isFacilityUser && !contact.whatsapp" class="feature">
                    <span class="ico"><Icon name="building" /></span>
                    <h3>Contact {{ company?.name }}</h3>
                    <p>Use the phone number or email on your bill or receipt.</p>
                </div>
            </div>

            <div class="card">
                <h2>Frequently asked questions</h2>
                <div v-for="([q, a], i) in faqs" :key="q" class="rep" style="display: block; cursor: pointer" @click="openFaq = openFaq === i ? -1 : i">
                    <button type="button" class="linkish" style="font-weight: 600; text-align: left; background: none; border: 0; padding: 0; color: inherit" :aria-expanded="openFaq === i">{{ q }}</button>
                    <p v-show="openFaq === i" class="sub" style="margin: 6px 0 0">{{ a }}</p>
                </div>
            </div>
        </div>
    </component>
</template>
