<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

defineProps({ show: Boolean, title: { type: String, required: true }, wide: Boolean });
const emit = defineEmits(['close']);

const onKey = (e) => e.key === 'Escape' && emit('close');
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="modal-back" @click.self="emit('close')">
            <div class="modal card" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
                <div class="modal-head">
                    <h2>{{ title }}</h2>
                    <button type="button" class="chip" aria-label="Close" @click="emit('close')">✕</button>
                </div>
                <slot />
            </div>
        </div>
    </Teleport>
</template>
