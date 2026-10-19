import { api } from '../api.js';
import { h, de } from '../dom.js';
import { SKILLS } from '../i18n.js';
import { badge, emptyState, loading, relativeDue, toastError } from '../ui.js';
import { startReview } from './dashboard.js';

const SOURCES = { mastered: 'بعد الإتقان', skipped: 'تم تخطّيه', deferred_to_review: 'صعب — نُقل للمراجعة', needs_review: 'نجح بمساعدة', review: 'مراجعة سابقة' };

export async function render(root, ctx) {
    root.append(loading());
    const d = await api('api/reviews');
    if (!ctx.isCurrent()) return;
    root.replaceChildren();

    const start = h('button', { class: 'btn btn--primary btn--lg', type: 'button', disabled: d.due.exercises === 0 }, `ابدأ مراجعة (${d.due.exercises})`);
    start.addEventListener('click', async () => {
        start.disabled = true;
        try {
            await startReview(ctx.navigate);
        } catch (err) {
            toastError(err);
            start.disabled = false;
        }
    });

    root.append(h('div', { class: 'page-head' },
        h('div', {}, h('h1', {}, 'المراجعة'), h('p', { class: 'muted', style: { margin: 0 } }, 'التكرار المتباعد: كل إجابة صحيحة تُطيل المدة حتى المراجعة التالية، وكل خطأ أو مساعدة تُقصّرها.')),
        start));

    root.append(h('div', { class: 'grid grid--2' },
        h('section', { class: 'card' }, h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(d.due.exercises)), h('span', { class: 'stat__label' }, 'تمارين مستحقة الآن'))),
        h('section', { class: 'card' }, h('div', { class: 'row row--between' },
            h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(d.due.vocabulary)), h('span', { class: 'stat__label' }, 'بطاقات مفردات مستحقة')),
            h('a', { class: 'btn btn--secondary', href: '#/vocabulary' }, 'البطاقات')))));

    const list = h('ol', { class: 'list' });
    for (const r of d.queue) {
        list.append(h('li', { class: 'row row--between' },
            h('div', { class: 'stack-sm' },
                de(r.prompt_de, 'div'),
                h('div', { class: 'row xsmall muted' }, badge(r.level, 'level'), r.lesson_title_ar, badge(SKILLS[r.skill_area] || r.skill_area), SOURCES[r.source] ? badge(SOURCES[r.source]) : null),
                r.last_reason ? h('div', { class: 'xsmall muted', lang: 'en', dir: 'ltr' }, r.last_reason) : null),
            badge(r.is_due ? 'مستحق الآن' : relativeDue(r.due_at), r.is_due ? 'warning' : '')));
    }
    root.append(h('section', { class: 'card', style: { 'margin-top': '1rem' } },
        h('h2', {}, 'جدول المراجعة'),
        d.queue.length ? list : emptyState('لا يوجد شيء في جدول المراجعة بعد. التمارين المتقنة أو المتخطّاة تظهر هنا تلقائيًا.')));
}
