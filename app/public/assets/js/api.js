// JSON API client. Same-origin only; the CSRF token is kept in memory and sent as a header.
// The browser never talks to the AI provider directly and never sees API keys.

const BASE = new URL('./', document.baseURI);
let csrfToken = null;
let onUnauthorized = null;
let ttsMode = 'server';

/** 'server' = generated audio from the API; 'browser' = the device's own German voice. */
export function setTtsMode(mode) {
    ttsMode = mode === 'browser' ? 'browser' : 'server';
}

export function getTtsMode() {
    return ttsMode;
}

export class ApiError extends Error {
    constructor(status, code, message, details = {}) {
        super(message || code);
        this.status = status;
        this.code = code;
        this.details = details || {};
    }
}

export function setCsrf(token) {
    if (typeof token === 'string' && token.length > 0) csrfToken = token;
}

export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

export function url(path) {
    return new URL(path.replace(/^\//, ''), BASE).toString();
}

function toError(status, data, fallback) {
    const err = data && data.error ? data.error : {};
    return new ApiError(status, err.code || fallback, err.message || '', err.details || {});
}

function handle(status, data) {
    if (data && typeof data.csrf_token === 'string') setCsrf(data.csrf_token);
    if (status === 401 && onUnauthorized) onUnauthorized();
    if (status < 200 || status >= 300 || !data || data.ok === false) {
        throw toError(status, data, `http_${status}`);
    }
    return data;
}

export async function api(path, { method = 'GET', body, timeout = 60000 } = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    const headers = { Accept: 'application/json' };
    let payload;
    if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken || '';
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch(url(path), { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
    } catch (e) {
        throw new ApiError(0, e.name === 'AbortError' ? 'timeout' : 'network', '');
    } finally {
        clearTimeout(timer);
    }
    const data = await res.json().catch(() => null);
    return handle(res.status, data);
}

/** Multipart upload with progress events (fetch has no upload progress). */
export function upload(path, formData, { onProgress, timeout = 120000 } = {}) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url(path));
        xhr.timeout = timeout;
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', csrfToken || '');
        xhr.upload.onprogress = (e) => {
            if (onProgress && e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
        };
        xhr.onload = () => {
            let data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch {
                data = null;
            }
            try {
                resolve(handle(xhr.status, data));
            } catch (e) {
                reject(e);
            }
        };
        xhr.onerror = () => reject(new ApiError(0, 'network', ''));
        xhr.ontimeout = () => reject(new ApiError(0, 'timeout', ''));
        xhr.send(formData);
    });
}

/** Fetches generated audio. Returns an object URL (blob:) for a native <audio> element. */
export async function fetchAudio(path) {
    let res;
    try {
        res = await fetch(url(path), { credentials: 'same-origin' });
    } catch {
        throw new ApiError(0, 'network', '');
    }
    if (!res.ok) {
        const data = await res.json().catch(() => null);
        if (res.status === 401 && onUnauthorized) onUnauthorized();
        throw toError(res.status, data, `http_${res.status}`);
    }
    const blob = await res.blob();
    return URL.createObjectURL(blob);
}

export function newSubmissionId() {
    if (crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    const a = new Uint8Array(16);
    crypto.getRandomValues(a);
    return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
}
