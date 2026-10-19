// Turn-based voice recording with MediaRecorder: supported-format detection, a live level
// meter, a visible timer and a hard maximum duration. The microphone is requested only
// after the learner presses the record button.

const CANDIDATE_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4',
    'audio/mpeg',
];

export class RecorderError extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

export class Recorder {
    static supported() {
        return Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    }

    static pickMimeType() {
        if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== 'function') return '';
        return CANDIDATE_TYPES.find((t) => MediaRecorder.isTypeSupported(t)) || '';
    }

    constructor({ maxSeconds = 60, canvas = null, onTick = () => {}, onStop = () => {}, onAutoStop = () => {} } = {}) {
        this.maxSeconds = maxSeconds;
        this.canvas = canvas;
        this.onTick = onTick;
        this.onStop = onStop;
        this.onAutoStop = onAutoStop;
        this.stream = null;
        this.recorder = null;
        this.chunks = [];
        this.startedAt = 0;
        this.timer = null;
        this.raf = null;
        this.audioCtx = null;
        this.cancelled = false;
    }

    get recording() {
        return this.recorder !== null && this.recorder.state === 'recording';
    }

    async start() {
        if (!window.isSecureContext) throw new RecorderError('mic_insecure');
        if (!Recorder.supported()) throw new RecorderError('mic_unsupported');
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 } });
        } catch (e) {
            const map = { NotAllowedError: 'mic_denied', SecurityError: 'mic_denied', NotFoundError: 'mic_missing', OverconstrainedError: 'mic_missing', NotReadableError: 'mic_busy', AbortError: 'mic_busy' };
            throw new RecorderError(map[e && e.name] || 'mic_error');
        }
        const mimeType = Recorder.pickMimeType();
        try {
            this.recorder = new MediaRecorder(this.stream, mimeType ? { mimeType, audioBitsPerSecond: 32000 } : undefined);
        } catch {
            this.cleanup();
            throw new RecorderError('mic_unsupported');
        }
        this.chunks = [];
        this.cancelled = false;
        this.recorder.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size > 0) this.chunks.push(e.data);
        });
        this.recorder.addEventListener('stop', () => {
            const durationMs = Date.now() - this.startedAt;
            const type = (this.recorder && this.recorder.mimeType) || mimeType || 'audio/webm';
            const blob = new Blob(this.chunks, { type });
            const cancelled = this.cancelled;
            this.cleanup();
            if (!cancelled) this.onStop({ blob, durationMs, mimeType: type });
        });
        this.recorder.start(250);
        this.startedAt = Date.now();
        this.timer = setInterval(() => {
            const elapsed = (Date.now() - this.startedAt) / 1000;
            this.onTick(elapsed);
            if (elapsed >= this.maxSeconds) {
                this.onAutoStop();
                this.stop();
            }
        }, 200);
        this.startMeter();
    }

    stop() {
        if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
    }

    cancel() {
        this.cancelled = true;
        if (this.recorder && this.recorder.state !== 'inactive') {
            this.recorder.stop();
        } else {
            this.cleanup();
        }
    }

    startMeter() {
        if (!this.canvas || !(window.AudioContext || window.webkitAudioContext)) return;
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            this.audioCtx = new Ctx();
            const source = this.audioCtx.createMediaStreamSource(this.stream);
            const analyser = this.audioCtx.createAnalyser();
            analyser.fftSize = 1024;
            source.connect(analyser);
            const data = new Uint8Array(analyser.fftSize);
            const ctx = this.canvas.getContext('2d');
            const color = getComputedStyle(document.documentElement).getPropertyValue('--record').trim() || '#d32f2f';
            const dpr = window.devicePixelRatio || 1;
            const draw = () => {
                const w = this.canvas.clientWidth;
                const hgt = this.canvas.clientHeight;
                if (this.canvas.width !== w * dpr) {
                    this.canvas.width = w * dpr;
                    this.canvas.height = hgt * dpr;
                }
                analyser.getByteTimeDomainData(data);
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.clearRect(0, 0, w, hgt);
                ctx.lineWidth = 2;
                ctx.strokeStyle = color;
                ctx.beginPath();
                const step = w / data.length;
                for (let i = 0; i < data.length; i++) {
                    const y = (data[i] / 255) * hgt;
                    if (i === 0) ctx.moveTo(0, y);
                    else ctx.lineTo(i * step, y);
                }
                ctx.stroke();
                this.raf = requestAnimationFrame(draw);
            };
            draw();
        } catch {
            /* the meter is optional */
        }
    }

    cleanup() {
        clearInterval(this.timer);
        this.timer = null;
        if (this.raf) cancelAnimationFrame(this.raf);
        this.raf = null;
        if (this.audioCtx) this.audioCtx.close().catch(() => {});
        this.audioCtx = null;
        if (this.stream) this.stream.getTracks().forEach((t) => t.stop());
        this.stream = null;
        if (this.canvas) {
            const ctx = this.canvas.getContext('2d');
            ctx && ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        }
    }
}

export function formatSeconds(s) {
    const total = Math.max(0, Math.floor(s));
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
