<script setup>
defineProps({ title: { type: String, required: true }, sub: { type: String, default: '' } });
</script>

<template>
    <div class="top">
        <div>
            <h1>{{ title }}</h1>
            <div class="sub">{{ sub }}</div>
        </div>
        <div><slot /></div>
    </div>
</template>
