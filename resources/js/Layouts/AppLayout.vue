<script setup>
import { computed, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { toast, toastState } from '@/toast';
import { useCan } from '@/auth';
import WhatsAppButton from '@/Components/WhatsAppButton.vue';

const page = usePage();
const can = useCan();
const user = computed(() => page.props.auth.user);
const impersonator = computed(() => page.props.auth.impersonator);
const company = computed(() => page.props.currentCompany);
const platform = computed(() => page.props.platform);
const managing = computed(() => page.props.auth.managing);

// Super admin: every company, and lists across all of them
const platformItems = computed(() =>
    can('manage-companies')
        ? [
              ['platform.companies', 'All companies'],
              ['platform.facility-users', 'All facility users'],
              ['platform.payments', 'All payments'],
              ['platform.invoices', 'All invoices'],
              ['platform.facilities', 'All facilities'],
              ['platform.categories', 'All categories'],
          ]
        : [],
);

const items = computed(() =>
    [
        ['portal', 'My dues & payments', 'use-portal'],
        ['dashboard', 'Dashboard', 'view-dashboard'],
        ['facility-users', 'Facility users', 'view-facility-users'],
        ['facilities', 'Facilities', 'view-facilities'],
        ['categories', 'Categories', 'manage-categories'],
        ['invoices', 'Invoices', 'view-invoices'],
        ['payments', 'Payments', 'view-payments'],
        ['receivables', 'Receivables', 'view-receivables'],
        ['expenditure', 'Expenditure', 'view-expenditure'],
        ['reports', 'Reports', 'view-reports'],
        ['analytics', 'Analytics', 'view-analytics'],
        ['notifications', 'Notifications', 'view-notifications'],
        ['sms-logs', 'SMS log', 'view-sms-logs'],
        ['users', 'Users', 'manage-users'],
        ['settings', 'Settings', 'manage-settings'],
        ['support', 'Help & support', null],
    ].filter(([, , ability]) => !ability || can(ability)),
);

// Show success / error messages flashed by the server
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.error) toast(flash.error, true);
        else if (flash?.success) toast(flash.success);
    },
    { immediate: true, deep: true },
);

const isOn = (r) => route().current(r) || route().current(r + '.*');
</script>

<template>
    <div class="app">
        <nav class="side" aria-label="Main">
            <Link :href="route('dashboard')" class="brand"><img v-if="company?.logo" :src="company.logo" alt="" class="side-logo" />{{ company?.name ?? platform.name }}<small>{{ company ? `${platform.name} · ${platform.vendor}` : `Super admin · ${platform.vendor}` }}</small></Link>
            <div class="menu">
                <template v-if="platformItems.length">
                    <div class="menu-head">Super admin</div>
                    <Link v-for="[r, label] in platformItems" :key="r" :href="route(r)" :class="{ on: isOn(r) }" :aria-current="isOn(r) ? 'page' : undefined">{{ label }}</Link>
                    <div v-if="company" class="menu-head">{{ company.name }}</div>
                    <Link v-else-if="managing" :href="route('dashboard')" class="menu-head" style="text-decoration: underline">Back to {{ managing.name }}</Link>
                </template>
                <Link v-for="[r, label] in items" :key="r" :href="route(r)" :class="{ on: isOn(r) }" :aria-current="isOn(r) ? 'page' : undefined">{{ label }}</Link>
            </div>
            <div class="tagline">Efficient billing. Timely payments. Stronger growth.</div>
        </nav>

        <main class="content">
            <div v-if="company && managing" class="impersonating" role="status">
                <span>You are managing <b>{{ company.name }}</b> as super admin. Everything you do here is recorded under your name.</span>
                <Link :href="route('platform.close')" method="post" as="button" class="chip on">Back to all companies</Link>
            </div>
            <div v-if="impersonator" class="impersonating" role="status">
                <span>You are logged in as <b>{{ user.name }}</b> ({{ user.role_label }}<template v-if="company">, {{ company.name }}</template>). Signed in by {{ impersonator }}.</span>
                <Link :href="route('impersonate.leave')" method="post" as="button" class="chip on">Return to my account</Link>
            </div>
            <div class="userbar">
                <div class="name">
                    <b>{{ user.name }}</b><br />
                    <span class="sub">{{ user.role_label }}<template v-if="company"> · {{ company.name }}</template></span>
                </div>
                <Link :href="route('profile.edit')" class="chip">Profile & password</Link>
                <Link :href="route('logout')" method="post" as="button" class="chip">Log out</Link>
            </div>
            <slot />
        </main>
    </div>
    <!-- Facility users reach their company; staff reach PayXbills support -->
    <WhatsAppButton
        :number="can('use-portal') ? company?.whatsapp : platform.whatsapp"
        :message="can('use-portal') ? `Hello ${company?.name}, I need help with my account.` : `Hello PayXbills support, I'm ${user.name} from ${company?.name ?? 'the platform'}.`"
    />
    <div id="toast" role="status" :class="{ show: toastState.show, bad: toastState.error }">{{ toastState.text }}</div>
</template>
