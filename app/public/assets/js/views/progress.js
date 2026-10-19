import { api } from '../api.js';
import { h, de } from '../dom.js';
import { SKILLS, VERDICTS } from '../i18n.js';
import { badge, emptyState, formatDate, formatDateTime, loading, meter, toastError } from '../ui.js';
import { startAssessment } from './dashboard.js';

export async function render(root, ctx) {
    root.append(loading());
    const p = await api('api/progress');
    if (!ctx.isCurrent()) return;
    root.replaceChildren();

    const assess = (kind, label) => {
        const b = h('button', { class: 'btn btn--secondary', type: 'button' }, label);
        b.addEventListener('click', async () => {
            b.disabled = true;
            try { await startAssessment(kind, ctx.navigate); } catch (err) { toastError(err); b.disabled = false; }
        });
        return b;
    };

    root.append(h('div', { class: 'page-head' },
        h('div', {}, h('h1', {}, 'التقدّم'), h('p', { class: 'muted', style: { margin: 0 } }, 'كل الأرقام هنا محسوبة من محاولاتك المحفوظة فعليًا.')),
        h('div', { class: 'row' }, assess('weekly', 'التقييم الأسبوعي'), assess('diagnostic', 'اختبار تحديد المستوى'))));

    root.append(h('div', { class: 'grid grid--4' },
        stat(p.totals.active_minutes, 'دقائق دراسة إجمالًا'),
        stat(p.totals.attempts, 'محاولات'),
        stat(p.totals.mastered, 'تمارين متقنة'),
        stat(`${p.lessons.completed}/${p.lessons.total}`, 'دروس مكتملة')));

    // Skills
    const skills = h('div', { class: 'stack' });
    for (const [key, s] of Object.entries(p.skills)) {
        const acc = s.first_attempt_accuracy === null ? 'لا بيانات بعد (آخر 30 يومًا)' : `دقة المحاولة الأولى: ${Math.round(s.first_attempt_accuracy * 100)}% من ${s.first_attempts}`;
        skills.append(h('div', { class: 'stack-sm' },
            meter(SKILLS[key] || key, `${s.exercises_mastered} / ${s.exercises_total} متقن`, s.exercises_mastered, s.exercises_total, 'success'),
            h('div', { class: 'xsmall muted' }, acc,
                key === 'speaking' && s.voice_answers ? ` · إجابات صوتية: ${s.voice_successes}/${s.voice_answers} ناجحة` : '')));
    }

    // Daily activity (real minutes, last 14 days)
    const days = [];
    const byDate = new Map(p.daily.map((d) => [d.date, d]));
    for (let i = 13; i >= 0; i--) {
        const d = new Date(Date.now() - i * 86400000);
        const key = d.toISOString().slice(0, 10);
        days.push({ key, label: `${d.getUTCDate()}.${d.getUTCMonth() + 1}`, v: byDate.get(key)?.active_minutes ?? 0 });
    }
    const goal = p.study_target.daily_goal_minutes;
    const max = Math.max(goal, ...days.map((d) => d.v), 1);
    const hasActivity = days.some((d) => d.v > 0);
    const chart = hasActivity
        ? h('div', { class: 'bars', role: 'img', 'aria-label': `دقائق الدراسة في آخر 14 يومًا: ${days.map((d) => `${d.label}: ${d.v}`).join('، ')}` },
            ...days.map((d) => h('div', { class: 'bars__col' },
                h('span', { class: 'xsmall muted' }, d.v ? String(d.v) : ''),
                h('div', { class: `bars__bar ${d.v >= goal ? 'bars__bar--goal' : ''}`, style: { height: `${Math.round((d.v / max) * 100)}%` } }),
                h('span', { class: 'bars__label' }, d.label))))
        : emptyState('لا يوجد نشاط مسجّل في آخر 14 يومًا.');

    root.append(h('div', { class: 'grid grid--2', style: { 'margin-top': '1rem' } },
        h('section', { class: 'card' }, h('h2', {}, 'المهارات'), skills),
        h('section', { class: 'card' }, h('div', { class: 'card__title' }, h('h2', {}, 'وقت الدراسة (14 يومًا)'), badge(`الهدف ${goal} د`)), chart,
            h('p', { class: 'xsmall muted' }, p.study_target.note_ar))));

    // Weak points
    const weak = p.weakest_categories.length
        ? h('ul', { class: 'list' }, ...p.weakest_categories.map((w) => h('li', { class: 'stack-sm' },
            h('div', { class: 'row row--between' }, h('strong', {}, w.label_ar), badge(`${w.occurrences}×`, 'danger')),
            w.example_original ? h('div', { class: 'small', lang: 'de', dir: 'ltr' }, h('span', { class: 'strike' }, w.example_original), ' → ', h('span', { class: 'fixword' }, w.example_correction || '')) : null,
            w.explanation_ar ? h('div', { class: 'xsmall muted' }, w.explanation_ar) : null)))
        : emptyState('لم تُسجَّل أخطاء متكررة بعد.');
    const vocab = p.vocabulary_mistakes.length
        ? h('ul', { class: 'list' }, ...p.vocabulary_mistakes.map((v) => h('li', {},
            h('div', { class: 'small', lang: 'de', dir: 'ltr' }, h('span', { class: 'strike' }, v.original), ' → ', h('span', { class: 'fixword' }, v.correction)),
            h('div', { class: 'xsmall muted' }, `${v.label_ar} · ${formatDate(v.created_at)}`))))
        : emptyState('لا توجد أخطاء مفردات مسجّلة.');
    root.append(h('div', { class: 'grid grid--2', style: { 'margin-top': '1rem' } },
        h('section', { class: 'card' }, h('h2', {}, 'أضعف نقاط القواعد'), weak),
        h('section', { class: 'card' }, h('h2', {}, 'أخطاء المفردات الشائعة'), vocab)));

    const attempts = (rows, empty) => rows.length
        ? h('ul', { class: 'list' }, ...rows.map((r) => h('li', { class: 'stack-sm' },
            de(r.prompt_de, 'div', 'xsmall muted'), de(r.answer_text, 'div'),
            h('div', { class: 'xsmall muted' }, `${VERDICTS[r.verdict] || r.verdict} · ${r.input_mode === 'audio' ? 'صوت' : 'كتابة'} · ${formatDateTime(r.created_at)}`))))
        : emptyState(empty);
    root.append(h('div', { class: 'grid grid--2', style: { 'margin-top': '1rem' } },
        h('section', { class: 'card' }, h('h2', {}, 'آخر النجاحات'), attempts(p.recent_successes, 'لا توجد بعد.')),
        h('section', { class: 'card' }, h('h2', {}, 'آخر المحاولات غير الناجحة'), attempts(p.recent_failures, 'لا توجد بعد.'))));

    const history = p.assessments.length
        ? h('div', { class: 'table-wrap' }, h('table', { class: 'table' },
            h('thead', {}, h('tr', {}, h('th', {}, 'التاريخ'), h('th', {}, 'النوع'), h('th', {}, 'النتيجة'), h('th', {}, 'تقدير'))),
            h('tbody', {}, ...p.assessments.map((a) => h('tr', {},
                h('td', {}, formatDate(a.started_at)),
                h('td', {}, a.kind === 'diagnostic' ? 'تحديد المستوى' : 'أسبوعي'),
                h('td', {}, a.status === 'completed' && a.results ? `${a.results.correct}/${a.results.answered}` : (a.status === 'in_progress' ? 'قيد التنفيذ' : 'متروك')),
                h('td', {}, a.estimated_level ? badge(a.estimated_level, 'level') : '—'))))))
        : emptyState('لا توجد تقييمات بعد.');
    root.append(h('section', { class: 'card', style: { 'margin-top': '1rem' } },
        h('h2', {}, 'سجل التقييمات'), history,
        h('p', { class: 'xsmall muted' }, 'هذه تقديرات داخلية غير رسمية وليست شهادة CEFR معتمدة.')));
}

function stat(value, label) {
    return h('section', { class: 'card card--tight' }, h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(value)), h('span', { class: 'stat__label' }, label)));
}
