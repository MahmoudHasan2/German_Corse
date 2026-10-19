import { api } from '../api.js';
import { h } from '../dom.js';
import { errorMessage } from '../i18n.js';
import { field, setBusy, showFieldErrors, banner } from '../ui.js';
import { keysCard } from './keys.js';

export async function render(root, ctx) {
    if (ctx.state.setupRequired) {
        ctx.navigate('/setup');
        return;
    }
    const email = h('input', { class: 'input', type: 'email', name: 'email', autocomplete: 'username', required: true, dir: 'ltr', inputmode: 'email' });
    const password = h('input', { class: 'input', type: 'password', name: 'password', autocomplete: 'current-password', required: true, dir: 'ltr' });
    const submit = h('button', { class: 'btn btn--primary btn--block btn--lg', type: 'submit' }, 'دخول');
    const msg = h('div', { role: 'alert', 'aria-live': 'assertive' });
    const form = h('form', { class: 'form', novalidate: true }, field('البريد الإلكتروني', email), field('كلمة المرور', password), msg, submit);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.replaceChildren();
        setBusy(submit, true, 'جارٍ الدخول…');
        try {
            const data = await api('api/auth/login', { method: 'POST', body: { email: email.value.trim(), password: password.value } });
            ctx.setUser(data.user);
            ctx.navigate('/');
        } catch (err) {
            showFieldErrors(form, err);
            msg.append(banner(errorMessage(err), 'danger'));
            password.value = '';
            password.focus();
        } finally {
            setBusy(submit, false, 'دخول');
        }
    });

    root.append(h('section', { class: 'auth' },
        h('div', { class: 'auth__logo' }, h('img', { src: 'assets/img/icon.svg', alt: '' })),
        h('div', { class: 'card' },
            h('h1', { class: 'center' }, 'أهلًا بك من جديد'),
            h('p', { class: 'center muted' }, 'مدرّبك الشخصي لتعلّم الألمانية خطوة بخطوة.'),
            form),
        await keysCard(),
        h('p', { class: 'center small' }, h('a', { href: '#/privacy' }, 'سياسة الخصوصية ومعالجة الصوت'))));
    email.focus();
}
