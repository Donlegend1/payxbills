// ₦625,000 — kobo shown only when present
export const N = (v) => {
    const n = Math.round(Number(v || 0) * 100) / 100;
    return '₦' + n.toLocaleString('en-NG', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
};

// ₦7.1M style, for chart labels
export const NM = (v) => '₦' + (Number(v || 0) / 1e6).toLocaleString('en-NG', { maximumFractionDigits: 2 }) + 'M';

export const sum = (a, f) => a.reduce((s, x) => s + f(x), 0);

export const today = () => new Date().toISOString().slice(0, 10);
