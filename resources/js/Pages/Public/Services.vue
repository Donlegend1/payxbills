<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Icon from '@/Components/Icon.vue';
import { features } from '@/data/platform';
</script>

<template>
    <PublicLayout>
        <Head title="Features" />

        <section class="page-hero">
            <div class="wrap">
                <span class="eyebrow">Features</span>
                <h1>From your first facility to your monthly collection report</h1>
                <p>Every company on PayXbills gets its own private workspace: its own facilities, staff, facility users, settings and payment account.</p>
            </div>
        </section>

        <section class="block" style="padding-top: 24px">
            <div class="wrap cards3">
                <div v-for="f in features" :key="f.key" class="feature">
                    <span class="ico"><Icon :name="f.icon" /></span>
                    <h3>{{ f.title }}</h3>
                    <p>{{ f.text }}</p>
                    <ul>
                        <li v-for="p in f.points" :key="p">{{ p }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="block alt">
            <div class="wrap split">
                <div class="sec-head">
                    <h2>A login for everyone on your team</h2>
                    <p>Each person sees only what their role needs.</p>
                </div>
                <div class="grid">
                    <div class="feature"><span class="ico"><Icon name="shield" /></span><h3>Company admin</h3><p>Sets up facilities, categories, settings and staff accounts, and approves removals.</p></div>
                    <div class="feature"><span class="ico g"><Icon name="invoice" /></span><h3>Accountant and management</h3><p>Invoices, payments, receivables, expenditure and reports.</p></div>
                    <div class="feature"><span class="ico a"><Icon name="users" /></span><h3>Supervisors</h3><p>Register facility users and record payments in the facilities they look after.</p></div>
                    <div class="feature"><span class="ico p"><Icon name="card" /></span><h3>Facility users</h3><p>See their dues, pay online and download receipts.</p></div>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="wrap">
                <div class="band">
                    <div><h2>Try it with your own facilities</h2><p>Register your company and set up your first facility in minutes.</p></div>
                    <Link :href="route('register')" class="btn">Register your company</Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
