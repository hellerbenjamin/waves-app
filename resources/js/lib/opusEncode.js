/**
 * Encode a stream of mono PCM into a WebM/Opus Blob in the browser, using the
 * native WebCodecs AudioEncoder (no WASM) plus webm-muxer to wrap the encoded
 * packets into a playable container. Uploads ship these Blobs straight to the
 * bucket — the source WAV never leaves the machine.
 *
 * WebCodecs is desktop Chrome/Edge/Firefox + Safari 16.4+. We gate uploads to
 * desktop, so that's covered; isOpusEncodeSupported() lets callers surface a
 * clear message anywhere it isn't.
 */

import { Muxer, ArrayBufferTarget } from 'webm-muxer';

/** True if the browser can encode Opus via WebCodecs. */
export function isOpusEncodeSupported() {
    return typeof window !== 'undefined'
        && typeof window.AudioEncoder === 'function'
        && typeof window.AudioData === 'function';
}

/**
 * Streaming mono-Opus encoder. Feed it Float32 PCM blocks in order; call
 * finish() to flush and get the WebM Blob. One instance per channel.
 *
 * AudioData timestamps are microseconds and must be monotonic; we derive them
 * from the running sample count so gaps/overlaps can't creep in.
 */
export class MonoOpusEncoder {
    /**
     * @param {{ sampleRate: number, bitrate?: number }} opts
     */
    constructor({ sampleRate, bitrate = 96_000 }) {
        if (!isOpusEncodeSupported()) {
            throw new Error('This browser cannot encode Opus (WebCodecs unavailable).');
        }

        this.sampleRate = sampleRate;
        this.framesSubmitted = 0;
        this.error = null;

        this.muxer = new Muxer({
            target: new ArrayBufferTarget(),
            audio: { codec: 'A_OPUS', numberOfChannels: 1, sampleRate },
            // We hand the muxer real (already monotonic-from-zero) timestamps,
            // so 'strict' keeps it honest rather than silently shifting them.
            firstTimestampBehavior: 'strict',
        });

        this.encoder = new AudioEncoder({
            output: (chunk, meta) => this.muxer.addAudioChunk(chunk, meta),
            error: (e) => { this.error = e; },
        });

        this.encoder.configure({
            codec: 'opus',
            sampleRate,
            numberOfChannels: 1,
            bitrate,
        });
    }

    /**
     * Encode one block of mono samples. The Float32Array is consumed
     * immediately (copied into an AudioData), so the caller may reuse it.
     *
     * @param {Float32Array} samples
     */
    encode(samples) {
        if (this.error) throw this.error;
        if (samples.length === 0) return;

        const timestamp = Math.round((this.framesSubmitted / this.sampleRate) * 1_000_000);
        const data = new AudioData({
            format: 'f32',
            sampleRate: this.sampleRate,
            numberOfFrames: samples.length,
            numberOfChannels: 1,
            timestamp,
            // Copy: AudioData takes ownership of the buffer it's given, and the
            // caller's chunk buffer is reused across reads.
            data: samples.slice(),
        });
        this.encoder.encode(data);
        data.close();
        this.framesSubmitted += samples.length;
    }

    /** Pending frames the encoder hasn't processed yet — used for backpressure. */
    get queueSize() {
        return this.encoder.encodeQueueSize;
    }

    /**
     * Flush the encoder, finalize the container, and return the WebM Blob.
     * The instance can't be reused afterward.
     *
     * @returns {Promise<Blob>}
     */
    async finish() {
        await this.encoder.flush();
        if (this.error) throw this.error;
        this.encoder.close();
        this.muxer.finalize();
        const { buffer } = this.muxer.target;
        return new Blob([buffer], { type: 'audio/webm' });
    }
}
