<script setup>
import { computed } from 'vue';

// Floating "chat on WhatsApp" button. Hidden when there is no number.
const props = defineProps({ number: { type: String, default: null }, message: { type: String, default: '' } });

const href = computed(() => {
    let digits = (props.number ?? '').replace(/\D/g, '');
    if (!digits) return null;
    // Local Nigerian numbers (0803...) become international (234803...)
    if (digits.startsWith('0')) digits = '234' + digits.slice(1);
    return `https://wa.me/${digits}${props.message ? '?text=' + encodeURIComponent(props.message) : ''}`;
});
</script>

<template>
    <a v-if="href" :href="href" target="_blank" rel="noopener" class="whatsapp no-print" aria-label="Chat with us on WhatsApp" title="Chat with us on WhatsApp">
        <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true" fill="currentColor">
            <path d="M16.04 3C9.4 3 4 8.36 4 14.97c0 2.11.56 4.17 1.62 5.99L4 29l8.24-1.6a12.1 12.1 0 0 0 3.8.6C22.67 28 28 22.64 28 16.03 28 9.42 22.67 3 16.04 3zm0 22.9c-1.17 0-2.32-.2-3.41-.58l-.47-.17-4.89.95.98-4.73-.3-.49a9.8 9.8 0 0 1-1.5-5.22C6.45 9.6 10.75 5.3 16.04 5.3c5.28 0 9.58 4.43 9.58 10.73 0 5.3-4.3 9.87-9.58 9.87zm5.27-7.36c-.29-.14-1.71-.84-1.97-.94-.27-.1-.46-.14-.65.15-.19.29-.75.94-.92 1.13-.17.19-.34.22-.63.07-.29-.14-1.22-.45-2.32-1.43-.86-.76-1.44-1.7-1.6-1.99-.17-.29-.02-.44.13-.59.13-.13.29-.34.43-.5.15-.17.19-.29.29-.48.1-.19.05-.36-.02-.5-.07-.15-.65-1.56-.89-2.14-.23-.56-.47-.48-.65-.49h-.55c-.19 0-.5.07-.77.36-.26.29-1.01.99-1.01 2.41 0 1.42 1.04 2.8 1.18 2.99.14.19 2.04 3.12 4.95 4.37.69.3 1.23.48 1.65.61.69.22 1.32.19 1.82.12.55-.08 1.71-.7 1.95-1.37.24-.68.24-1.26.17-1.38-.07-.12-.26-.19-.55-.33z" />
        </svg>
    </a>
</template>
