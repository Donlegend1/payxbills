<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

defineProps({ href: { type: String, default: '/' }, tagline: { type: String, default: 'by Fivestar Technologies' } });
</script>

<template>
    <Link :href="href" class="logo">
        <span class="mark"><Icon name="card" :size="18" /></span>
        <span>PayXbills<small>{{ tagline }}</small></span>
    </Link>
</template>
