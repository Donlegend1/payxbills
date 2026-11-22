<script setup>
defineProps({ message: { type: String } });
</script>

<template>
    <div v-show="message" class="error">{{ message }}</div>
</template>
