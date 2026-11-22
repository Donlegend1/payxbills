<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';

defineProps({ status: String });

const form = useForm({ email: '' });

const submit = () => form.post(route('password.email'));
</script>

<template>
    <AuthLayout>
        <Head title="Forgot password" />

        <div>
            <h1>Forgot password</h1>
            <div class="sub">Enter your work email and we will send you a link to choose a new password.</div>
        </div>

        <div v-if="status" class="notice">{{ status }}</div>

        <form class="grid" @submit.prevent="submit">
            <label class="field">Email address
                <input v-model="form.email" type="email" required autofocus autocomplete="username" />
                <InputError :message="form.errors.email" />
            </label>
            <button class="btn block" :disabled="form.processing">Email reset link</button>
        </form>

        <Link :href="route('login')" class="linkish" style="text-align: center">Back to login</Link>
    </AuthLayout>
</template>
