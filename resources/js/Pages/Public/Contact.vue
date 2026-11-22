<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';
import { audiences, platform } from '@/data/platform';

defineProps({ status: String });

const form = useForm({ name: '', email: '', phone: '', service: '', message: '' });

const submit = () => form.post(route('contact.store'), { preserveScroll: true, onSuccess: () => form.reset() });
</script>

<template>
    <PublicLayout>
        <Head title="Contact" />

        <section class="page-hero">
            <div class="wrap">
                <span class="eyebrow">Contact</span>
                <h1>Talk to the PayXbills team</h1>
                <p>Questions about PayXbills, a demo for your team, or help moving your billing over? We reply within one working day.</p>
            </div>
        </section>

        <section class="block" style="padding-top: 24px">
            <div class="wrap split">
                <form class="card grid" style="padding: 22px" @submit.prevent="submit">
                    <div v-if="status" class="notice">{{ status }}</div>
                    <label class="field">Full name<input v-model="form.name" required autocomplete="name" /><InputError :message="form.errors.name" /></label>
                    <div class="form" style="margin: 0">
                        <label class="field">Email<input v-model="form.email" type="email" required autocomplete="email" /><InputError :message="form.errors.email" /></label>
                        <label class="field">Phone (optional)<input v-model="form.phone" type="tel" autocomplete="tel" /><InputError :message="form.errors.phone" /></label>
                    </div>
                    <label class="field">Your business
                        <select v-model="form.service">
                            <option value="">Choose one</option>
                            <option v-for="a in audiences" :key="a">{{ a }}</option>
                            <option>Other</option>
                        </select>
                    </label>
                    <label class="field">How can we help?<textarea v-model="form.message" rows="5" required placeholder="How many facilities and facility users you manage, and how you bill them today…"></textarea><InputError :message="form.errors.message" /></label>
                    <button class="btn" :disabled="form.processing">{{ form.processing ? 'Sending…' : 'Send message' }}</button>
                </form>

                <div class="grid">
                    <div class="feature"><span class="ico"><Icon name="phone" /></span><h3>Call us</h3><p><a :href="`tel:${platform.phone.replace(/\s/g, '')}`">{{ platform.phone }}</a></p></div>
                    <div class="feature"><span class="ico g"><Icon name="mail" /></span><h3>Email</h3><p><a :href="`mailto:${platform.email}`">{{ platform.email }}</a></p></div>
                    <div class="feature"><span class="ico a"><Icon name="pin" /></span><h3>Office</h3><p>{{ platform.address }}<br />{{ platform.hours }}</p></div>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
