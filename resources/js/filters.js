import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Filters kept in the query string. Call apply() after a change, or apply(300) to debounce typing.
 */
export function useFilters(routeName, initial = {}) {
    const filters = reactive({ ...initial });
    let timer;

    const apply = (delay = 0) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null && v !== undefined));
            router.get(route(routeName), query, { preserveState: true, preserveScroll: true, replace: true });
        }, delay);
    };

    return { filters, apply };
}
