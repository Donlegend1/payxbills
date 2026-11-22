<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';

defineProps({ canResetPassword: Boolean, status: String });

const form = useForm({ email: '', password: '', remember: false });

const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <AuthLayout>
        <Head title="Log in" />

        <div>
            <h1>Log in</h1>
            <div class="sub">Staff and facility users of every PayXbills company sign in here.</div>
        </div>

        <div v-if="status" class="notice">{{ status }}</div>

        <form class="grid" @submit.prevent="submit">
            <label class="field">Email address
                <input v-model="form.email" type="email" required autofocus autocomplete="username" placeholder="name@yourcompany.com" />
                <InputError :message="form.errors.email" />
            </label>
            <label class="field">
                <span style="display: flex; justify-content: space-between">
                    Password
                    <Link v-if="canResetPassword" :href="route('password.request')" class="linkish">Forgot password?</Link>
                </span>
                <input v-model="form.password" type="password" required autocomplete="current-password" />
                <InputError :message="form.errors.password" />
            </label>
            <label class="check"><input v-model="form.remember" type="checkbox" /> Keep me signed in</label>
            <button class="btn block" :disabled="form.processing">{{ form.processing ? 'Signing in…' : 'Sign in' }}</button>
        </form>

        <p class="sub" style="text-align: center; margin: 0">New to PayXbills? <Link :href="route('register')" class="linkish">Register your company</Link></p>
    </AuthLayout>
</template>
