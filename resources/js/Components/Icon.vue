<script setup>
defineProps({ name: { type: String, required: true }, size: { type: Number, default: 20 } });

const paths = {
    home: 'M3 11 12 4l9 7M5 10v10h14V10M10 20v-6h4v6',
    building: 'M4 21V5l8-2v18M12 7h8v14M7 8h2M7 12h2M7 16h2M15 11h2M15 15h2M2 21h20',
    cross: 'M9 3h6v6h6v6h-6v6H9v-6H3V9h6z',
    book: 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 19V5M8 7h7',
    bin: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6',
    spark: 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6',
    invoice: 'M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h5',
    card: 'M3 6h18v12H3zM3 10h18M7 15h3',
    bell: 'M6 16V11a6 6 0 1 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0',
    chart: 'M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6',
    shield: 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z M9 12l2 2 4-4',
    clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
    mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
    pin: 'M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12zM12 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
    users: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 3a4 4 0 0 1 0 8M22 21a7 7 0 0 0-5-6.7',
    menu: 'M4 6h16M4 12h16M4 18h16',
    leaf: 'M5 19c0-9 6-14 15-14 0 9-5 15-14 15M5 19l7-7',
};
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[name]" />
    </svg>
</template>
