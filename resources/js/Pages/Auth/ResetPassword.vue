<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ email: { type: String, required: true }, token: { type: String, required: true } });

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

const submit = () => form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <AuthLayout>
        <Head title="Reset password" />

        <div>
            <h1>Choose a new password</h1>
            <div class="sub">Use at least 8 characters.</div>
        </div>

        <form class="grid" @submit.prevent="submit">
            <label class="field">Email address
                <input v-model="form.email" type="email" required autocomplete="username" />
                <InputError :message="form.errors.email" />
            </label>
            <label class="field">New password
                <input v-model="form.password" type="password" required autofocus autocomplete="new-password" />
                <InputError :message="form.errors.password" />
            </label>
            <label class="field">Confirm password
                <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
                <InputError :message="form.errors.password_confirmation" />
            </label>
            <button class="btn block" :disabled="form.processing">Reset password</button>
        </form>
    </AuthLayout>
</template>
