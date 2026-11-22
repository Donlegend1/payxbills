<script setup>
import { Link } from '@inertiajs/vue3';
import Logo from '@/Components/Logo.vue';
</script>

<template>
    <div class="auth">
        <aside class="auth-art">
            <Logo />
            <div>
                <h2>Bill your facility users. Get paid on time.</h2>
                <p>PayXbills gives every company one place to manage its facilities, facility users, invoices, payments and receivables.</p>
                <ul>
                    <li>Monthly invoices issued and sent automatically</li>
                    <li>Online payments straight to your own account</li>
                    <li>Live receivables and collection reports</li>
                </ul>
            </div>
            <span style="font-size: 12px; color: #8f9bc0; position: relative; z-index: 1">PayXbills by Fivestar Technologies</span>
        </aside>
        <section class="auth-form">
            <div class="box">
                <slot />
                <div class="foot"><Link href="/" class="linkish">← Back to website</Link></div>
            </div>
        </section>
    </div>
</template>
