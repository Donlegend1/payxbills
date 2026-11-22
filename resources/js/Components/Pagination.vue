<script setup>
import { Link } from '@inertiajs/vue3';

// A Laravel paginator: { data, links, from, to, total, last_page }
defineProps({ page: { type: Object, required: true } });
</script>

<template>
    <div v-if="page.total" class="pager">
        <span class="sub">{{ page.from }}–{{ page.to }} of {{ page.total }}</span>
        <div v-if="page.last_page > 1" class="pager-links">
            <template v-for="(l, i) in page.links" :key="i">
                <Link v-if="l.url" :href="l.url" class="chip" :class="{ on: l.active }" preserve-scroll preserve-state><span v-html="l.label" /></Link>
                <span v-else class="chip disabled"><span v-html="l.label" /></span>
            </template>
        </div>
    </div>
</template>
