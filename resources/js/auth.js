import { usePage } from '@inertiajs/vue3';

/**
 * can('approve-payments') — abilities come from App\Enums\Role via the shared `auth.can` prop.
 * The server checks them again on every request; this only hides what the user cannot use.
 */
export function useCan() {
    const page = usePage();
    return (ability) => page.props.auth.can.includes(ability);
}
