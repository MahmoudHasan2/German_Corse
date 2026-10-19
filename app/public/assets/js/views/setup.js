import { api } from '../api.js';
import { h } from '../dom.js';
import { errorMessage } from '../i18n.js';
import { banner, field, setBusy, showFieldErrors } from '../ui.js';
import { keysCard } from './keys.js';

export async function render(root, ctx) {
    let status = null;
    try {
        status = await api('api/setup/status');
    } catch {
        status = null;
    }
    if (status && status.account_exists) {
        ctx.state.setupRequired = false;
        ctx.navigate('/login');
        return;
    }

    const token = h('input', { class: 'input mono', type: 'password', name: 'token', required: true, dir: 'ltr', autocomplete: 'off' });
    const name = h('input', { class: 'input', type: 'text', name: 'display_name', required: true, maxlength: '80', autocomplete: 'name' });
    const email = h('input', { class: 'input', type: 'email', name: 'email', required: true, dir: 'ltr', autocomplete: 'username' });
    const password = h('input', { class: 'input', type: 'password', name: 'password', required: true, dir: 'ltr', minlength: '10', autocomplete: 'new-password' });
    const submit = h('button', { class: 'btn btn--primary btn--block btn--lg', type: 'submit' }, 'إنشاء الحساب وتجهيز قاعدة البيانات');
    const msg = h('div', { 'aria-live': 'assertive' });
    const tokenRequired = !status || status.token_required !== false;
    const form = h('form', { class: 'form', novalidate: true },
        tokenRequired ? field('رمز الإعداد (SETUP_TOKEN)', token, 'موجود في ملف ‎.env‎ على الخادم. لا يظهر لأحد غيرك.') : null,
        field('اسمك', name),
        field('البريد الإلكتروني', email),
        field('كلمة المرور', password, '10 أحرف على الأقل.'),
        msg, submit);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.replaceChildren();
        setBusy(submit, true, 'جارٍ الإعداد…');
        try {
            const res = await api('api/setup/run', { method: 'POST', body: { token: token.value, display_name: name.value.trim(), email: email.value.trim(), password: password.value } });
            ctx.state.setupRequired = false;
            const session = await api('api/auth/session');
            ctx.setUser(session.user);
            msg.append(banner(`تم الإعداد: ${res.migrations_applied.length} ترحيلات، ${res.curriculum.lessons} دروس.`, 'info'));
            ctx.navigate('/');
        } catch (err) {
            showFieldErrors(form, err);
            msg.append(banner(errorMessage(err), 'danger'));
        } finally {
            setBusy(submit, false, 'إنشاء الحساب وتجهيز قاعدة البيانات');
        }
    });

    const notices = [];
    if (status && !status.setup_enabled) {
        notices.push(banner('الإعداد عبر المتصفح معطّل: أضف SETUP_TOKEN (‏24 حرفًا على الأقل) إلى ملف ‎.env‎ ثم أعد تحميل الصفحة.', 'warning'));
    }
    if (status && !status.database_reachable) {
        notices.push(banner('تعذّر الاتصال بقاعدة البيانات. تحقّق من إعدادات DB_* في ملف ‎.env‎.', 'danger'));
    }

    root.append(h('section', { class: 'auth' },
        h('div', { class: 'auth__logo' }, h('img', { src: 'assets/img/icon.svg', alt: '' })),
        h('div', { class: 'card stack' },
            h('h1', { class: 'center' }, 'الإعداد الأولي'),
            h('p', { class: 'muted' }, 'تُنفّذ هذه الخطوة مرة واحدة: تُنشأ جداول قاعدة البيانات، ويُستورد المنهج، ويُنشأ حسابك. بعد إنشاء الحساب لا يمكن إنشاء حساب آخر من هنا.'),
            ...notices,
            form),
        await keysCard()));
}
