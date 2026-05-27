/**
 * Stream a multi-channel WAV File and emit one mono WAV Blob per channel.
 * Used only by the throwaway sync-test page to feed N parallel `<audio>`
 * elements from a single source recording — production splitting would do
 * this server-side as part of the transcode pipeline.
 *
 * PCM in a WAV is frame-interleaved (`[ch0, ch1, …, chN-1, ch0, ch1, …]`),
 * so we walk the source once, distributing each sample's bytes into a
 * per-channel output buffer; the alternative (one pass per channel) costs
 * `channels`× more disk I/O.
 *
 * Caller-controlled `maxSeconds` clips the output so a 4 GB source doesn't
 * blow up the per-channel buffers when the test only needs a few minutes.
 */

import { buildWavHeader, readWavHeader } from './wav.js';

/**
 * @param {File|Blob} file
 * @param {{ maxSeconds?: number, onProgress?: (p:number)=>void }} [opts]
 * @returns {Promise<{ header: import('./wav.js').WavHeader, channels: Blob[] }>}
 */
export async function splitChannelsToMonoWavs(file, opts = {}) {
    const { maxSeconds = null, onProgress } = opts;

    const header = await readWavHeader(file);
    const { sampleRate, channels, bitsPerSample, bytesPerFrame, dataOffset, dataLength } = header;
    const bytesPerSample = bitsPerSample / 8;

    const totalFrames = Math.floor(dataLength / bytesPerFrame);
    const clipFrames = maxSeconds != null
        ? Math.min(totalFrames, Math.floor(maxSeconds * sampleRate))
        : totalFrames;
    const outBytes = clipFrames * bytesPerSample;

    // One output Uint8Array per channel; we know the exact size up front.
    const outBufs = Array.from({ length: channels }, () => new Uint8Array(outBytes));
    const outOffsets = new Array(channels).fill(0);

    const CHUNK_BYTES = 1 << 22; // 4 MB
    const dataEnd = dataOffset + clipFrames * bytesPerFrame;
    let cursor = dataOffset;
    let processedFrames = 0;

    while (cursor < dataEnd) {
        const end = Math.min(cursor + CHUNK_BYTES, dataEnd);
        const chunk = new Uint8Array(await file.slice(cursor, end).arrayBuffer());
        const wholeFrames = Math.min(
            Math.floor(chunk.byteLength / bytesPerFrame),
            clipFrames - processedFrames,
        );

        for (let f = 0; f < wholeFrames; f++) {
            const frameOff = f * bytesPerFrame;
            for (let c = 0; c < channels; c++) {
                const inOff = frameOff + c * bytesPerSample;
                const outOff = outOffsets[c];
                const out = outBufs[c];
                // Copy bytesPerSample bytes; unrolled for the common widths.
                if (bytesPerSample === 2) {
                    out[outOff] = chunk[inOff];
                    out[outOff + 1] = chunk[inOff + 1];
                } else if (bytesPerSample === 3) {
                    out[outOff] = chunk[inOff];
                    out[outOff + 1] = chunk[inOff + 1];
                    out[outOff + 2] = chunk[inOff + 2];
                } else {
                    for (let b = 0; b < bytesPerSample; b++) {
                        out[outOff + b] = chunk[inOff + b];
                    }
                }
                outOffsets[c] += bytesPerSample;
            }
        }

        cursor += wholeFrames * bytesPerFrame;
        processedFrames += wholeFrames;
        onProgress?.(processedFrames / clipFrames);

        if (wholeFrames === 0) break;
    }

    const monoBlobs = outBufs.map((buf) => {
        const hdr = buildWavHeader({
            sampleRate, channels: 1, bitsPerSample, dataLength: buf.byteLength,
        });
        return new Blob([hdr, buf], { type: 'audio/wav' });
    });

    return { header, channels: monoBlobs };
}
