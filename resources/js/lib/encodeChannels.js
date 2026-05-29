/**
 * De-interleave a multi-channel WAV (or a region of a stitched timeline) into
 * one mono Opus stream per channel, computing a peaks envelope per channel in
 * the same single PCM pass. The source is read in chunks via File.slice(), so
 * multi-GB inputs stay out of memory; the only things that grow are the small
 * per-channel peaks arrays and the encoders' internal queues (bounded by
 * backpressure below).
 *
 * Output shape matches what the upload + player expect: each channel yields a
 * WebM/Opus Blob and a flat [max, min, max, min, …] peaks array in [-1, 1].
 */

import { readWavHeaders, locateOnStitched, sampleDecoder } from './wav.js';
import { MonoOpusEncoder } from './opusEncode.js';

const PEAK_BUCKETS = 4000;
const ENCODE_BLOCK_FRAMES = 8192; // submit to the encoder in blocks, not per-sample
const CHUNK_BYTES = 1 << 22;      // 4 MB reads
const MAX_QUEUE = 24;             // pause feeding when an encoder is this far behind

const round4 = (n) => Math.round(n * 10000) / 10000;

/**
 * @typedef {object} EncodedChannels
 * @property {number} sampleRate
 * @property {number} channelCount
 * @property {number} durationSeconds
 * @property {Array<{ blob: Blob, peaks: number[] }>} channels  in channel order
 */

/**
 * Encode every channel of a single multi-channel WAV File.
 *
 * @param {File|Blob} file
 * @param {{ bitrate?: number, onProgress?: (p:number)=>void }} [opts]
 * @returns {Promise<EncodedChannels>}
 */
export async function encodeWavChannels(file, opts = {}) {
    const stitched = await readWavHeaders([file]);
    return encodeStitchedRegionChannels(stitched, { start: 0, end: stitched.totalDurationSeconds }, opts);
}

/**
 * Encode a `[start, end)` region (seconds) of a stitched multi-file timeline.
 * A region may span more than one source file; we walk the sources in order
 * and feed the de-interleaved samples straight through one set of encoders so
 * the boundary is seamless.
 *
 * @param {import('./wav.js').Stitched} stitched
 * @param {{ start: number, end: number }} region
 * @param {{ bitrate?: number, onProgress?: (p:number)=>void }} [opts]
 * @returns {Promise<EncodedChannels>}
 */
export async function encodeStitchedRegionChannels(stitched, region, opts = {}) {
    const { bitrate = 96_000, onProgress } = opts;
    const { format, sources } = stitched;
    const { sampleRate, channels, bitsPerSample, bytesPerFrame } = format;
    const bytesPerSample = bitsPerSample / 8;
    const decode = sampleDecoder(bitsPerSample);

    const begin = locateOnStitched(stitched, region.start);
    const finish = locateOnStitched(stitched, region.end);

    const totalFrames = Math.max(1, Math.round((region.end - region.start) * sampleRate));
    const framesPerBucket = Math.max(1, Math.ceil(totalFrames / PEAK_BUCKETS));

    const encoders = Array.from({ length: channels }, () => new MonoOpusEncoder({ sampleRate, bitrate }));

    // Per-channel peaks accumulators and a reusable submission block per channel.
    const peaks = Array.from({ length: channels }, () => []);
    const accMax = new Array(channels).fill(-Infinity);
    const accMin = new Array(channels).fill(Infinity);
    const blocks = Array.from({ length: channels }, () => new Float32Array(ENCODE_BLOCK_FRAMES));
    let blockLen = 0;
    let bucketFrame = 0;
    let processedFrames = 0;

    const flushBlock = async () => {
        if (blockLen === 0) return;
        for (let c = 0; c < channels; c++) encoders[c].encode(blocks[c].subarray(0, blockLen));
        blockLen = 0;
        // Backpressure: let the encoders drain before reading more PCM so the
        // internal queues (and their retained AudioData) stay bounded.
        while (encoders.some((e) => e.queueSize > MAX_QUEUE)) {
            await new Promise((r) => setTimeout(r, 4));
        }
    };

    for (let si = begin.sourceIndex; si <= finish.sourceIndex; si++) {
        const src = sources[si];
        const startSec = (si === begin.sourceIndex) ? begin.secondsWithinSource : 0;
        const endSec = (si === finish.sourceIndex) ? finish.secondsWithinSource : src.header.durationSeconds;

        const startFrame = Math.round(startSec * src.header.sampleRate);
        const endFrame = Math.round(endSec * src.header.sampleRate);
        let cursor = src.header.dataOffset + startFrame * bytesPerFrame;
        const dataEnd = src.header.dataOffset + endFrame * bytesPerFrame;

        while (cursor < dataEnd) {
            const end = Math.min(cursor + CHUNK_BYTES, dataEnd);
            const view = new DataView(await src.file.slice(cursor, end).arrayBuffer());
            const frames = Math.floor(view.byteLength / bytesPerFrame);

            for (let f = 0; f < frames; f++) {
                const base = f * bytesPerFrame;
                for (let c = 0; c < channels; c++) {
                    const s = decode(view, base + c * bytesPerSample);
                    blocks[c][blockLen] = s;
                    if (s > accMax[c]) accMax[c] = s;
                    if (s < accMin[c]) accMin[c] = s;
                }
                blockLen++;
                bucketFrame++;

                if (bucketFrame >= framesPerBucket) {
                    for (let c = 0; c < channels; c++) {
                        peaks[c].push(round4(accMax[c]), round4(accMin[c]));
                        accMax[c] = -Infinity;
                        accMin[c] = Infinity;
                    }
                    bucketFrame = 0;
                }

                if (blockLen === ENCODE_BLOCK_FRAMES) {
                    await flushBlock();
                }
            }

            cursor += frames * bytesPerFrame;
            processedFrames += frames;
            onProgress?.(Math.min(1, processedFrames / totalFrames));
            if (frames === 0) break;
        }
    }

    await flushBlock();
    // Trailing partial bucket so the region's tail isn't dropped.
    if (bucketFrame > 0) {
        for (let c = 0; c < channels; c++) {
            peaks[c].push(round4(accMax[c] === -Infinity ? 0 : accMax[c]), round4(accMin[c] === Infinity ? 0 : accMin[c]));
        }
    }

    const blobs = await Promise.all(encoders.map((e) => e.finish()));
    onProgress?.(1);

    return {
        sampleRate,
        channelCount: channels,
        durationSeconds: processedFrames / sampleRate,
        channels: blobs.map((blob, c) => ({ blob, peaks: peaks[c] })),
    };
}
