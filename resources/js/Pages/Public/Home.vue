<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Icon from '@/Components/Icon.vue';
import { audiences, features } from '@/data/platform';
</script>

<template>
    <PublicLayout>
        <Head title="Facility billing and payment collection" />

        <section class="hero">
            <div class="wrap">
                <div>
                    <span class="eyebrow">Hostels · Estates · Markets · Offices</span>
                    <h1>Bill every facility user. Get paid every month.</h1>
                    <p class="lead">PayXbills lets your company manage its facilities and the people who use them, issue monthly invoices automatically and collect payments online, all in one place.</p>
                    <div class="cta">
                        <Link :href="route('register')" class="btn">Register your company</Link>
                        <Link :href="route('services')" class="btn ghost">See the features</Link>
                    </div>
                </div>
                <div class="hero-card" aria-label="What you get">
                    <div class="rowi"><span class="ico"><Icon name="building" /></span><div><b>All your facilities in one place</b><div class="sub">Rooms, flats, shops and stalls, occupied or vacant</div></div></div>
                    <div class="rowi"><span class="ico g"><Icon name="invoice" /></span><div><b>Invoices sent automatically</b><div class="sub">By email and SMS on your billing day</div></div></div>
                    <div class="rowi"><span class="ico a"><Icon name="card" /></span><div><b>Paid straight to your account</b><div class="sub">Card or transfer through your own Paystack</div></div></div>
                    <div class="rowi"><span class="ico p"><Icon name="chart" /></span><div><b>Know who owes what</b><div class="sub">Live receivables and collection reports</div></div></div>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="wrap">
                <div class="sec-head">
                    <h2>Everything you need to collect dues</h2>
                    <p>Built for companies that manage facilities on behalf of owners, or run their own.</p>
                </div>
                <div class="cards3">
                    <div v-for="f in features" :key="f.key" class="feature">
                        <span class="ico"><Icon :name="f.icon" /></span>
                        <h3>{{ f.title }}</h3>
                        <p>{{ f.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="block alt">
            <div class="wrap">
                <div class="sec-head">
                    <h2>Up and running in a day</h2>
                    <p>No installation. Register, set up and start billing.</p>
                </div>
                <div class="cards4 steps">
                    <div class="feature"><h3>Register</h3><p>Create your company account and your admin login in a minute.</p></div>
                    <div class="feature"><h3>Set up</h3><p>Add your facilities, their spaces and the monthly amount for each category.</p></div>
                    <div class="feature"><h3>Add users</h3><p>Register facility users into their spaces. They get their login by email.</p></div>
                    <div class="feature"><h3>Get paid</h3><p>Invoices go out every month with a pay link. Receipts follow straight away.</p></div>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="wrap split">
                <div class="sec-head">
                    <h2>Who uses PayXbills</h2>
                    <p>Any company that bills people for the use of a space or a service, every month.</p>
                </div>
                <div class="feature">
                    <ul>
                        <li v-for="a in audiences" :key="a">{{ a }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="wrap">
                <div class="band">
                    <div>
                        <h2>Ready to stop chasing payments?</h2>
                        <p>Register your company and bill your first facility users today.</p>
                    </div>
                    <div class="cta">
                        <Link :href="route('register')" class="btn">Register your company</Link>
                        <Link :href="route('contact')" class="btn ghost">Talk to us</Link>
                    </div>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
