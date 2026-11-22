<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';

const form = useForm({
    company_name: '',
    company_phone: '',
    company_address: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => form.post(route('register.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <AuthLayout>
        <Head title="Register your company" />

        <div>
            <h1>Register your company</h1>
            <div class="sub">Create your company's PayXbills workspace. You will be its admin and can add your staff afterwards.</div>
        </div>

        <form class="grid" @submit.prevent="submit">
            <label class="field">Company name
                <input v-model="form.company_name" required autofocus autocomplete="organization" placeholder="e.g. Cosmopolitan Cleaners Limited" />
                <InputError :message="form.errors.company_name" />
            </label>
            <label class="field">Company phone (optional)
                <input v-model="form.company_phone" type="tel" autocomplete="tel" />
                <InputError :message="form.errors.company_phone" />
            </label>
            <label class="field">Company address (optional)
                <input v-model="form.company_address" autocomplete="street-address" />
                <InputError :message="form.errors.company_address" />
            </label>

            <label class="field">Your full name
                <input v-model="form.name" required autocomplete="name" />
                <InputError :message="form.errors.name" />
            </label>
            <label class="field">Your email (you will log in with it)
                <input v-model="form.email" type="email" required autocomplete="email" />
                <InputError :message="form.errors.email" />
            </label>
            <label class="field">Password
                <input v-model="form.password" type="password" required autocomplete="new-password" />
                <InputError :message="form.errors.password" />
            </label>
            <label class="field">Confirm password
                <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
            </label>

            <button class="btn block" :disabled="form.processing">{{ form.processing ? 'Creating your workspace…' : 'Create company account' }}</button>
        </form>

        <p class="sub" style="text-align: center; margin: 0">Already registered? <Link :href="route('login')" class="linkish">Log in</Link></p>
    </AuthLayout>
</template>
