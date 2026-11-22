<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';

defineProps({ status: String });

const user = usePage().props.auth.user;

const profile = useForm({ name: user.name, email: user.email });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

const saveProfile = () => profile.patch(route('profile.update'), { preserveScroll: true });
const savePassword = () =>
    password.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <AppLayout>
        <Head title="My account" />

        <PageHeader title="My account" sub="Update your name, email and password." />

        <div class="grid auto">
            <form class="card grid" @submit.prevent="saveProfile">
                <h2>Profile</h2>
                <div v-if="status" class="notice">{{ status }}</div>
                <label class="field">Name<input v-model="profile.name" required autocomplete="name" /><InputError :message="profile.errors.name" /></label>
                <label class="field">Email<input v-model="profile.email" type="email" required autocomplete="username" /><InputError :message="profile.errors.email" /></label>
                <div><button class="btn" :disabled="profile.processing">Save profile</button></div>
            </form>

            <form class="card grid" @submit.prevent="savePassword">
                <h2>Password</h2>
                <div v-if="password.recentlySuccessful" class="notice">Password updated.</div>
                <label class="field">Current password<input v-model="password.current_password" type="password" autocomplete="current-password" /><InputError :message="password.errors.current_password" /></label>
                <label class="field">New password<input v-model="password.password" type="password" autocomplete="new-password" /><InputError :message="password.errors.password" /></label>
                <label class="field">Confirm new password<input v-model="password.password_confirmation" type="password" autocomplete="new-password" /><InputError :message="password.errors.password_confirmation" /></label>
                <div><button class="btn" :disabled="password.processing">Update password</button></div>
            </form>
        </div>
    </AppLayout>
</template>
