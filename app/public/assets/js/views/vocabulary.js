import { api } from '../api.js';
import { audioPlayer } from '../audio.js';
import { h, de, clear } from '../dom.js';
import { badge, emptyState, loading, toastError } from '../ui.js';

const STATUS = { new: 'جديدة', due: 'مستحقة', learning: 'قيد الحفظ', known: 'محفوظة' };
const GRADES = [
    { key: 'again', label: 'لم أتذكّر', hotkey: '1', variant: 'btn--danger' },
    { key: 'hard', label: 'صعبة', hotkey: '2', variant: 'btn--secondary' },
    { key: 'good', label: 'جيدة', hotkey: '3', variant: 'btn--secondary' },
    { key: 'easy', label: 'سهلة', hotkey: '4', variant: 'btn--success' },
];
const NEW_PER_ROUND = 10;

const fullWord = (w) => `${w.article ? `${w.article} ` : ''}${w.word_de}`;

export async function render(root, ctx) {
    root.append(loading());
    let data = await api('api/vocabulary');
    if (!ctx.isCurrent()) return undefined;
    root.replaceChildren();

    const deckBox = h('section', { class: 'card', 'aria-labelledby': 'deck-h' });
    const listBox = h('section', { class: 'card', style: { 'margin-top': '1rem' } });
    let deck = [];
    let shownAt = 0;
    let revealed = false;
    let busy = false;

    const buildDeck = () => {
        const due = data.words.filter((w) => w.status === 'due');
        const fresh = data.words.filter((w) => w.status === 'new').slice(0, NEW_PER_ROUND);
        deck = [...due, ...fresh];
    };

    root.append(h('div', { class: 'page-head' },
        h('div', {}, h('h1', {}, 'المفردات'), h('p', { class: 'muted', style: { margin: 0 } }, 'بطاقات للمراجعة الذاتية — تعمل بالكامل بدون ذكاء اصطناعي.'))),
    deckBox, listBox);

    async function grade(key) {
        if (busy || !deck.length) return;
        busy = true;
        const word = deck[0];
        try {
            await api(`api/vocabulary/${word.id}/review`, { method: 'POST', body: { grade: key, elapsed_ms: Date.now() - shownAt } });
            deck.shift();
            if (key === 'again') deck.push(word); // see it again this round
            revealed = false;
            renderCard();
        } catch (err) {
            toastError(err);
        } finally {
            busy = false;
        }
    }

    function renderCard() {
        clear(deckBox);
        deckBox.append(h('div', { class: 'card__title' }, h('h2', { id: 'deck-h' }, 'جولة اليوم'), badge(`${deck.length} بطاقة`)));
        if (!deck.length) {
            deckBox.append(emptyState('لا توجد بطاقات مستحقة أو جديدة الآن. عُد لاحقًا أو افتح دروسًا جديدة.',
                h('button', { class: 'btn btn--secondary', type: 'button', onClick: async () => { data = await api('api/vocabulary'); buildDeck(); renderCard(); renderList(); } }, 'تحديث')));
            return;
        }
        const w = deck[0];
        shownAt = Date.now();
        const card = h('div', { class: 'flashcard' },
            badge(STATUS[w.status] || w.status, w.status === 'due' ? 'warning' : 'info'),
            de(fullWord(w), 'div', 'flashcard__word'),
            w.plural ? h('div', { class: 'small muted' }, 'الجمع: ', de(`die ${w.plural}`)) : null,
            audioPlayer(`api/vocabulary/${w.id}/audio`, { label: 'نطق الكلمة', text: `${fullWord(w)}. ${w.example_de || ''}` }));
        if (revealed) {
            card.append(h('div', { class: 'flashcard__meaning' }, w.word_ar),
                w.example_de ? de(w.example_de, 'div', 'muted') : null,
                h('div', { class: 'grades', role: 'group', 'aria-label': 'قيّم تذكّرك' }, ...GRADES.map((g) =>
                    h('button', { class: `btn ${g.variant}`, type: 'button', onClick: () => grade(g.key), 'aria-keyshortcuts': g.hotkey }, `${g.label} (${g.hotkey})`))));
        } else {
            const show = h('button', { class: 'btn btn--primary btn--lg', type: 'button', 'aria-keyshortcuts': 'Space' }, 'اعرض المعنى');
            show.addEventListener('click', () => { revealed = true; renderCard(); });
            card.append(show);
            setTimeout(() => show.focus({ preventScroll: true }), 0);
        }
        deckBox.append(card, h('p', { class: 'xsmall muted center' }, 'قيّم بصدق: التقييم يحدّد موعد ظهور البطاقة مجددًا فقط، ولا يفتح أي تمرين.'));
    }

    function renderList() {
        clear(listBox);
        const c = data.counts;
        listBox.append(h('div', { class: 'card__title' }, h('h2', {}, 'كل الكلمات'),
            h('div', { class: 'row' }, badge(`جديدة ${c.new}`), badge(`مستحقة ${c.due}`, 'warning'), badge(`قيد الحفظ ${c.learning}`, 'info'), badge(`محفوظة ${c.known}`, 'success'))));
        if (!data.words.length) {
            listBox.append(emptyState('لا توجد كلمات في الدروس المتاحة بعد.'));
            return;
        }
        listBox.append(h('div', { class: 'table-wrap' }, h('table', { class: 'table' },
            h('thead', {}, h('tr', {}, h('th', {}, 'الكلمة'), h('th', {}, 'المعنى'), h('th', {}, 'الدرس'), h('th', {}, 'الحالة'))),
            h('tbody', {}, ...data.words.map((w) => h('tr', {},
                h('td', {}, de(fullWord(w), 'strong')),
                h('td', {}, w.word_ar),
                h('td', { class: 'xsmall muted' }, w.lesson_title_ar),
                h('td', {}, badge(STATUS[w.status] || w.status, w.status === 'known' ? 'success' : (w.status === 'due' ? 'warning' : '')), w.lapses ? h('span', { class: 'xsmall muted' }, ` · نُسيت ${w.lapses}×`) : null)))))));
    }

    const onKey = (e) => {
        if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;
        if (!revealed && e.key === ' ' && deck.length) {
            e.preventDefault();
            revealed = true;
            renderCard();
            return;
        }
        const g = GRADES.find((x) => x.hotkey === e.key);
        if (revealed && g) grade(g.key);
    };
    document.addEventListener('keydown', onKey);

    buildDeck();
    renderCard();
    renderList();
    return () => document.removeEventListener('keydown', onKey);
}
