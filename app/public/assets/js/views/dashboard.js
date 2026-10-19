import { api } from '../api.js';
import { h, de } from '../dom.js';
import { icon } from '../icons.js';
import { badge, banner, loading, meter, toastError } from '../ui.js';

export async function startAssessment(kind, navigate) {
    const data = await api('api/assessments/start', { method: 'POST', body: { kind } });
    navigate(`/session/${data.session.id}`);
}

export async function startReview(navigate) {
    const data = await api('api/reviews/start', { method: 'POST' });
    navigate(`/session/${data.session.id}`);
}

export async function startLesson(lessonId, navigate) {
    const data = await api('api/lessons/start', { method: 'POST', body: { lesson_id: lessonId } });
    navigate(`/session/${data.session.id}`);
}

export async function render(root, ctx) {
    root.append(loading());
    const d = await api('api/dashboard');
    if (!ctx.isCurrent()) return;
    root.replaceChildren();

    const act = (fn) => async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
            await fn();
        } catch (err) {
            toastError(err);
            btn.disabled = false;
        }
    };

    const actionFor = (s) => {
        switch (s.type) {
            case 'diagnostic': return { label: 'ابدأ الاختبار', onClick: act(() => startAssessment('diagnostic', ctx.navigate)) };
            case 'weekly': return { label: 'ابدأ التقييم', onClick: act(() => startAssessment('weekly', ctx.navigate)) };
            case 'review': return { label: 'ابدأ المراجعة', onClick: act(() => startReview(ctx.navigate)) };
            case 'resume': return { label: 'تابع', onClick: () => ctx.navigate(`/session/${s.session_id}`) };
            case 'lesson': return { label: 'ابدأ الدرس', onClick: act(() => startLesson(s.lesson_id, ctx.navigate)) };
            case 'vocabulary': return { label: 'افتح البطاقات', onClick: () => ctx.navigate('/vocabulary') };
            default: return null;
        }
    };

    const t = d.today;
    const goalReached = t.active_minutes >= t.goal_minutes && t.goal_minutes > 0;
    const header = h('div', { class: 'page-head' },
        h('div', {},
            h('h1', {}, `مرحبًا، ${d.user.display_name}`),
            h('p', { class: 'muted' }, `اليوم ${d.study_target.day} من خطة ${d.study_target.target_days} يومًا`,
                d.user.start_level ? ' · ' : '', d.user.start_level ? badge(`بداية: ${d.user.start_level}`, 'level') : null)),
        h('div', { class: 'row' }, badge(`الهدف: ${d.user.target_level}`, 'level')));

    const notices = [];
    if (d.usage.demo_provider) notices.push(banner('وضع تجريبي: المقيّم الحالي تجريبي ولا يستخدم ذكاءً اصطناعيًا حقيقيًا. لا تعتمد على تقييماته للتعلّم.', 'warning'));
    if (!d.usage.ai_enabled || !d.usage.provider_configured) notices.push(banner('ميزات الذكاء الاصطناعي متوقفة أو غير مهيّأة. تبقى المراجعة والمفردات والإجابات المطابقة للنموذج متاحة.', 'warning'));
    else if (d.usage.warning) notices.push(banner('اقتربت من حدّ الاستخدام اليومي أو الشهري. راجع صفحة الاستخدام في الإعدادات.', 'warning'));

    const todayCard = h('section', { class: 'card', 'aria-labelledby': 'today-h' },
        h('div', { class: 'card__title' }, h('h2', { id: 'today-h' }, 'اليوم'), goalReached ? badge('تحقّق الهدف', 'success') : null),
        h('div', { class: 'grid grid--3' },
            h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(t.active_minutes)), h('span', { class: 'stat__label' }, `دقيقة من ${t.goal_minutes}`)),
            h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(t.streak_days)), h('span', { class: 'stat__label' }, 'أيام متتالية')),
            h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(d.reviews_due.exercises + d.reviews_due.vocabulary)), h('span', { class: 'stat__label' }, 'مراجعات مستحقة'))),
        h('div', { class: 'stack-sm', style: { 'margin-top': '1rem' } },
            meter('وقت الدراسة اليوم', `${t.active_minutes} / ${t.goal_minutes} دقيقة`, t.active_minutes, t.goal_minutes, goalReached ? 'success' : '')),
        h('p', { class: 'xsmall muted', style: { 'margin-top': '.75rem' } }, 'يُحسب وقت الدراسة من تفاعلك الفعلي (حدّ أقصى 3 دقائق بين خطوتين).'));

    const planList = h('ol', { class: 'list' });
    for (const p of d.plan) {
        let action = null;
        if (p.block === 'review') action = h('button', { class: 'btn btn--secondary btn--sm', type: 'button', onClick: act(() => startReview(ctx.navigate)) }, 'ابدأ');
        if (p.block === 'lesson' && p.lesson_id) action = h('button', { class: 'btn btn--secondary btn--sm', type: 'button', onClick: act(() => startLesson(p.lesson_id, ctx.navigate)) }, 'ابدأ');
        if (p.block === 'vocabulary') action = h('a', { class: 'btn btn--secondary btn--sm', href: '#/vocabulary' }, 'افتح');
        if (p.block === 'speaking') action = h('span', { class: 'xsmall muted' }, 'استخدم زر الميكروفون في التمارين');
        planList.append(h('li', { class: 'row row--between' }, h('div', {}, h('strong', {}, p.title_ar), h('div', { class: 'xsmall muted' }, `≈ ${p.minutes} دقيقة`)), action));
    }
    const planCard = h('section', { class: 'card', 'aria-labelledby': 'plan-h' },
        h('div', { class: 'card__title' }, h('h2', { id: 'plan-h' }, 'خطة اليوم'), badge(`${t.goal_minutes} دقيقة`)),
        planList);

    const sugg = h('ul', { class: 'list' });
    for (const s of d.suggestions) {
        const a = actionFor(s);
        sugg.append(h('li', { class: 'row row--between' },
            h('div', {}, h('strong', {}, s.title_ar), h('div', { class: 'small muted' }, s.reason_ar)),
            a ? h('button', { class: 'btn btn--primary btn--sm', type: 'button', onClick: a.onClick }, a.label) : null));
    }
    const nextCard = h('section', { class: 'card', 'aria-labelledby': 'next-h' },
        h('h2', { id: 'next-h' }, 'الخطوة التالية المقترحة'),
        d.suggestions.length ? sugg : h('p', { class: 'muted' }, 'لا توجد اقتراحات الآن. افتح قائمة الدروس.'));

    const lessonsCard = h('section', { class: 'card', 'aria-labelledby': 'les-h' },
        h('div', { class: 'card__title' }, h('h2', { id: 'les-h' }, 'المنهج'), h('a', { href: '#/lessons', class: 'small' }, 'كل الدروس')),
        meter('دروس مكتملة', `${d.lessons.completed} / ${d.lessons.total}`, d.lessons.completed, d.lessons.total, 'success'),
        d.lessons.next ? h('div', { class: 'stack-sm', style: { 'margin-top': '1rem' } },
            h('div', { class: 'small muted' }, 'التالي'),
            h('div', { class: 'row' }, badge(d.lessons.next.level, 'level'), de(d.lessons.next.title_de, 'strong')),
            h('div', { class: 'small' }, d.lessons.next.title_ar)) : null);

    const targetCard = h('section', { class: 'card card--info card--flat' },
        h('p', { class: 'small', style: { margin: 0 } }, icon('alert'), ' ', d.study_target.note_ar));

    root.append(header, ...notices.map((n) => h('div', { style: { 'margin-bottom': '1rem' } }, n)),
        h('div', { class: 'grid grid--2' }, todayCard, nextCard, planCard, lessonsCard),
        h('div', { style: { 'margin-top': '1rem' } }, targetCard));
}
