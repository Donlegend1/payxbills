import { reactive } from 'vue';

export const toastState = reactive({ text: '', show: false, error: false });

let timer;

export function toast(text, error = false) {
    toastState.text = text;
    toastState.error = error;
    toastState.show = true;
    clearTimeout(timer);
    timer = setTimeout(() => (toastState.show = false), error ? 4000 : 2500);
}
