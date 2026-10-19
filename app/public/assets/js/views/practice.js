import { api, upload, newSubmissionId } from '../api.js';
import { audioPlayer } from '../audio.js';
import { h, de, clear } from '../dom.js';
import { icon } from '../icons.js';
import { ERRORS, SKILLS, TYPES, VERDICTS, errorMessage } from '../i18n.js';
import { Recorder, RecorderError, formatSeconds } from '../recorder.js';
import { badge, banner, loading, meter, spinner, storage, toast, toastError } from '../ui.js';

const GOOD = ['mastered', 'require_transfer', 'next_transfer'];
const RETRY = ['retry', 'retry_new_strategy'];
const EVALUATORS = { ai: 'تقييم بالذكاء الاصطناعي', rule: 'تحقّق تلقائي (مطابقة نموذجية، بدون ذكاء اصطناعي)', offline: 'بدون تقييم آلي' };
const MODE_TITLES = { lesson: null, review: 'جلسة مراجعة', assessment: 'تقييم (محاولة واحدة لكل سؤال)' };

export async function render(root, ctx) {
    root.append(loading());
    let session = (await api(`api/sessions/${ctx.params.id}`)).session;
    if (!ctx.isCurrent()) return undefined;

    let answerMode = storage('gac-answer-mode') || (Recorder.supported() ? 'voice' : 'text');
    let lastResult = null;
    let busy = false;
    let recorder = null;
    let transcript = null; // {id,text,confidence,note_ar}
    let lastBlob = null;
    let submission = null; // {id,text} reused on network retry (idempotent)
    let draft = '';

    const live = h('div', { class: 'sr-only', 'aria-live': 'polite', role: 'status' });
    const layout = h('div', { class: 'practice' });
    root.replaceChildren(live, layout);

    const announce = (text) => { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 50); };

    const refresh = async () => {
        session = (await api(`api/sessions/${session.id}`)).session;
        renderAll();
    };

    // ------------------------------------------------------------ actions

    async function submitAnswer(text, inputMode = 'text', transcriptId = null) {
        if (busy) return;
        const clean = text.trim();
        if (!clean) {
            toast('اكتب إجابتك أولًا.', 'error');
            return;
        }
        // Same text + same transcript → reuse the idempotency key, so a retry after a network
        // error can never be graded twice.
        if (!submission || submission.text !== clean || submission.mode !== inputMode || submission.transcriptId !== transcriptId) {
            submission = { id: newSubmissionId(), text: clean, mode: inputMode, transcriptId };
        }
        busy = true;
        renderAnswer('processing');
        try {
            const res = await api(`api/sessions/${session.id}/answer`, {
                method: 'POST',
                body: { answer_text: clean, client_submission_id: submission.id, input_mode: inputMode, transcript_id: transcriptId },
                timeout: 90000,
            });
            submission = null;
            draft = '';
            transcript = null;
            lastBlob = null;
            lastResult = res;
            session = res.session;
            busy = false;
            renderAll();
            announce(res.decision.message_ar);
            document.getElementById('decision')?.focus();
            if (res.usage_warning) toast('تنبيه: اقتربت من حدّ الاستخدام.', 'info');
        } catch (err) {
            busy = false;
            if (err.code === 'answer_in_progress') {
                setTimeout(() => refresh().catch(toastError), 2000);
            }
            renderAnswer('error', err);
        }
    }

    async function uploadRecording(blob, durationMs) {
        lastBlob = { blob, durationMs };
        if (blob.size < 600) {
            renderAnswer('error', new RecorderError('audio_empty'));
            return;
        }
        busy = true;
        renderAnswer('uploading', 0);
        const fd = new FormData();
        const ext = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : blob.type.includes('mpeg') ? 'mp3' : 'webm';
        fd.append('audio', blob, `answer.${ext}`);
        fd.append('duration_ms', String(Math.round(durationMs)));
        try {
            const res = await upload(`api/sessions/${session.id}/audio`, fd, {
                onProgress: (pct) => renderAnswer(pct >= 100 ? 'transcribing' : 'uploading', pct),
            });
            transcript = res.transcript;
            busy = false;
            renderAnswer('transcript');
            announce(`النص المتعرَّف عليه: ${transcript.text}`);
        } catch (err) {
            busy = false;
            renderAnswer('error', err);
        }
    }

    async function toggleRecording(canvas, timerEl, statusEl, micBtn) {
        if (recorder && recorder.recording) {
            recorder.stop();
            return;
        }
        if (busy) return;
        transcript = null;
        recorder = new Recorder({
            maxSeconds: session.audio.max_seconds,
            canvas,
            onTick: (s) => { timerEl.textContent = `${formatSeconds(s)} / ${formatSeconds(session.audio.max_seconds)}`; },
            onAutoStop: () => toast('وصلت إلى المدة القصوى للتسجيل، تم الإيقاف تلقائيًا.', 'info'),
            onStop: ({ blob, durationMs }) => {
                micBtn.setAttribute('aria-pressed', 'false');
                uploadRecording(blob, durationMs);
            },
        });
        try {
            await recorder.start();
            micBtn.setAttribute('aria-pressed', 'true');
            micBtn.setAttribute('aria-label', 'إيقاف التسجيل');
            statusEl.replaceChildren(h('span', { class: 'rec-dot', 'aria-hidden': 'true' }), 'جارٍ التسجيل… تكلّم بالألمانية ثم اضغط للإيقاف.');
            announce('بدأ التسجيل');
        } catch (err) {
            recorder = null;
            renderAnswer('error', err);
        }
    }

    async function requestHint() {
        try {
            session = (await api(`api/sessions/${session.id}/hint`, { method: 'POST' })).session;
            renderAll();
            announce('تم عرض التلميح. ستُسجَّل المحاولة القادمة كمحاولة بمساعدة.');
        } catch (err) {
            toastError(err);
        }
    }

    async function skip() {
        const text = session.mode === 'assessment'
            ? 'تخطّي هذا السؤال يُحتسب إجابة غير صحيحة في التقييم. متابعة؟'
            : 'التخطّي مؤقت: لن يُحتسب التمرين متقنًا، وسيظهر لاحقًا في المراجعة. متابعة؟';
        if (!window.confirm(text)) return;
        recorder?.cancel();
        try {
            const res = await api(`api/sessions/${session.id}/skip`, { method: 'POST' });
            session = res.session;
            lastResult = { decision: res.decision, skipped: true };
            transcript = null;
            draft = '';
            renderAll();
            announce(res.decision.message_ar);
        } catch (err) {
            toastError(err);
        }
    }

    async function endSession() {
        if (!window.confirm('إنهاء الجلسة الآن؟ تقدّمك محفوظ بعد كل إجابة.')) return;
        recorder?.cancel();
        try {
            session = (await api(`api/sessions/${session.id}/complete`, { method: 'POST' })).session;
            renderAll();
        } catch (err) {
            toastError(err);
        }
    }

    // ------------------------------------------------------------ render

    const mainCol = h('div', { class: 'stack' });
    const sideCol = h('aside', { class: 'stack', 'aria-label': 'سجل المحادثة' });
    const answerBox = h('section', { class: 'card', 'aria-labelledby': 'answer-h' });
    layout.append(mainCol, sideCol);

    function renderAll() {
        clear(mainCol);
        mainCol.append(renderHeader());
        for (const n of renderNotices()) mainCol.append(n);
        if (lastResult) mainCol.append(renderFeedback(lastResult));
        if (session.completed) {
            mainCol.append(renderSummary());
        } else if (session.item) {
            mainCol.append(renderTask(), answerBox);
            renderAnswer(transcript ? 'transcript' : 'idle');
        }
        renderTurns();
    }

    function renderHeader() {
        const title = session.lesson ? session.lesson.title_ar : MODE_TITLES[session.mode];
        const p = session.progress;
        const progressEl = session.mode === 'lesson'
            ? meter('تمارين الدرس المتقنة', `${p.mastered ?? 0} / ${p.total}`, p.mastered ?? 0, p.total, 'success')
            : meter('الأسئلة', `${p.handled_in_session} / ${p.total}`, p.handled_in_session, p.total);
        return h('header', { class: 'card card--tight stack-sm' },
            h('div', { class: 'practice__head' },
                h('div', {},
                    h('div', { class: 'row' }, session.lesson ? badge(session.lesson.level, 'level') : null, session.mode !== 'lesson' ? badge(MODE_TITLES[session.mode], 'info') : null),
                    h('h1', { style: { margin: '.25rem 0 0', 'font-size': '1.3rem' } }, title || 'التمرين'),
                    session.lesson ? de(session.lesson.title_de, 'div', 'small muted') : null),
                session.completed ? null : h('button', { class: 'btn btn--ghost btn--sm', type: 'button', onClick: endSession }, 'إنهاء الجلسة')),
            progressEl);
    }

    function renderNotices() {
        const out = [];
        if (session.ai.demo_provider) out.push(banner('وضع تجريبي: التقييم غير حقيقي (مزوّد تجريبي).', 'warning'));
        if (!session.ai.available && session.ai.message_ar) {
            out.push(banner(`${session.ai.message_ar} الإجابات المطابقة للنموذج تُقيَّم تلقائيًا، ويمكنك التخطّي أو المراجعة.`, 'warning'));
        } else if (session.ai.warning) {
            out.push(banner('تنبيه: اقتربت من حدّ الاستخدام اليومي أو الشهري.', 'info'));
        }
        return out;
    }

    function renderTask() {
        const it = session.item;
        const phaseLabel = it.phase === 'transfer' ? `مهمة نقل ${it.transfer_index + 1} من ${it.transfer_count}` : 'التمرين الرئيسي';
        const attemptText = session.mode === 'assessment'
            ? 'محاولة واحدة'
            : `المحاولة ${session.attempts_on_item + 1} · الحدّ ${session.max_attempts} أخطاء قبل نقله للمراجعة`;
        const card = h('section', { class: 'card task', 'aria-labelledby': 'task-prompt' },
            h('div', { class: 'task__meta' },
                badge(phaseLabel, it.phase === 'transfer' ? 'success' : 'info'),
                badge(TYPES[it.type] || it.type),
                badge(SKILLS[it.skill_area] || it.skill_area),
                h('span', { class: 'xsmall muted', 'aria-label': 'عداد المحاولات' }, attemptText)),
            it.phase === 'transfer' ? h('p', { class: 'small', style: { color: 'var(--success)' } }, 'نفس المهارة في سياق جديد — أجب بدون مساعدة لإثبات الإتقان.') : null,
            h('p', { class: 'task__instruction' }, it.instruction_ar),
            h('h2', { id: 'task-prompt', class: 'task__prompt', lang: 'de', dir: 'ltr', tabindex: '-1' }, it.prompt_de));

        const tools = h('div', { class: 'row' });
        if (it.question_turn_id) tools.append(audioPlayer(`api/turns/${it.question_turn_id}/audio`, { label: 'استمع إلى السؤال', text: it.prompt_de }));
        card.append(tools);

        if (it.context_mode === 'read' && it.context_de) {
            card.append(h('div', { class: 'stack-sm', style: { 'margin-top': '1rem' } },
                h('div', { class: 'section-title' }, 'النص'),
                de(it.context_de, 'div', 'task__context de--block')));
        }
        if (it.context_mode === 'listen') {
            const box = h('div', { class: 'stack-sm', style: { 'margin-top': '1rem' } }, h('div', { class: 'section-title' }, 'نص الاستماع'));
            if (it.listening_turn_id) box.append(audioPlayer(`api/turns/${it.listening_turn_id}/audio`, { label: 'استمع إلى النص', text: it.context_de }));
            if (it.context_de) {
                box.append(de(it.context_de, 'div', 'task__context de--block'));
            } else {
                box.append(h('p', { class: 'small muted' }, 'النص مخفي لتدريب الاستماع. يمكنك إظهاره، لكن ذلك يُحتسب تلميحًا.'));
            }
            card.append(box);
        }
        if (session.hint_used && it.hint) {
            card.append(h('div', { class: 'card card--info card--flat card--tight', style: { 'margin-top': '1rem' } },
                h('div', { class: 'section-title' }, 'تلميح (المحاولة التالية تُسجَّل بمساعدة)'),
                it.hint.hint_de ? de(it.hint.hint_de, 'p', 'de--block') : null,
                it.hint.hint_ar ? h('p', { class: 'small', style: { margin: 0 } }, it.hint.hint_ar) : null));
        }
        const actions = h('div', { class: 'row', style: { 'margin-top': '1rem' } });
        if (it.hint_available && !session.hint_used) {
            const label = it.context_mode === 'listen' ? 'تلميح / إظهار النص' : 'تلميح';
            actions.append(h('button', { class: 'btn btn--ghost btn--sm', type: 'button', onClick: requestHint, title: 'المحاولة التالية ستُسجَّل كمحاولة بمساعدة' }, icon('bulb'), label));
        }
        actions.append(h('button', { class: 'btn btn--ghost btn--sm', type: 'button', onClick: skip }, icon('skip'), session.mode === 'assessment' ? 'تخطَّ السؤال' : 'تخطٍّ مؤقت'));
        card.append(actions);
        return card;
    }

    function renderAnswer(state, extra = null) {
        if (!session.item) return;
        clear(answerBox);
        answerBox.append(h('div', { class: 'card__title' },
            h('h2', { id: 'answer-h' }, 'إجابتك'),
            h('div', { class: 'answer-modes', role: 'group', 'aria-label': 'طريقة الإجابة' },
                modeButton('voice', 'بالصوت', 'mic'),
                modeButton('text', 'بالكتابة', 'keyboard'))));

        if (state === 'processing') {
            answerBox.append(h('div', { class: 'status-line', role: 'status' }, spinner(true), 'جارٍ تقييم إجابتك…'));
            return;
        }
        if (state === 'uploading' || state === 'transcribing') {
            answerBox.append(h('div', { class: 'status-line', role: 'status' }, spinner(true),
                state === 'uploading' ? `جارٍ رفع التسجيل… ${extra ?? 0}%` : 'جارٍ تحويل الصوت إلى نص…'));
            return;
        }
        if (state === 'error') {
            answerBox.append(renderError(extra));
            // then show the input UI again (transcript editor if a transcript is pending)
        }
        if ((state === 'transcript' || state === 'error') && transcript) {
            answerBox.append(renderTranscriptEditor());
            return;
        }
        answerBox.append(answerMode === 'voice' ? renderVoice() : renderText());
    }

    function modeButton(mode, label, iconName) {
        return h('button', {
            type: 'button',
            'aria-pressed': String(answerMode === mode),
            disabled: busy || (recorder && recorder.recording) ? true : null,
            onClick: () => {
                answerMode = mode;
                storage('gac-answer-mode', mode);
                transcript = null;
                renderAnswer('idle');
            },
        }, icon(iconName), ' ', label);
    }

    function renderError(err) {
        const msg = err instanceof RecorderError ? (ERRORS[err.code] || ERRORS.mic_error) : errorMessage(err);
        const box = h('div', { class: 'status-line status-line--error', role: 'alert', style: { 'margin-bottom': '1rem' } }, icon('alert'), h('span', {}, msg));
        const fallback = err && err.details && err.details.fallback;
        const row = h('div', { class: 'row', style: { 'margin-bottom': '1rem' } });
        if (submission && (err.code === 'network' || err.code === 'timeout' || err.code === 'ai_provider_error')) {
            row.append(h('button', { class: 'btn btn--primary btn--sm', type: 'button', onClick: () => submitAnswer(submission.text, submission.mode, submission.transcriptId) }, icon('refresh'), 'أعد الإرسال'));
        }
        if (lastBlob && (err.code === 'network' || err.code === 'timeout' || err.code === 'stt_unavailable')) {
            row.append(h('button', { class: 'btn btn--secondary btn--sm', type: 'button', onClick: () => uploadRecording(lastBlob.blob, lastBlob.durationMs) }, icon('refresh'), 'أعد رفع التسجيل'));
        }
        if (fallback === 'type_answer' || err instanceof RecorderError) {
            row.append(h('button', { class: 'btn btn--secondary btn--sm', type: 'button', onClick: () => { answerMode = 'text'; renderAnswer('idle'); } }, icon('keyboard'), 'اكتب الإجابة بدلًا من ذلك'));
        }
        return h('div', {}, box, row.childElementCount ? row : null);
    }

    function renderText() {
        const ta = h('textarea', { class: 'textarea de', lang: 'de', dir: 'ltr', name: 'answer', 'aria-label': 'اكتب إجابتك بالألمانية', maxlength: '2000', spellcheck: 'false', autocomplete: 'off', placeholder: 'Schreiben Sie Ihre Antwort …' });
        ta.value = draft;
        ta.addEventListener('input', () => { draft = ta.value; });
        const send = h('button', { class: 'btn btn--primary', type: 'submit' }, icon('send'), 'تحقّق من الإجابة');
        const form = h('form', { class: 'form' }, ta,
            h('div', { class: 'row row--between' }, h('span', { class: 'xsmall muted' }, h('kbd', {}, 'Ctrl'), ' + ', h('kbd', {}, 'Enter'), ' للإرسال'), send));
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            submitAnswer(ta.value, 'text');
        });
        ta.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                submitAnswer(ta.value, 'text');
            }
        });
        setTimeout(() => ta.focus({ preventScroll: true }), 0);
        return form;
    }

    function renderVoice() {
        if (!Recorder.supported() || !window.isSecureContext) {
            return h('div', { class: 'stack-sm' },
                banner(!window.isSecureContext ? ERRORS.mic_insecure : ERRORS.mic_unsupported, 'warning'),
                h('button', { class: 'btn btn--secondary', type: 'button', onClick: () => { answerMode = 'text'; renderAnswer('idle'); } }, 'الإجابة بالكتابة'));
        }
        const canvas = h('canvas', { class: 'recorder__wave', 'aria-hidden': 'true' });
        const timer = h('div', { class: 'recorder__timer', 'aria-live': 'off', dir: 'ltr' }, `00:00 / ${formatSeconds(session.audio.max_seconds)}`);
        const status = h('div', { class: 'recorder__status', role: 'status' }, 'اضغط على الميكروفون وتكلّم بالألمانية.');
        const mic = h('button', { class: 'mic', type: 'button', 'aria-pressed': 'false', 'aria-label': 'ابدأ التسجيل' }, icon('mic'));
        mic.addEventListener('click', () => {
            if (recorder && recorder.recording) {
                mic.setAttribute('aria-label', 'ابدأ التسجيل');
                status.textContent = 'تم الإيقاف.';
            }
            toggleRecording(canvas, timer, status, mic);
        });
        return h('div', { class: 'recorder' }, mic, timer, canvas, status,
            h('p', { class: 'xsmall muted center', style: { margin: 0 } }, 'يُطلب إذن الميكروفون عند الضغط فقط. يُحذف التسجيل من الخادم بعد تحويله إلى نص.'));
    }

    function renderTranscriptEditor() {
        const conf = { high: ['تعرّف واضح', 'success'], medium: ['تعرّف متوسط', 'info'], low: ['تعرّف غير مؤكد', 'warning'], unknown: ['دقة غير معروفة', ''] }[transcript.confidence] || ['', ''];
        const ta = h('textarea', { class: 'textarea de', lang: 'de', dir: 'ltr', 'aria-label': 'النص المتعرَّف عليه (يمكنك تصحيحه)', maxlength: '2000', spellcheck: 'false' });
        ta.value = transcript.text;
        const check = h('button', { class: 'btn btn--primary', type: 'button' }, icon('check'), 'تحقّق من الإجابة');
        check.addEventListener('click', () => submitAnswer(ta.value, 'audio', transcript.id));
        const again = h('button', { class: 'btn btn--secondary', type: 'button' }, icon('mic'), 'سجّل مرة أخرى');
        again.addEventListener('click', () => { transcript = null; renderAnswer('idle'); });
        setTimeout(() => ta.focus({ preventScroll: true }), 0);
        return h('div', { class: 'stack-sm' },
            h('div', { class: 'row row--between' }, h('strong', {}, 'هذا ما سمعناه'), badge(conf[0], conf[1])),
            ta,
            h('p', { class: 'small muted', style: { margin: 0 } }, transcript.note_ar, ' تعديل النص يُسجَّل، ولا يُحسب خطأ التعرّف على الصوت خطأً لغويًا.'),
            h('div', { class: 'row' }, check, again));
    }

    function decisionClass(code) {
        if (GOOD.includes(code)) return 'decision--good';
        if (RETRY.includes(code)) return 'decision--retry';
        return 'decision--neutral';
    }

    function renderFeedback(res) {
        const d = res.decision;
        const wrap = h('section', { class: 'card stack', 'aria-labelledby': 'decision' });
        wrap.append(h('div', { id: 'decision', class: `decision ${decisionClass(d.code)}`, tabindex: '-1' },
            icon(GOOD.includes(d.code) ? 'check' : (RETRY.includes(d.code) ? 'refresh' : 'alert')), h('span', {}, d.message_ar)));
        if (res.skipped || !res.attempt) return wrap;

        const a = res.attempt;
        const f = res.feedback;
        wrap.append(h('div', { class: 'row' },
            badge(VERDICTS[a.verdict] || a.verdict, a.outcome === 'success' ? 'success' : (a.outcome === 'failure' ? 'danger' : 'warning')),
            h('span', { class: 'xsmall muted' }, EVALUATORS[a.evaluator] || ''),
            a.hint_used ? badge('بمساعدة تلميح', 'warning') : null,
            a.copied_correction ? badge('منسوخة من التصحيح', 'warning') : null,
            a.independent && a.outcome === 'success' ? badge('مستقلة', 'success') : null));

        const mine = h('div', { class: 'compare__col' }, h('h4', {}, a.input_mode === 'audio' ? 'إجابتك (من الصوت)' : 'إجابتك'), de(a.answer_text, 'p', 'compare__text de--block'));
        if (a.input_mode === 'audio' && a.transcript_edited && a.transcript_original) {
            mine.append(h('p', { class: 'xsmall muted', style: { margin: '.5rem 0 0' } }, 'النص الأصلي قبل تعديلك: ', de(a.transcript_original)));
        }
        const cols = [mine];
        if (f.corrected_answer && f.corrected_answer !== a.answer_text) {
            const fbTurn = [...session.turns].reverse().find((t) => t.kind === 'feedback' && t.has_audio);
            cols.push(h('div', { class: 'compare__col compare__col--fixed' }, h('h4', {}, 'الصيغة المصحّحة'), de(f.corrected_answer, 'p', 'compare__text de--block'),
                fbTurn ? audioPlayer(`api/turns/${fbTurn.id}/audio`, { label: 'استمع', text: f.corrected_answer }) : null));
        }
        wrap.append(h('div', { class: 'compare' }, ...cols));

        if (f.errors && f.errors.length) {
            wrap.append(h('ul', { class: 'errors', 'aria-label': 'الأخطاء' }, ...f.errors.map((e) => h('li', { class: 'error-item' },
                badge(e.category_ar, 'danger'),
                h('div', { class: 'error-item__fix', lang: 'de', dir: 'ltr' }, h('span', { class: 'strike' }, e.original), ' → ', h('span', { class: 'fixword' }, e.correction)),
                h('div', { class: 'small' }, e.explanation_ar)))));
        }
        if (f.hint_de) wrap.append(h('div', { class: 'card card--info card--flat card--tight' }, h('div', { class: 'section-title' }, 'تلميح للمحاولة التالية'), de(f.hint_de, 'p', 'de--block')));
        if (f.explanation_ar) wrap.append(h('p', { class: 'small', style: { margin: 0 } }, f.explanation_ar));

        const s = f.strategy || {};
        if (s.level > 0) {
            const det = h('details', { class: 'explain', open: true }, h('summary', {}, `طريقة شرح جديدة: ${s.label_ar}`));
            if (s.hint_de || s.hint_ar) det.append(h('div', { class: 'stack-sm' }, s.hint_de ? de(s.hint_de, 'p', 'de--block') : null, s.hint_ar ? h('p', { class: 'small' }, s.hint_ar) : null));
            if (s.explanation_ar) det.append(h('p', {}, s.explanation_ar));
            for (const n of s.grammar_notes || []) {
                det.append(h('div', { class: 'stack-sm' }, h('strong', {}, n.title_ar), h('p', { class: 'small' }, n.body_ar), h('ul', {}, ...(n.examples_de || []).map((x) => h('li', {}, de(x))))));
            }
            if (s.model_answer) det.append(h('div', { class: 'card card--success card--flat card--tight' }, h('div', { class: 'section-title' }, 'جملة نموذجية — كرّرها بنفسك'), de(s.model_answer, 'p', 'de--block')));
            wrap.append(det);
        }
        if (f.reference_answers && f.reference_answers.length) {
            wrap.append(h('div', { class: 'card card--warning card--flat card--tight' },
                h('div', { class: 'section-title' }, 'إجابات نموذجية للمقارنة الذاتية'),
                h('ul', {}, ...f.reference_answers.map((r) => h('li', {}, de(r))))));
        }
        if (d.review) wrap.append(h('p', { class: 'xsmall muted', style: { margin: 0 } }, `المراجعة القادمة بعد ${d.review.interval_days} يوم/أيام.`));
        return wrap;
    }

    function renderSummary() {
        const s = session.summary || {};
        const card = h('section', { class: 'card stack' }, h('h2', {}, s.ended_early ? 'تم إنهاء الجلسة' : 'انتهت الجلسة'));
        card.append(h('div', { class: 'grid grid--4' },
            stat(s.attempts ?? 0, 'محاولات'), stat(s.successes ?? 0, 'إجابات ناجحة'), stat(s.mastered ?? 0, 'تمارين أُتقنت'), stat(s.active_minutes ?? 0, 'دقائق نشطة')));
        if ((s.needs_review ?? 0) + (s.skipped ?? 0) > 0) card.append(h('p', { class: 'small muted' }, `${s.needs_review ?? 0} تمارين للمراجعة و${s.skipped ?? 0} تم تخطّيها — ستظهر في قائمة المراجعة.`));
        if (s.lesson_completed) card.append(banner('أحسنت! اكتمل هذا الدرس وفُتح الدرس التالي.', 'info'));
        if (s.assessment) {
            const a = s.assessment;
            card.append(h('h3', {}, 'نتيجة التقييم'),
                h('div', { class: 'table-wrap' }, h('table', { class: 'table' },
                    h('thead', {}, h('tr', {}, h('th', {}, 'المستوى'), h('th', {}, 'صحيح'), h('th', {}, 'المجموع'))),
                    h('tbody', {}, ...Object.entries(a.by_level || {}).map(([lvl, v]) => h('tr', {}, h('td', {}, badge(lvl, 'level')), h('td', {}, String(v.correct ?? 0)), h('td', {}, String(v.total ?? 0))))))),
                a.recommended_start_level ? h('p', {}, 'نقطة البداية المقترحة: ', badge(a.recommended_start_level, 'level')) : null,
                h('p', { class: 'small muted' }, a.disclaimer_ar));
        }
        card.append(h('div', { class: 'row' },
            h('a', { class: 'btn btn--primary', href: '#/' }, 'إلى الرئيسية'),
            h('a', { class: 'btn btn--secondary', href: '#/lessons' }, 'الدروس'),
            h('a', { class: 'btn btn--ghost', href: '#/reviews' }, 'المراجعة')));
        return card;
    }

    function stat(value, label) {
        return h('div', { class: 'stat' }, h('span', { class: 'stat__value' }, String(value)), h('span', { class: 'stat__label' }, label));
    }

    function renderTurns() {
        clear(sideCol);
        const list = h('ol', { class: 'turns' });
        for (const t of session.turns) {
            const who = t.role === 'tutor' ? 'المدرّب' : (t.role === 'learner' ? 'أنت' : 'ملاحظة');
            const item = h('li', { class: `turn turn--${t.role}` });
            const verdict = t.payload && t.payload.feedback ? VERDICTS[t.payload.feedback.verdict] : null;
            item.append(h('div', { class: 'turn__who' }, h('span', {}, who), verdict ? h('span', {}, verdict) : null));
            if (t.hidden) item.append(h('div', { class: 'xsmall muted' }, 'نص الاستماع مخفي — استمع إليه.'));
            else if (t.text_de) item.append(de(t.text_de, 'div'));
            if (t.text_ar && t.kind !== 'feedback') item.append(h('div', { class: 'xsmall muted' }, t.text_ar));
            if (t.kind === 'feedback' && t.payload && t.payload.decision) item.append(h('div', { class: 'xsmall muted' }, t.payload.decision.message_ar));
            if (t.payload && t.payload.input_mode === 'audio') item.append(h('div', { class: 'xsmall muted' }, '🎤 من التسجيل الصوتي'));
            if (t.has_audio) item.append(audioPlayer(`api/turns/${t.id}/audio`, { label: 'استمع', text: t.hidden ? null : t.text_de }));
            list.append(item);
        }
        sideCol.append(h('section', { class: 'card card--tight' }, h('h2', { class: 'section-title' }, 'سجل المحادثة'), list));
        requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; });
    }

    renderAll();
    return () => {
        if (recorder) recorder.cancel();
        if (window.speechSynthesis) window.speechSynthesis.cancel();
    };
}
