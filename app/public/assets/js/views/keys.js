import { api } from '../api.js';
import { h } from '../dom.js';
import { errorMessage } from '../i18n.js';
import { banner, field, setBusy, showFieldErrors } from '../ui.js';

/**
 * Portable desktop copy only: a card on the login/setup page where the learner pastes
 * their own free Groq / Gemini keys. Renders nothing when the server is not in LOCAL_MODE.
 */
export async function keysCard() {
    let status;
    try {
        status = await api('api/local/keys');
    } catch {
        return null;
    }
    if (!status.enabled) return null;

    const keyInput = (name, placeholder) => h('input', { class: 'input mono', type: 'password', name, dir: 'ltr', autocomplete: 'off', spellcheck: 'false', placeholder });
    const groq = keyInput('groq_api_key', status.groq ? '•••••• (محفوظ)' : 'gsk_...');
    const gemini = keyInput('gemini_api_key', status.gemini ? '•••••• (محفوظ)' : 'AIza...');
    const submit = h('button', { class: 'btn btn--primary btn--block', type: 'submit' }, 'حفظ المفاتيح');
    const msg = h('div', { 'aria-live': 'polite' });
    const state = h('p', { class: 'small' });
    const showState = (s) => {
        state.textContent = s.groq
            ? `✅ مفتاح Groq محفوظ — التصحيح عبر ${s.tutor_provider === 'gemini' ? 'Gemini' : 'Groq'}، والتعرّف على الصوت يعمل.`
            : (s.gemini ? '⚠️ مفتاح Gemini وحده محفوظ: التصحيح يعمل، لكن التعرّف على الصوت يحتاج مفتاح Groq.' : '⚠️ لا يوجد مفتاح بعد: الذكاء الاصطناعي لن يعمل حتى تضيف مفتاح Groq.');
    };
    showState(status);

    const form = h('form', { class: 'form', novalidate: true },
        field('مفتاح Groq (يبدأ بـ gsk_)', groq, 'مجاني من console.groq.com — للتصحيح وللتعرّف على الصوت.'),
        field('مفتاح Gemini (اختياري، يبدأ بـ AIza)', gemini, 'مجاني من aistudio.google.com — يُستعمل للتصحيح إذا لم يوجد مفتاح Groq.'),
        msg, submit);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.replaceChildren();
        setBusy(submit, true, 'جارٍ الحفظ…');
        try {
            const res = await api('api/local/keys', { method: 'POST', body: { groq_api_key: groq.value.trim(), gemini_api_key: gemini.value.trim() } });
            if (groq.value.trim()) groq.placeholder = '•••••• (محفوظ)';
            if (gemini.value.trim()) {
                gemini.placeholder = '•••••• (محفوظ)';
                status.gemini = true;
            }
            groq.value = '';
            gemini.value = '';
            showState({ ...res, gemini: status.gemini });
            msg.append(banner('تم حفظ المفاتيح على هذا الجهاز.', 'info'));
        } catch (err) {
            showFieldErrors(form, err);
            msg.append(banner(errorMessage(err), 'danger'));
        } finally {
            setBusy(submit, false, 'حفظ المفاتيح');
        }
    });

    return h('details', { class: 'card stack', open: !status.groq && !status.gemini },
        h('summary', { class: 'keys__summary' }, '🔑 مفاتيح الذكاء الاصطناعي (Groq / Gemini)'),
        h('p', { class: 'muted small' }, 'تُحفظ المفاتيح على هذا الجهاز فقط ولا تُعرض مرة أخرى. اترك الحقل فارغًا لإبقاء المفتاح المحفوظ.'),
        state,
        form);
}
