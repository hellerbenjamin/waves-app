/**
 * Stream a multi-GB WAV (or a stitched timeline of several WAVs) straight from
 * the user's disk through Web Audio — no upload, no giant in-memory blob, and
 * no 4 GB ceiling. The player reads small PCM windows on demand via
 * `File.slice()` (the same zero-memory pattern the peaks scanner and encoder
 * use), sums every channel to mono, and schedules each window as a short
 * AudioBuffer on a private AudioContext.
 *
 * Mono downmix mirrors the dialogs' old multichannel graph, which panned every
 * channel dead-centre — each channel is summed into both speakers. The
 * AudioContext is created at the source sample rate so playback is sample-
 * accurate with no per-block resampling seams.
 *
 * Drive a media-less wavesurfer alongside it for the waveform + cursor: feed
 * `onTime` into `ws.setTime()` and translate wavesurfer's `interaction` event
 * (click-to-seek) into `seek()`.
 */

import { locateOnStitched, timeToByteOffset, sampleDecoder } from './wav.js';

// Window size for one read/decode/schedule cycle. Small enough that a seek is
// near-instant and a paused read returns quickly; large enough that the
// per-block scheduling overhead stays negligible.
const BLOCK_SEC = 0.4;
// Start the first block this far in the future so the producer has a moment to
// read and decode before the playhead reaches it.
const LEAD_SEC = 0.15;
// Keep roughly this many seconds scheduled ahead of the playhead.
const AHEAD_SEC = 2;
// How often the producer wakes to top up the scheduled-ahead buffer.
const PUMP_MS = 100;

/**
 * @typedef {object} TimelineDescriptor
 * @property {import('./wav.js').StitchedSource[]} sources
 * @property {{ sampleRate:number, channels:number, bitsPerSample:number, bytesPerFrame:number }} format
 * @property {number} totalDurationSeconds
 */

export class PcmStreamPlayer {
    /**
     * @param {TimelineDescriptor} descriptor
     * @param {{ onTime?:(t:number)=>void, onState?:(playing:boolean)=>void, onEnded?:()=>void }} [callbacks]
     */
    constructor(descriptor, { onTime, onState, onEnded } = {}) {
        this.d = descriptor;
        this.onTime = onTime;
        this.onState = onState;
        this.onEnded = onEnded;

        const Ctx = window.AudioContext || window.webkitAudioContext;
        const rate = descriptor.format.sampleRate;
        try {
            // Matching the context rate to the source avoids per-buffer
            // resampling, which would otherwise click at every block seam.
            this.ctx = new Ctx({ sampleRate: rate });
        } catch {
            // Some browsers refuse uncommon rates — fall back and let the
            // context resample the whole output stream smoothly.
            this.ctx = new Ctx();
        }
        this.master = this.ctx.createGain();
        this.master.gain.value = 1;
        this.master.connect(this.ctx.destination);

        this.playing = false;
        this.active = new Set();   // currently-scheduled AudioBufferSourceNodes
        this.pumping = false;
        this.pumpTimer = null;
        this.rafId = null;

        // `anchorSec` is the timeline position at audio-clock time
        // `anchorCtxTime`; the live playhead is derived from the gap between
        // them. `scheduleSec` is the next timeline second still to be read.
        this.anchorSec = 0;
        this.anchorCtxTime = 0;
        this.scheduleSec = 0;
        this.endLimit = descriptor.totalDurationSeconds;
        this.destroyed = false;
    }

    get total() {
        return this.d.totalDurationSeconds;
    }

    get isPlaying() {
        return this.playing;
    }

    get currentTime() {
        if (!this.playing) return this.anchorSec;
        const t = this.anchorSec + (this.ctx.currentTime - this.anchorCtxTime);
        return Math.max(0, Math.min(this.endLimit, t));
    }

    resume() {
        return this.ctx.resume?.();
    }

    /** Resume full-track playback from the current position. */
    play() {
        this.resume();
        const from = this.anchorSec >= this.total ? 0 : this.anchorSec;
        this._start(from, this.total);
    }

    /** Play just `[start, end)` (seconds), stopping at the end. */
    playRange(start, end) {
        this.resume();
        this._start(start, Math.min(end, this.total));
    }

    pause() {
        if (!this.playing) return;
        const at = this.currentTime;
        this._stopNodes();
        this._stopTimers();
        this.playing = false;
        this.anchorSec = at;
        this.scheduleSec = at;
        this.onState?.(false);
        this.onTime?.(at);
    }

    /** Move the playhead; if playing, resume from there as full-track playback. */
    seek(sec) {
        const target = Math.max(0, Math.min(sec, this.total));
        if (this.playing) {
            this._start(target, this.total);
        } else {
            this.anchorSec = target;
            this.scheduleSec = target;
            this.endLimit = this.total;
            this.onTime?.(target);
        }
    }

    destroy() {
        this.destroyed = true;
        this._stopNodes();
        this._stopTimers();
        this.playing = false;
        try { this.master.disconnect(); } catch {}
        this.ctx.close?.().catch(() => {});
    }

    // ---- internals -------------------------------------------------------

