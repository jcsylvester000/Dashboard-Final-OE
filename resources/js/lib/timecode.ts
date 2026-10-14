/**
 * E-14: frame-accurate timecode helpers (HH:MM:SS:FF, non-drop-frame).
 * A frame index is floor(seconds × fps); seeking lands in the middle of a frame so the
 * browser shows that frame and not the one before it.
 */
export function frameOf(seconds: number, fps: number): number {
    return Math.max(0, Math.floor(seconds * fps + 1e-6));
}

export function frameMid(frame: number, fps: number): number {
    return (Math.max(0, frame) + 0.5) / fps;
}

export function timecode(ms: number, fps: number): string {
    const nominal = Math.max(1, Math.round(fps));
    const total = frameOf(ms / 1000, fps);
    const frames = total % nominal;
    const secondsTotal = Math.floor(total / nominal);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${pad(Math.floor(secondsTotal / 3600))}:${pad(Math.floor((secondsTotal % 3600) / 60))}:${pad(secondsTotal % 60)}:${pad(frames)}`;
}

/** Short label for lists: 0:35 or 1:02:05. */
export function clock(ms: number): string {
    const s = Math.floor(ms / 1000);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');

    return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}
