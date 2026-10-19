import { api, fetchAudio, getTtsMode } from './api.js';
import { h } from './dom.js';
import { icon } from './icons.js';
import { errorMessage } from './i18n.js';

const cache = new Map(); // path → object URL (server audio)
const textCache = new Map(); // path → German text (browser voice)

export function browserSpeechAvailable() {
    return 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined';
}

function voicesReady() {
    return new Promise((resolve) => {
        const voices = window.speechSynthesis.getVoices();
        if (voices.length) {
            resolve(voices);
            return;
        }
        const done = () => resolve(window.speechSynthesis.getVoices());
        window.speechSynthesis.addEventListener('voiceschanged', done, { once: true });
        setTimeout(done, 1500);
    });
}

/** Best available German voice: natural/online voices first (Edge "Natural", Chrome "Google Deutsch"). */
export async function germanVoice() {
    if (!browserSpeechAvailable()) return null;
    const voices = (await voicesReady()).filter((v) => v.lang && v.lang.toLowerCase().startsWith('de'));
    const score = (v) => (/natural|online|neural/i.test(v.name) ? 3 : 0) + (/google/i.test(v.name) ? 2 : 0) + (v.lang.toLowerCase() === 'de-de' ? 1 : 0);
    voices.sort((a, b) => score(b) - score(a));
    return voices[0] || null;
}

/** The device's own German voice (free; quality depends on browser and operating system). */
export async function speakWithBrowser(text, { rate = 0.9 } = {}) {
    if (!browserSpeechAvailable() || !text) return { ok: false, voice: null };
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'de-DE';
    u.rate = rate;
    const voice = await germanVoice();
    if (voice) u.voice = voice;
    window.speechSynthesis.speak(u);
    return { ok: true, voice };
}

async function textFor(path, text) {
    if (text) return text;
    if (textCache.has(path)) return textCache.get(path);
    const m = path.match(/^api\/turns\/(\d+)\/audio$/);
    if (!m) return null;
    const data = await api(`api/turns/${m[1]}/speech-text`);
    textCache.set(path, data.text_de);
    return data.text_de;
}

/**
 * A play control. Server mode: loads generated audio on first click and shows the native
 * <audio controls>. Browser mode (or when server audio is unavailable): speaks the German
 * text with the device's voice, with normal and slow buttons.
 */
export function audioPlayer(path, { label = 'استمع', text = null } = {}) {
    const wrap = h('div', { class: 'player' });
    const status = h('span', { class: 'xsmall muted', role: 'status' });

    const browserControls = (note = '') => {
        wrap.replaceChildren();
        const speak = (rate) => async () => {
            status.textContent = '';
            try {
                const t = await textFor(path, text);
                const res = await speakWithBrowser(t, { rate });
                if (!res.ok) status.textContent = 'متصفحك لا يدعم النطق.';
                else if (!res.voice) status.textContent = 'لا يوجد صوت ألماني على جهازك — جرّب متصفح Edge أو Chrome.';
            } catch (err) {
                status.textContent = errorMessage(err);
            }
        };
        wrap.append(
            h('button', { class: 'btn btn--secondary btn--sm', type: 'button', onClick: speak(0.92), 'aria-label': `${label} (صوت الجهاز)` }, icon('volume'), label),
            h('button', { class: 'btn btn--ghost btn--sm', type: 'button', onClick: speak(0.7), 'aria-label': `${label} ببطء` }, 'ببطء'),
            status);
        if (note) status.textContent = note;
    };

    if (getTtsMode() === 'browser') {
        browserControls();
        return wrap;
    }

    const btn = h('button', { class: 'btn btn--secondary btn--sm', type: 'button', 'aria-label': `${label} (تشغيل الصوت)` }, icon('volume'), label);
    wrap.append(btn, status);

    const showAudio = (src) => {
        const audio = h('audio', { controls: true, preload: 'auto', src, 'aria-label': label });
        btn.replaceWith(audio);
        status.textContent = '';
        audio.play().catch(() => { /* autoplay may be blocked; native controls remain */ });
    };

    btn.addEventListener('click', async () => {
        if (cache.has(path)) {
            showAudio(cache.get(path));
            return;
        }
        btn.disabled = true;
        status.textContent = 'جارٍ تحميل الصوت…';
        try {
            const src = await fetchAudio(path);
            cache.set(path, src);
            showAudio(src);
        } catch (err) {
            btn.disabled = false;
            if (browserSpeechAvailable() && (text || /^api\/turns\//.test(path))) {
                browserControls(err.details && err.details.fallback === 'browser_speech' ? '' : `${errorMessage(err)} — صوت الجهاز بدلًا من ذلك.`);
            } else {
                status.textContent = errorMessage(err);
            }
        }
    });
    return wrap;
}
