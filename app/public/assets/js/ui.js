import { h } from './dom.js';
import { errorMessage, FIELD_ERRORS } from './i18n.js';

export function toast(message, type = 'info', ms = 4500) {
    const box = document.getElementById('toasts');
    if (!box) return;
    const el = h('div', { class: `toast toast--${type}`, role: type === 'error' ? 'alert' : 'status' }, message);
    box.append(el);
    setTimeout(() => el.remove(), ms);
}

export function toastError(err) {
    toast(errorMessage(err), 'error', 6000);
}

export function spinner(small = false) {
    return h('span', { class: small ? 'spinner spinner--sm' : 'spinner', 'aria-hidden': 'true' });
}

export function loading(text = 'جارٍ التحميل…') {
    return h('div', { class: 'boot', role: 'status' }, spinner(), h('p', {}, text));
}

export function progressBar(value, max, { label = '', variant = '' } = {}) {
    const pct = max > 0 ? Math.max(0, Math.min(100, Math.round((value / max) * 100))) : 0;
    return h('div', {
        class: `progress ${variant ? `progress--${variant}` : ''}`,
        role: 'progressbar',
        'aria-valuemin': '0',
        'aria-valuemax': String(max),
        'aria-valuenow': String(Math.min(value, max)),
        'aria-label': label || null,
    }, h('div', { class: 'progress__bar', style: { width: `${pct}%` } }));
}

export function meter(label, valueText, value, max, variant = '') {
    return h('div', {},
        h('div', { class: 'meter-label' }, h('span', {}, label), h('span', { class: 'muted' }, valueText)),
        progressBar(value, max, { label, variant }));
}

export function badge(text, variant = '') {
    return h('span', { class: `badge ${variant ? `badge--${variant}` : ''}` }, text);
}

export function emptyState(text, action = null) {
    return h('div', { class: 'empty' }, h('p', {}, text), action);
}

export function banner(text, variant = 'info', extra = null) {
    return h('div', { class: `banner banner--${variant}`, role: variant === 'danger' ? 'alert' : 'status' }, h('div', {}, text, extra));
}

const dateFmt = new Intl.DateTimeFormat('ar-u-nu-latn', { dateStyle: 'medium' });
const dateTimeFmt = new Intl.DateTimeFormat('ar-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short' });

/** Server timestamps are UTC "YYYY-MM-DD HH:MM:SS". */
export function parseUtc(s) {
    if (!s) return null;
    return new Date(String(s).replace(' ', 'T') + (String(s).length <= 10 ? 'T00:00:00Z' : 'Z'));
}

export function formatDate(s) {
    const d = parseUtc(s);
    return d ? dateFmt.format(d) : '';
}

export function formatDateTime(s) {
    const d = parseUtc(s);
    return d ? dateTimeFmt.format(d) : '';
}

export function relativeDue(s) {
    const d = parseUtc(s);
    if (!d) return '';
    const days = Math.round((d.getTime() - Date.now()) / 86400000);
    if (days <= 0) return 'مستحق الآن';
    if (days === 1) return 'غدًا';
    return `بعد ${days} أيام`;
}

/** Shows server-side field errors under the matching inputs of a form. */
export function showFieldErrors(form, err) {
    form.querySelectorAll('.field__error').forEach((e) => e.remove());
    form.querySelectorAll('.field--error').forEach((e) => e.classList.remove('field--error'));
    const fields = err?.details?.fields || {};
    for (const [name, code] of Object.entries(fields)) {
        const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!input) continue;
        const field = input.closest('.field');
        field?.classList.add('field--error');
        const id = `err-${name}`;
        input.setAttribute('aria-describedby', id);
        input.setAttribute('aria-invalid', 'true');
        field?.append(h('span', { class: 'field__error', id }, FIELD_ERRORS[code] || code));
    }
}

export function field(label, input, hint = null) {
    const id = input.id || `f-${Math.random().toString(36).slice(2, 9)}`;
    input.id = id;
    return h('div', { class: 'field' }, h('label', { for: id }, label), input, hint ? h('span', { class: 'hint' }, hint) : null);
}

export function setBusy(button, busy, busyText = null) {
    if (!button) return;
    if (busy) {
        button.dataset.label = button.textContent;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        if (busyText) button.textContent = busyText;
    } else {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        if (button.dataset.label && busyText) button.textContent = button.dataset.label;
    }
}

export function storage(key, value) {
    try {
        if (value === undefined) return localStorage.getItem(key);
        if (value === null) localStorage.removeItem(key);
        else localStorage.setItem(key, value);
    } catch {
        return null;
    }
    return null;
}

export function applyTheme(theme = storage('gac-theme') || 'auto') {
    document.documentElement.dataset.theme = ['light', 'dark', 'auto'].includes(theme) ? theme : 'auto';
}
