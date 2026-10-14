import { reactive } from 'vue';
import {
    abort as abortRoute,
    complete as completeRoute,
    parts as partsRoute,
    start as startRoute,
} from '@/routes/video-uploads';

/**
 * E-11: upload a large video straight to storage in parts.
 * - parts go out 3 at a time, each retried up to 6 times with backoff;
 * - when the connection drops it waits for "online" and carries on (resume);
 * - pause / resume / cancel; progress in bytes.
 * Part URLs are fetched in small batches so they never expire mid-upload.
 */
export type VideoUploadState = {
    name: string;
    loaded: number;
    total: number;
    status:
        | 'starting'
        | 'uploading'
        | 'paused'
        | 'waiting-network'
        | 'finishing'
        | 'done'
        | 'error'
        | 'cancelled';
    error: string | null;
    pause: () => void;
    resume: () => void;
    cancel: () => void;
};

type StartResponse = { id: number; part_size: number; parts: number };

const CONCURRENCY = 3;
const MAX_TRIES = 6;
const BATCH = 20;

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

async function api<T>(url: string, method: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = (await res.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(
            first ?? data.message ?? `Upload failed (${res.status}).`,
        );
    }

    return data as T;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

function waitOnline(): Promise<void> {
    return navigator.onLine
        ? Promise.resolve()
        : new Promise((r) =>
              window.addEventListener('online', () => r(), { once: true }),
          );
}

/** PUT one part; resolves with the part's ETag. Same-origin URLs (local driver) carry the CSRF header. */
function putPart(
    url: string,
    blob: Blob,
    onProgress: (bytes: number) => void,
    signal: { xhr: XMLHttpRequest | null },
): Promise<string> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        signal.xhr = xhr;
        xhr.open('PUT', url);
        if (url.startsWith('/')) {
            xhr.setRequestHeader('X-XSRF-TOKEN', xsrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        }
        xhr.upload.onprogress = (e) => onProgress(e.loaded);
        xhr.onload = () => {
            const etag = xhr.getResponseHeader('ETag');
            if (xhr.status >= 200 && xhr.status < 300 && etag) {
                resolve(etag);
            } else {
                reject(
                    new Error(
                        xhr.status >= 200 && xhr.status < 300
                            ? 'Storage did not return the part ETag (check the bucket CORS rule exposes ETag).'
                            : `Part upload failed (${xhr.status}).`,
                    ),
                );
            }
        };
        xhr.onerror = () => reject(new Error('network'));
        xhr.onabort = () => reject(new Error('aborted'));
        xhr.send(blob);
    });
}

export function uploadVideo(
    file: File,
    target: {
        attachableType: 'task' | 'project';
        attachableId: number;
        versionOf?: number;
    },
): { state: VideoUploadState; done: Promise<boolean> } {
    let paused = false;
    let cancelled = false;
    let uploadId: number | null = null;
    const inflight = new Map<number, { xhr: XMLHttpRequest | null }>();
    const loadedByPart = new Map<number, number>();

    const state = reactive<VideoUploadState>({
        name: file.name,
        loaded: 0,
        total: file.size,
        status: 'starting',
        error: null,
        pause: () => {
            paused = true;
            state.status = 'paused';
            inflight.forEach((s) => s.xhr?.abort());
        },
        resume: () => {
            paused = false;
            state.status = 'uploading';
        },
        cancel: () => {
            cancelled = true;
            inflight.forEach((s) => s.xhr?.abort());
            state.status = 'cancelled';
            if (uploadId !== null) {
                void api(abortRoute.url(uploadId), 'DELETE').catch(() => {});
            }
        },
    });

    const tally = () => {
        let sum = 0;
        loadedByPart.forEach((v) => (sum += v));
        state.loaded = Math.min(sum, file.size);
    };

    const done = (async (): Promise<boolean> => {
        try {
            const started = await api<StartResponse>(startRoute.url(), 'POST', {
                attachable_type: target.attachableType,
                attachable_id: target.attachableId,
                name: file.name,
                size: file.size,
                version_of: target.versionOf ?? null,
            });
            uploadId = started.id;
            state.status = 'uploading';

            const etags = new Map<number, string>();
            const queue = Array.from(
                { length: started.parts },
                (_, i) => i + 1,
            );
            const urls = new Map<number, string>();

            const urlFor = async (part: number): Promise<string> => {
                if (!urls.has(part)) {
                    const batch = queue
                        .filter((p) => !etags.has(p) && !urls.has(p))
                        .slice(0, BATCH);
                    if (!batch.includes(part)) {
                        batch.unshift(part);
                    }
                    const res = await api<{ urls: Record<string, string> }>(
                        partsRoute.url(started.id),
                        'POST',
                        { parts: batch },
                    );
                    Object.entries(res.urls).forEach(([p, u]) =>
                        urls.set(Number(p), u),
                    );
                }

                return urls.get(part) as string;
            };

            const worker = async (): Promise<void> => {
                while (!cancelled) {
                    const part = queue.shift();
                    if (part === undefined) {
                        return;
                    }
                    const from = (part - 1) * started.part_size;
                    const blob = file.slice(
                        from,
                        Math.min(from + started.part_size, file.size),
                    );
                    for (let attempt = 1; ; attempt++) {
                        while (paused && !cancelled) {
                            await sleep(300);
                        }
                        if (cancelled) {
                            return;
                        }
                        if (!navigator.onLine) {
                            state.status = 'waiting-network';
                            await waitOnline();
                            state.status = paused ? 'paused' : 'uploading';
                        }
                        const signal = { xhr: null as XMLHttpRequest | null };
                        inflight.set(part, signal);
                        try {
                            const etag = await putPart(
                                await urlFor(part),
                                blob,
                                (b) => {
                                    loadedByPart.set(part, b);
                                    tally();
                                },
                                signal,
                            );
                            etags.set(part, etag);
                            loadedByPart.set(part, blob.size);
                            tally();
                            break;
                        } catch (e) {
                            loadedByPart.set(part, 0);
                            tally();
                            urls.delete(part); // a fresh link for the retry
                            if (cancelled) {
                                return;
                            }
                            if (paused) {
                                continue; // aborted by pause: not a failed try
                            }
                            if (attempt >= MAX_TRIES) {
                                throw e instanceof Error &&
                                    e.message !== 'network'
                                    ? e
                                    : new Error(
                                          'The connection kept dropping. Try again on a steadier network.',
                                      );
                            }
                            await sleep(Math.min(30000, 1000 * 2 ** attempt));
                        } finally {
                            inflight.delete(part);
                        }
                    }
                }
            };

            await Promise.all(
                Array.from(
                    { length: Math.min(CONCURRENCY, started.parts) },
                    () => worker(),
                ),
            );
            if (cancelled) {
                return false;
            }
            state.status = 'finishing';
            await api(completeRoute.url(started.id), 'POST', {
                parts: [...etags.entries()].map(([part, etag]) => ({
                    part,
                    etag,
                })),
            });
            state.status = 'done';

            return true;
        } catch (e) {
            if (cancelled) {
                return false;
            }
            state.status = 'error';
            state.error = e instanceof Error ? e.message : 'Upload failed.';
            if (uploadId !== null) {
                void api(abortRoute.url(uploadId), 'DELETE').catch(() => {});
            }

            return false;
        }
    })();

    return { state, done };
}
