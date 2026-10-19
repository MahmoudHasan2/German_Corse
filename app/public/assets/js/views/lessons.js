import { api } from '../api.js';
import { h, de } from '../dom.js';
import { icon } from '../icons.js';
import { LESSON_STATUS, SKILLS } from '../i18n.js';
import { badge, loading, meter, toastError } from '../ui.js';
import { startLesson, startAssessment } from './dashboard.js';

const LEVEL_TITLES = { A1: 'A1 — الأساسيات', A2: 'A2 — التواصل اليومي', B1: 'B1 — المحادثة العملية والاستعداد للامتحان' };
const STATUS_VARIANT = { completed: 'success', in_progress: 'info', available: '', locked: '' };

export async function render(root, ctx) {
    root.append(loading());
    const data = await api('api/lessons');
    if (!ctx.isCurrent()) return;
    root.replaceChildren();
    const byId = new Map(data.lessons.map((l) => [l.id, l]));

    root.append(h('div', { class: 'page-head' },
        h('div', {}, h('h1', {}, 'الدروس'), h('p', { class: 'muted' }, 'تُفتح الدروس بالترتيب. الدرس يكتمل عندما تتقن معظم تمارينه وتعمل على جميعها.')),
        h('button', { class: 'btn btn--secondary', type: 'button', onClick: async (e) => {
            e.currentTarget.disabled = true;
            try { await startAssessment('diagnostic', ctx.navigate); } catch (err) { toastError(err); e.currentTarget.disabled = false; }
        } }, 'اختبار تحديد المستوى')));

    for (const level of ['A1', 'A2', 'B1']) {
        const items = data.lessons.filter((l) => l.level === level);
        if (!items.length) continue;
        const grid = h('div', { class: 'grid grid--3' });
        for (const l of items) {
            const locked = l.status === 'locked';
            const requires = l.requires.map((id) => byId.get(id)).filter(Boolean);
            const startBtn = h('button', { class: 'btn btn--primary btn--sm', type: 'button', disabled: locked }, l.status === 'in_progress' ? 'تابع' : (l.status === 'completed' ? 'تدرّب مجددًا' : 'ابدأ'));
            startBtn.addEventListener('click', async () => {
                startBtn.disabled = true;
                try {
                    await startLesson(l.id, ctx.navigate);
                } catch (err) {
                    toastError(err);
                    startBtn.disabled = false;
                }
            });
            grid.append(h('article', { class: `card card--tight lesson-card ${locked ? 'lesson-card--locked' : ''}` },
                h('div', { class: 'row row--between' }, badge(l.level, 'level'), badge(LESSON_STATUS[l.status], STATUS_VARIANT[l.status]),),
                h('h3', { class: 'lesson-card__title' }, de(l.title_de)),
                h('div', {}, l.title_ar),
                h('p', { class: 'small muted' }, l.objective_ar),
                h('div', { class: 'row xsmall muted' }, ...l.skills.map((s) => badge(SKILLS[s] || s)), `≈ ${l.estimated_minutes} د`),
                meter('متقن', `${l.exercises_mastered} / ${l.exercises_total}`, l.exercises_mastered, l.exercises_total, 'success'),
                l.exercises_pending_review > 0 ? h('div', { class: 'xsmall muted' }, `${l.exercises_pending_review} تمارين تنتظر المراجعة`) : null,
                locked && requires.length ? h('div', { class: 'xsmall muted row' }, icon('lock'), 'يتطلّب: ', requires.map((r) => r.title_ar).join('، ')) : null,
                h('div', { class: 'row', style: { 'margin-top': 'auto' } },
                    h('a', { class: 'btn btn--ghost btn--sm', href: `#/lessons/${l.id}` }, 'التفاصيل'),
                    startBtn)));
        }
        root.append(h('section', { class: 'level-group', 'aria-label': LEVEL_TITLES[level] }, h('h2', {}, LEVEL_TITLES[level]), grid));
    }
}
