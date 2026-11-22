<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Logo from '@/Components/Logo.vue';
import Icon from '@/Components/Icon.vue';
import { platform } from '@/data/platform';

const page = usePage();
const open = ref(false);
const links = [
    ['home', 'Home'],
    ['services', 'Features'],
    ['about', 'About us'],
    ['contact', 'Contact'],
];
const year = new Date().getFullYear();
</script>

<template>
    <header class="site-head">
        <div class="wrap">
            <Logo />
            <button class="btn ghost menu-btn" :aria-expanded="open" aria-label="Menu" @click="open = !open"><Icon name="menu" /></button>
            <nav class="site-nav" :class="{ open }" aria-label="Main">
                <Link v-for="[r, label] in links" :key="r" :href="route(r)" class="link" :class="{ on: route().current(r) }">{{ label }}</Link>
                <Link v-if="page.props.auth.user" :href="route('dashboard')" class="btn">My account</Link>
                <template v-else>
                    <Link :href="route('login')" class="btn ghost">Log in</Link>
                    <Link :href="route('register')" class="btn">Register your company</Link>
                </template>
            </nav>
        </div>
    </header>

    <main>
        <slot />
    </main>

    <footer class="site-foot">
        <div class="wrap">
            <div class="cols">
                <div>
                    <Logo />
                    <p style="max-width: 320px">Billing and payment collection for companies that manage facilities: hostels, estates, markets, offices and more.</p>
                </div>
                <div>
                    <h4>PayXbills</h4>
                    <Link v-for="[r, label] in links" :key="r" :href="route(r)">{{ label }}</Link>
                    <Link :href="route('register')">Register your company</Link>
                </div>
                <div>
                    <h4>Get in touch</h4>
                    <a :href="`tel:${platform.phone.replace(/\s/g, '')}`">{{ platform.phone }}</a>
                    <a :href="`mailto:${platform.email}`">{{ platform.email }}</a>
                    <span>{{ platform.hours }}</span>
                </div>
            </div>
            <div class="legal">
                <span>© {{ year }} {{ platform.vendor }}</span>
                <span>PayXbills is a product of {{ platform.vendor }}</span>
            </div>
        </div>
    </footer>
</template>
