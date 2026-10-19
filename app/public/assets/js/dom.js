// Minimal DOM builder. All text is inserted with textContent / text nodes — never innerHTML —
// so learner answers and AI output can never become executable markup.

/**
 * h('div', {class: 'card', onClick: fn, 'aria-label': 'x'}, 'text', childNode, [more])
 */
export function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') {
            el.className = value;
        } else if (key === 'text') {
            el.textContent = String(value);
        } else if (key === 'dataset') {
            Object.assign(el.dataset, value);
        } else if (key === 'style') {
            for (const [prop, val] of Object.entries(value)) el.style.setProperty(prop, val);
        } else if (key.startsWith('on') && typeof value === 'function') {
            el.addEventListener(key.slice(2).toLowerCase(), value);
        } else if ((key === 'href' || key === 'src') && /^\s*javascript:/i.test(String(value))) {
            continue; // never allow script URLs
        } else if (value === true) {
            el.setAttribute(key, '');
        } else {
            el.setAttribute(key, String(value));
        }
    }
    append(el, children);
    return el;
}

export function append(el, children) {
    for (const child of [children].flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
}

/** German text: always LTR and marked as German for screen readers and fonts. */
export function de(text, tag = 'span', cls = '') {
    return h(tag, { lang: 'de', dir: 'ltr', class: `de ${cls}`.trim() }, text ?? '');
}

/** Arabic text: RTL. */
export function ar(text, tag = 'span', cls = '') {
    return h(tag, { lang: 'ar', dir: 'rtl', class: cls || null }, text ?? '');
}

export function clear(el) {
    el.replaceChildren();
    return el;
}

export function qs(sel, root = document) {
    return root.querySelector(sel);
}
