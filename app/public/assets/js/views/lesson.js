import { api } from '../api.js';
import { audioPlayer } from '../audio.js';
import { h, de } from '../dom.js';
import { EX_STATUS, LESSON_STATUS, SKILLS, TYPES } from '../i18n.js';
import { badge, loading, meter, toastError } from '../ui.js';
import { startLesson } from './dashboard.js';

export async function render(root, ctx) {
    root.append(loading());
    const d = await api(`api/lessons/${ctx.params.id}`);
    if (!ctx.isCurrent()) return;
    root.replaceChildren();
    const l = d.lesson;
    const locked = l.status === 'locked';

    const start = h('button', { class: 'btn btn--primary btn--lg', type: 'button', disabled: locked }, l.status === 'in_progress' ? 'تابع التمرين' : 'ابدأ التمرين');
    start.addEventListener('click', async () => {
        start.disabled = true;
        try {
            await startLesson(l.id, ctx.navigate);
        } catch (err) {
            toastError(err);
            start.disabled = false;
        }
    });

    const head = h('div', { class: 'page-head' },
        h('div', {},
            h('div', { class: 'row' }, badge(l.level, 'level'), badge(LESSON_STATUS[l.status])),
            h('h1', {}, de(l.title_de)),
            h('p', { class: 'muted', style: { margin: 0 } }, l.title_ar)),
        start);

    const about = h('section', { class: 'card stack' },
        h('h2', {}, 'الهدف'),
        h('p', {}, l.objective_ar),
        d.description_ar ? h('p', { class: 'muted' }, d.description_ar) : null,
        meter('تمارين متقنة', `${l.exercises_mastered} / ${l.exercises_total}`, l.exercises_mastered, l.exercises_total, 'success'));

    const grammar = d.grammar_notes.length ? h('section', { class: 'card stack' },
        h('h2', {}, 'القواعد'),
        ...d.grammar_notes.map((n) => h('div', { class: 'stack-sm' },
            h('h3', {}, n.title_ar),
            h('p', {}, n.body_ar),
            h('ul', {}, ...(n.examples_de || []).map((ex) => h('li', {}, de(ex))))))) : null;

    const dialogue = d.dialogue.length ? h('section', { class: 'card stack' },
        h('h2', {}, 'حوار نموذجي'),
        h('ol', { class: 'list' }, ...d.dialogue.map((line, i) => h('li', { class: 'stack-sm' },
            h('div', { class: 'row row--between' }, h('strong', { class: 'small' }, de(line.speaker)),
                audioPlayer(`api/lessons/${l.id}/dialogue/${i}/audio`, { label: 'استمع', text: line.de })),
            de(line.de, 'div', 'de--block'),
            h('div', { class: 'small muted' }, line.ar))))) : null;

    const vocab = d.vocabulary.length ? h('section', { class: 'card' },
        h('div', { class: 'card__title' }, h('h2', {}, 'المفردات'), h('a', { href: '#/vocabulary', class: 'small' }, 'تدرّب بالبطاقات')),
        h('div', { class: 'table-wrap' }, h('table', { class: 'table' },
            h('thead', {}, h('tr', {}, h('th', {}, 'الكلمة'), h('th', {}, 'المعنى'), h('th', {}, 'مثال'))),
            h('tbody', {}, ...d.vocabulary.map((v) => h('tr', {},
                h('td', {}, de(`${v.article ? `${v.article} ` : ''}${v.word_de}`, 'strong'), v.plural ? h('div', { class: 'xsmall muted' }, 'الجمع: ', de(`die ${v.plural}`)) : null),
                h('td', {}, v.word_ar),
                h('td', {}, v.example_de ? de(v.example_de) : ''))))))) : null;

    const exercises = h('section', { class: 'card' },
        h('h2', {}, 'التمارين'),
        h('ol', { class: 'list' }, ...d.exercises.map((e) => h('li', { class: 'row row--between' },
            h('div', {}, h('div', {}, e.instruction_ar), h('div', { class: 'row xsmall muted' }, badge(TYPES[e.type] || e.type), badge(SKILLS[e.skill_area] || e.skill_area))),
            badge(EX_STATUS[e.status] || e.status, e.status === 'provisionally_mastered' ? 'success' : (e.status === 'needs_review' || e.status === 'skipped' ? 'warning' : ''))))),
        h('p', { class: 'xsmall muted' }, 'الإجابات النموذجية لا تظهر هنا عمدًا: ستتعرّف عليها من خلال التمرين نفسه.'));

    root.append(head, h('div', { class: 'grid grid--2' }, about, grammar), dialogue, vocab, exercises);
    root.querySelectorAll(':scope > section, :scope > .grid').forEach((el) => { el.style.setProperty('margin-top', '1rem'); });
}
