import { api, setCsrf, setTtsMode, setUnauthorizedHandler } from './api.js';
import { h, clear } from './dom.js';
import { icon } from './icons.js';
import { applyTheme, toastError } from './ui.js';
import * as dashboard from './views/dashboard.js';
import * as lessons from './views/lessons.js';
import * as lesson from './views/lesson.js';
import * as practice from './views/practice.js';
import * as reviews from './views/reviews.js';
import * as vocabulary from './views/vocabulary.js';
import * as progress from './views/progress.js';
import * as settings from './views/settings.js';
import * as privacy from './views/privacy.js';
import * as login from './views/login.js';
import * as setup from './views/setup.js';

const ROUTES = [
    { path: '/', view: dashboard, title: 'الرئيسية' },
    { path: '/lessons', view: lessons, title: 'الدروس' },
    { path: '/lessons/:id', view: lesson, title: 'الدرس' },
    { path: '/session/:id', view: practice, title: 'التمرين' },
    { path: '/reviews', view: reviews, title: 'المراجعة' },
    { path: '/vocabulary', view: vocabulary, title: 'المفردات' },
    { path: '/progress', view: progress, title: 'التقدّم' },
    { path: '/settings', view: settings, title: 'الإعدادات' },
    { path: '/privacy', view: privacy, title: 'الخصوصية', public: true },
    { path: '/login', view: login, title: 'تسجيل الدخول', public: true, guestOnly: true },
    { path: '/setup', view: setup, title: 'الإعداد الأولي', public: true, guestOnly: true },
];

const NAV = [
    { href: '#/', label: 'الرئيسية', icon: 'home', match: /^\/$/ },
    { href: '#/lessons', label: 'الدروس', icon: 'book', match: /^\/(lessons|session)/ },
    { href: '#/reviews', label: 'المراجعة', icon: 'review', match: /^\/reviews/ },
    { href: '#/vocabulary', label: 'المفردات', icon: 'cards', match: /^\/vocabulary/ },
    { href: '#/progress', label: 'التقدّم', icon: 'chart', match: /^\/progress/ },
    { href: '#/settings', label: 'الإعدادات', icon: 'settings', match: /^\/settings/ },
];

const state = { user: null, setupRequired: false, registrationOpen: false };
let cleanup = null;
let renderToken = 0;

export function navigate(path) {
    if (location.hash === `#${path}`) {
        route();
    } else {
        location.hash = `#${path}`;
    }
}

function match(path) {
    for (const r of ROUTES) {
        const names = [];
        const re = new RegExp('^' + r.path.replace(/:([a-z]+)/g, (_, n) => {
            names.push(n);
            return '(\\d{1,18})';
        }) + '$');
        const m = path.match(re);
        if (m) {
            const params = {};
            names.forEach((n, i) => { params[n] = m[i + 1]; });
            return { route: r, params };
        }
    }
    return null;
}

function renderNav(path) {
    const nav = document.getElementById('mainnav');
    const topbar = document.getElementById('topbar');
    topbar.hidden = !state.user;
    clear(nav);
    if (!state.user) return;
    for (const item of NAV) {
        nav.append(h('a', { href: item.href, 'aria-current': item.match.test(path) ? 'page' : null }, icon(item.icon), item.label));
    }
    const out = h('a', { href: '#/login', role: 'button' }, icon('logout'), 'خروج');
    out.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
            await api('api/auth/logout', { method: 'POST' });
        } catch (err) {
            toastError(err);
        }
        state.user = null;
        navigate('/login');
    });
    nav.append(out);
}

async function route() {
    const token = ++renderToken;
    const path = (location.hash.replace(/^#/, '') || '/').split('?')[0];
    let found = match(path);
    if (!found) {
        navigate('/');
        return;
    }
    if (!found.route.public && !state.user) {
        navigate(state.setupRequired ? '/setup' : '/login');
        return;
    }
    if (found.route.guestOnly && state.user) {
        navigate('/');
        return;
    }
    if (typeof cleanup === 'function') {
        try { cleanup(); } catch { /* ignore */ }
    }
    cleanup = null;
    document.getElementById('mainnav')?.classList.remove('is-open');
    document.getElementById('menu-toggle')?.setAttribute('aria-expanded', 'false');
    renderNav(path);
    const root = document.getElementById('app');
    clear(root);
    document.title = `${found.route.title} — German AI Coach`;
    const ctx = {
        params: found.params,
        user: state.user,
        state,
        navigate,
        setUser: (u) => { state.user = u; renderNav(path); },
        isCurrent: () => token === renderToken,
    };
    try {
        const result = await found.route.view.render(root, ctx);
        if (token === renderToken && typeof result === 'function') cleanup = result;
    } catch (err) {
        if (token !== renderToken) return;
        if (err && err.status === 401) return;
        toastError(err);
        root.append(h('div', { class: 'card card--danger' }, 'تعذّر عرض الصفحة. حاول تحديثها.'));
    }
    if (token === renderToken) {
        const main = document.getElementById('main');
        main.focus({ preventScroll: true });
        window.scrollTo(0, 0);
    }
}

async function boot() {
    applyTheme();
    setUnauthorizedHandler(() => {
        if (state.user) {
            state.user = null;
            navigate('/login');
        }
    });
    document.getElementById('menu-toggle')?.addEventListener('click', (e) => {
        const nav = document.getElementById('mainnav');
        const open = nav.classList.toggle('is-open');
        e.currentTarget.setAttribute('aria-expanded', String(open));
    });
    try {
        const data = await api('api/auth/session');
        setCsrf(data.csrf_token);
        setTtsMode(data.tts_mode);
        state.user = data.user;
        state.setupRequired = Boolean(data.setup_required);
        state.registrationOpen = Boolean(data.registration_open);
    } catch (err) {
        toastError(err);
    }
    document.getElementById('boot')?.remove();
    window.addEventListener('hashchange', route);
    route();
}

boot();
