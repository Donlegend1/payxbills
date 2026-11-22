<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Icon from '@/Components/Icon.vue';
</script>

<template>
    <PublicLayout>
        <Head title="About us" />

        <section class="page-hero">
            <div class="wrap">
                <span class="eyebrow">About us</span>
                <h1>PayXbills is built by Fivestar Technologies</h1>
                <p>We build software that helps businesses bill their customers clearly and get paid on time.</p>
            </div>
        </section>

        <section class="block" style="padding-top: 24px">
            <div class="wrap split">
                <div class="prose">
                    <p>Companies that manage hostels, estates, markets and offices spend too much time on billing: working out who owes what, sending invoices, chasing payments and matching bank transfers.</p>
                    <p>PayXbills does that work for them. Each client company gets its own secure workspace to manage its facilities and facility users, and its billing runs itself every month.</p>
                    <p>Our first clients, such as Cosmopolitan Cleaners, use PayXbills to bill hundreds of facility users across several sites from one dashboard.</p>
                    <div class="cta" style="margin-top: 20px">
                        <Link :href="route('register')" class="btn">Register your company</Link>
                        <Link :href="route('contact')" class="btn ghost">Talk to us</Link>
                    </div>
                </div>
                <div class="grid">
                    <div class="feature"><span class="ico"><Icon name="shield" /></span><h3>Private by design</h3><p>Every company's data is kept separate. Your staff see only your company.</p></div>
                    <div class="feature"><span class="ico g"><Icon name="card" /></span><h3>Your money, your account</h3><p>Online payments go to your own Paystack account, not ours.</p></div>
                    <div class="feature"><span class="ico a"><Icon name="clock" /></span><h3>Runs on schedule</h3><p>Invoices, reminders and receipts go out on time, every month.</p></div>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
