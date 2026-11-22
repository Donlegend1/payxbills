<script setup>
import { ref } from 'vue';

// A password field with a button to show or hide what has been typed.
const model = defineModel({ type: String, default: '' });
defineOptions({ inheritAttrs: false });

const visible = ref(false);
</script>

<template>
    <span class="password-input">
        <input v-model="model" v-bind="$attrs" :type="visible ? 'text' : 'password'" />
        <button type="button" class="toggle" :aria-label="visible ? 'Hide password' : 'Show password'" :aria-pressed="visible" :title="visible ? 'Hide password' : 'Show password'" @click="visible = !visible">
            <svg v-if="!visible" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" />
            </svg>
            <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.5 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94" /><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19" /><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" /><path d="M1 1l22 22" />
            </svg>
        </button>
    </span>
</template>
