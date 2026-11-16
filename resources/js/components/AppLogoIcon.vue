<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M12 2C7.03 2 3 3.57 3 5.5v13C3 20.43 7.03 22 12 22s9-1.57 9-3.5v-13C21 3.57 16.97 2 12 2Zm7 16.48C18.68 19.07 16.03 20 12 20s-6.68-.93-7-1.52v-3.1C6.62 16.39 9.2 17 12 17s5.38-.61 7-1.62v3.1Zm0-5.48c-.32.59-2.97 1.52-7 1.52S5.32 13.59 5 13v-3.12C6.62 10.89 9.2 11.5 12 11.5s5.38-.61 7-1.62V13Zm-7-3.5c-4.03 0-6.68-.93-7-1.5.32-.57 2.97-1.5 7-1.5s6.68.93 7 1.5c-.32.57-2.97 1.5-7 1.5Zm5.5 5.25 1.5-1 .75 1.25-2.25 1.5v-1.75Z"
        />
    </svg>
</template>