    _start(fromSec, endLimit) {
        this._stopNodes();
        this._stopTimers();
        this.anchorSec = Math.max(0, Math.min(fromSec, this.total));
        this.scheduleSec = this.anchorSec;
        this.endLimit = endLimit;
        this.anchorCtxTime = this.ctx.currentTime + LEAD_SEC;
        this.playing = true;
        this.onState?.(true);
        this._pump();
        this._startClock();
    }

    _stopNodes() {
        for (const node of this.active) {
            try { node.onended = null; node.stop(); node.disconnect(); } catch {}
        }
        this.active.clear();
    }

    _stopTimers() {
        if (this.pumpTimer) { clearTimeout(this.pumpTimer); this.pumpTimer = null; }
        if (this.rafId) { cancelAnimationFrame(this.rafId); this.rafId = null; }
    }

    // Producer loop: keep reading/decoding/scheduling blocks until we're
    // AHEAD_SEC in front of the playhead, then sleep and check again.
    async _pump() {
        if (this.pumping || !this.playing) return;
        this.pumping = true;
        const startGeneration = this.anchorCtxTime;
        try {
            while (this.playing && this.scheduleSec < this.endLimit) {
                if (this.scheduleSec - this.currentTime > AHEAD_SEC) break;

                const blockStart = this.scheduleSec;
                const blockEnd = Math.min(blockStart + BLOCK_SEC, this.endLimit);
                const mono = await readTimelineMonoBlock(this.d, blockStart, blockEnd);

                // A seek/pause/destroy during the await invalidates this read.
                if (!this.playing || this.destroyed || this.anchorCtxTime !== startGeneration) break;

                if (mono.length > 0) {
                    const buf = this.ctx.createBuffer(1, mono.length, this.d.format.sampleRate);
                    buf.copyToChannel(mono, 0);
                    const node = this.ctx.createBufferSource();
                    node.buffer = buf;
                    node.connect(this.master);
                    const when = this.anchorCtxTime + (blockStart - this.anchorSec);
                    node.start(when > this.ctx.currentTime ? when : this.ctx.currentTime);
                    this.active.add(node);
                    node.onended = () => { this.active.delete(node); try { node.disconnect(); } catch {} };
                }
                this.scheduleSec = blockEnd;
            }
        } finally {
            this.pumping = false;
        }
        if (this.playing && !this.destroyed) {
            this.pumpTimer = setTimeout(() => this._pump(), PUMP_MS);
        }
    }

    _startClock() {
        const tick = () => {
            if (!this.playing) return;
            const t = this.currentTime;
            this.onTime?.(t);
            if (t >= this.endLimit - 1e-3) {
                this._finish();
                return;
            }
            this.rafId = requestAnimationFrame(tick);
        };
        this.rafId = requestAnimationFrame(tick);
    }

    _finish() {
        this._stopNodes();
        this._stopTimers();
        this.playing = false;
        this.anchorSec = this.endLimit;
        this.scheduleSec = this.endLimit;
        this.onState?.(false);
        this.onTime?.(this.endLimit);
        this.onEnded?.();
    }
}

/**
 * Read `[startSec, endSec)` of the stitched timeline into a single mono
 * Float32Array (every source channel summed). Spans across source-file
 * boundaries transparently — the same walk `buildStitchedSegmentBlob` and the
 * encoder use. Pure `File.slice()` reads, so memory stays at one block.
 *
 * Exported for unit testing; not part of the player's public API.
 *
 * @returns {Promise<Float32Array>} length = round((endSec-startSec)*sampleRate)
 */
export async function readTimelineMonoBlock(descriptor, startSec, endSec) {
    const { sources, format } = descriptor;
    const { sampleRate, channels, bitsPerSample, bytesPerFrame } = format;
    const bytesPerSample = bitsPerSample / 8;
    const decode = sampleDecoder(bitsPerSample);

    const frames = Math.max(0, Math.round((endSec - startSec) * sampleRate));
    const out = new Float32Array(frames);
    if (frames === 0) return out;

    const begin = locateOnStitched(descriptor, startSec);
    const finish = locateOnStitched(descriptor, endSec);

    let outFrame = 0;
    for (let si = begin.sourceIndex; si <= finish.sourceIndex && outFrame < frames; si++) {
        const src = sources[si];
        const sStart = (si === begin.sourceIndex) ? begin.secondsWithinSource : 0;
        const sEnd = (si === finish.sourceIndex) ? finish.secondsWithinSource : src.header.durationSeconds;

        const startByte = timeToByteOffset(src.header, sStart);
        const endByte = timeToByteOffset(src.header, sEnd);
        if (endByte <= startByte) continue;

        const view = new DataView(await src.file.slice(startByte, endByte).arrayBuffer());
        const n = Math.floor(view.byteLength / bytesPerFrame);
        for (let f = 0; f < n && outFrame < frames; f++, outFrame++) {
            const base = f * bytesPerFrame;
            let sum = 0;
            for (let c = 0; c < channels; c++) sum += decode(view, base + c * bytesPerSample);
            out[outFrame] = sum;
        }
    }
    return out;
}
