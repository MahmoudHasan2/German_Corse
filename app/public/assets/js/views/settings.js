import { api, url } from '../api.js';
import { h } from '../dom.js';
import { errorMessage } from '../i18n.js';
import { applyTheme, badge, banner, field, meter, loading, setBusy, showFieldErrors, storage, toast, toastError } from '../ui.js';

export async function render(root, ctx) {
    root.append(loading());
    const u = await api('api/settings/usage');
    if (!ctx.isCurrent()) return;
    root.replaceChildren();
    const user = ctx.user;

    root.append(h('div', { class: 'page-head' }, h('h1', {}, 'الإعدادات')));

    // Profile
    const name = h('input', { class: 'input', name: 'display_name', value: user.display_name, maxlength: '80' });
    const goal = h('input', { class: 'input', name: 'daily_goal_minutes', type: 'number', min: '10', max: '300', step: '5', value: String(user.daily_goal_minutes), dir: 'ltr' });
    const level = h('select', { class: 'select', name: 'target_level' }, ...['A1', 'A2', 'B1'].map((l) => h('option', { value: l, selected: l === user.target_level }, l)));
    const saveBtn = h('button', { class: 'btn btn--primary', type: 'submit' }, 'حفظ');
    const profile = h('form', { class: 'form' }, field('الاسم', name), field('هدف الدراسة اليومي (دقائق)', goal, 'الخطة الافتراضية: 120 دقيقة يوميًا لمدة 90 يومًا — هدف دراسي وليس وعدًا بنتيجة.'), field('المستوى المستهدف', level), saveBtn);
    profile.addEventListener('submit', async (e) => {
        e.preventDefault();
        setBusy(saveBtn, true);
        try {
            const res = await api('api/settings/profile', { method: 'POST', body: { display_name: name.value.trim(), daily_goal_minutes: Number(goal.value), target_level: level.value } });
            ctx.setUser(res.user);
            toast('تم الحفظ.', 'success');
        } catch (err) {
            showFieldErrors(profile, err);
            toastError(err);
        } finally {
            setBusy(saveBtn, false);
        }
    });

    // Theme (per device)
    const current = storage('gac-theme') || 'auto';
    const theme = h('select', { class: 'select', name: 'theme' },
        h('option', { value: 'auto', selected: current === 'auto' }, 'تلقائي (حسب النظام)'),
        h('option', { value: 'light', selected: current === 'light' }, 'فاتح'),
        h('option', { value: 'dark', selected: current === 'dark' }, 'داكن'));
    theme.addEventListener('change', () => {
        storage('gac-theme', theme.value);
        applyTheme(theme.value);
    });

    // Usage
    const s = u.usage;
    const L = s.limits;
    const usageCard = h('section', { class: 'card stack' },
        h('div', { class: 'card__title' }, h('h2', {}, 'الاستخدام والتكلفة'), badge(s.ai_enabled ? 'الذكاء الاصطناعي مفعّل' : 'الذكاء الاصطناعي متوقف', s.ai_enabled ? 'success' : 'danger')),
        s.warning ? banner('اقتربت من أحد الحدود.', 'warning') : null,
        meter('طلبات اليوم', `${s.today_requests} / ${L.daily_requests}`, s.today_requests, L.daily_requests, s.ratios.requests >= 0.8 ? 'warning' : ''),
        meter('دقائق الصوت اليوم', `${(s.today_audio_seconds / 60).toFixed(1)} / ${L.daily_audio_minutes}`, s.today_audio_seconds, L.daily_audio_minutes * 60, s.ratios.audio >= 0.8 ? 'warning' : ''),
        meter('تكلفة اليوم (تقديرية)', `$${s.today_cost.toFixed(4)} / $${L.daily_budget_usd.toFixed(2)}`, s.today_cost, L.daily_budget_usd, s.ratios.daily_cost >= 0.8 ? 'warning' : ''),
        meter('تكلفة الشهر لكل الحسابات (تقديرية)', `$${s.month_cost.toFixed(4)} / $${L.monthly_budget_usd.toFixed(2)} (يتوقف عند ${Math.round(L.emergency_threshold * 100)}%)`, s.month_cost, L.monthly_budget_usd * L.emergency_threshold, s.ratios.monthly_cost >= 0.8 ? 'warning' : ''),
        h('div', { class: 'small muted' }, `اليوم: تقييم ${s.today_text_requests} · تحويل صوت ${s.today_stt_requests} · توليد صوت ${s.today_tts_requests}. الحدّ الأقصى للتسجيل: ${L.max_recording_seconds} ثانية.`),
        h('div', { class: 'xsmall muted', dir: 'ltr', lang: 'en' }, `Provider: ${s.provider} · tutor: ${u.models.tutor} · STT: ${u.models.transcription} · TTS: ${u.models.speech} · pricing as of ${u.pricing_as_of}`),
        h('p', { class: 'small', style: { margin: 0 } }, u.note_ar));
    if (u.is_admin) {
        const toggle = h('button', { class: `btn ${s.ai_enabled ? 'btn--danger' : 'btn--success'}`, type: 'button' }, s.ai_enabled ? 'إيقاف كل طلبات الذكاء الاصطناعي' : 'تشغيل الذكاء الاصطناعي');
        toggle.addEventListener('click', async () => {
            try {
                await api('api/admin/ai-switch', { method: 'POST', body: { enabled: !s.ai_enabled } });
                ctx.navigate('/settings');
            } catch (err) {
                toastError(err);
            }
        });
        usageCard.append(h('div', { class: 'row' }, toggle, u.emergency_shutdown_at ? badge(`إيقاف طارئ منذ ${u.emergency_shutdown_at} UTC`, 'danger') : null));
    }

    // Password
    const cur = h('input', { class: 'input', type: 'password', name: 'current_password', autocomplete: 'current-password', dir: 'ltr' });
    const neu = h('input', { class: 'input', type: 'password', name: 'new_password', autocomplete: 'new-password', dir: 'ltr', minlength: '10' });
    const pwBtn = h('button', { class: 'btn btn--secondary', type: 'submit' }, 'تغيير كلمة المرور');
    const pwForm = h('form', { class: 'form' }, field('كلمة المرور الحالية', cur), field('كلمة المرور الجديدة', neu, '10 أحرف على الأقل.'), pwBtn);
    pwForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        setBusy(pwBtn, true);
        try {
            await api('api/settings/password', { method: 'POST', body: { current_password: cur.value, new_password: neu.value } });
            cur.value = '';
            neu.value = '';
            toast('تم تغيير كلمة المرور.', 'success');
        } catch (err) {
            showFieldErrors(pwForm, err);
            toastError(err);
        } finally {
            setBusy(pwBtn, false);
        }
    });

    // Data
    const delPw = h('input', { class: 'input', type: 'password', name: 'password', autocomplete: 'current-password', dir: 'ltr' });
    const delConfirm = h('input', { class: 'input', name: 'confirm', dir: 'ltr', placeholder: 'DELETE', autocomplete: 'off' });
    const delBtn = h('button', { class: 'btn btn--danger', type: 'submit' }, 'حذف حسابي وكل بياناتي نهائيًا');
    const delMsg = h('div', { 'aria-live': 'assertive' });
    const delForm = h('form', { class: 'form' }, field('كلمة المرور', delPw), field('اكتب DELETE للتأكيد', delConfirm), delMsg, delBtn);
    delForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!window.confirm('لا يمكن التراجع عن الحذف. هل أنت متأكد؟')) return;
        delMsg.replaceChildren();
        setBusy(delBtn, true);
        try {
            await api('api/account/delete', { method: 'POST', body: { password: delPw.value, confirm: delConfirm.value.trim() } });
            ctx.setUser(null);
            ctx.navigate('/login');
        } catch (err) {
            showFieldErrors(delForm, err);
            delMsg.append(banner(errorMessage(err), 'danger'));
        } finally {
            setBusy(delBtn, false);
        }
    });

    root.append(h('div', { class: 'grid grid--2' },
        h('section', { class: 'card' }, h('h2', {}, 'الملف الشخصي'), profile),
        h('section', { class: 'card stack' }, h('h2', {}, 'المظهر'), field('السمة', theme), h('p', { class: 'xsmall muted' }, 'يُحفظ هذا الإعداد على هذا الجهاز فقط.'))),
    h('div', { style: { 'margin-top': '1rem' } }, usageCard),
    h('div', { class: 'grid grid--2', style: { 'margin-top': '1rem' } },
        h('section', { class: 'card' }, h('h2', {}, 'الأمان'), pwForm),
        h('section', { class: 'card stack' },
            h('h2', {}, 'بياناتك'),
            h('p', { class: 'small' }, 'نزّل نسخة كاملة من بياناتك بصيغة JSON، أو احذف حسابك. التسجيلات الصوتية لا تُحفظ أصلًا.'),
            h('a', { class: 'btn btn--secondary', href: url('api/account/export'), download: 'german-coach-export.json' }, 'تنزيل بياناتي (JSON)'),
            h('a', { href: '#/privacy', class: 'small' }, 'سياسة الخصوصية ومعالجة الصوت'),
            h('hr', {}),
            delForm)));
}
