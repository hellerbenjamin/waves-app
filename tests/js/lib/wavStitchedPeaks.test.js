import { describe, it, expect } from 'vitest';
import { buildWavHeader, readWavHeaders } from '@/lib/wav.js';
import { scanStitchedPeaks } from '@/lib/wavStitchedPeaks.js';

/**
 * Build a 16-bit mono WAV Blob whose PCM is a function of frame index. The
 * caller picks the function, so each test can place a known maximum at a known
 * frame and assert it round-trips through the bucket envelope.
 *
 * @param {object} o
 * @param {number} o.durationSeconds
 * @param {number} [o.sampleRate=8000]
 * @param {(frameIndex:number)=>number} o.sampleAt  signed 16-bit value, [-32768, 32767]
 */
function makePcmWav({ durationSeconds, sampleRate = 8000, sampleAt }) {
    const channels = 1;
    const bitsPerSample = 16;
    const bytesPerFrame = 2;
    const frames = Math.round(sampleRate * durationSeconds);
    const dataLength = frames * bytesPerFrame;

    const hdr = buildWavHeader({ sampleRate, channels, bitsPerSample, dataLength });
    const pcm = new Uint8Array(dataLength);
    const view = new DataView(pcm.buffer);
    for (let i = 0; i < frames; i++) {
        const v = Math.max(-32768, Math.min(32767, Math.round(sampleAt(i))));
        view.setInt16(i * bytesPerFrame, v, true);
    }
    return new Blob([hdr, pcm], { type: 'audio/wav' });
}

describe('scanStitchedPeaks', () => {
    it('returns an interleaved [max,min,...] Float32Array within [-1, 1]', async () => {
        // Two 0.5s files of low-amplitude noise; we don't care about exact peaks
        // here, only the output shape and value range.
        const a = makePcmWav({ durationSeconds: 0.5, sampleAt: (i) => (i % 7) * 100 });
        const b = makePcmWav({ durationSeconds: 0.5, sampleAt: (i) => -((i % 5) * 200) });
        const stitched = await readWavHeaders([a, b]);
        const { peaks } = await scanStitchedPeaks(stitched, { peakStrides: 50 });

        expect(peaks).toBeInstanceOf(Float32Array);
        expect(peaks.length % 2).toBe(0);
        expect(peaks.length).toBeGreaterThan(0);

        for (let i = 0; i < peaks.length; i += 2) {
            const max = peaks[i];
            const min = peaks[i + 1];
            expect(max).toBeGreaterThanOrEqual(min);
            expect(max).toBeLessThanOrEqual(1);
            expect(min).toBeGreaterThanOrEqual(-1);
        }
    });

    it('surfaces peaks from both source files in the output envelope', async () => {
        // Two files with everything at ~zero except a single loud spike in
        // each — file A near the start (+0.9), file B near the end (-0.9).
        const SR = 8000;
        const spikeA = 1000;          // ~0.125s into A
        const spikeB = 0.5 * SR + 3000; // global frame index of spike in B
        const a = makePcmWav({
            durationSeconds: 0.5,
            sampleRate: SR,
            sampleAt: (i) => (i === spikeA ? Math.round(0.9 * 32767) : 0),
        });
        const b = makePcmWav({
            durationSeconds: 0.5,
            sampleRate: SR,
            sampleAt: (i) => (i + 0.5 * SR === spikeB ? -Math.round(0.9 * 32767) : 0),
        });
        const stitched = await readWavHeaders([a, b]);
        const { peaks } = await scanStitchedPeaks(stitched, { peakStrides: 100, windowMs: 20 });

        let globalMax = -Infinity;
        let globalMin = Infinity;
        for (let i = 0; i < peaks.length; i += 2) {
            if (peaks[i] > globalMax) globalMax = peaks[i];
            if (peaks[i + 1] < globalMin) globalMin = peaks[i + 1];
        }

        // ~0.9 with a hair of float rounding from int16 quantisation.
        expect(globalMax).toBeGreaterThan(0.85);
        expect(globalMin).toBeLessThan(-0.85);
    });

    it('threads the bucket accumulator across the source boundary', async () => {
        // Place a loud spike *just before* the end of file A and another just
        // *after* the start of file B. If the bucket counter were reset at the
        // boundary, these two would land in separate buckets and you'd see two
        // adjacent loud buckets. With threaded accumulation they collapse into
        // a single bucket carrying both extremes.
        const SR = 8000;
        const peakStrides = 20;
        const a = makePcmWav({
            durationSeconds: 0.5,
            sampleRate: SR,
            // Spike at the last frame of A.
            sampleAt: (i) => (i === 0.5 * SR - 1 ? Math.round(0.95 * 32767) : 0),
        });
        const b = makePcmWav({
            durationSeconds: 0.5,
            sampleRate: SR,
            // Negative spike at the first frame of B.
            sampleAt: (i) => (i === 0 ? -Math.round(0.95 * 32767) : 0),
        });
        const stitched = await readWavHeaders([a, b]);
        const { peaks } = await scanStitchedPeaks(stitched, { peakStrides, windowMs: 20 });

        // Find the bucket containing the maximum and inspect its companion min.
        let argmax = 0;
        for (let i = 2; i < peaks.length; i += 2) {
            if (peaks[i] > peaks[argmax]) argmax = i;
        }
        const max = peaks[argmax];
        const min = peaks[argmax + 1];
        expect(max).toBeGreaterThan(0.9);
        // Same bucket should also capture the negative spike from B.
        expect(min).toBeLessThan(-0.9);
    });

    it('reports progress monotonically and finishes at 1', async () => {
        const a = makePcmWav({ durationSeconds: 0.25, sampleAt: () => 0 });
        const b = makePcmWav({ durationSeconds: 0.25, sampleAt: () => 0 });
        const stitched = await readWavHeaders([a, b]);

        const progress = [];
        await scanStitchedPeaks(stitched, {
            peakStrides: 20,
            onProgress: (p) => progress.push(p),
        });

        expect(progress.length).toBeGreaterThan(0);
        for (let i = 1; i < progress.length; i++) {
            expect(progress[i]).toBeGreaterThanOrEqual(progress[i - 1] - 1e-9);
        }
        expect(progress[progress.length - 1]).toBe(1);
    });

    it('returns an empty envelope for an empty stitched input', async () => {
        const empty = { sources: [], format: { sampleRate: 8000, channels: 1, bitsPerSample: 16, bytesPerFrame: 2 }, totalDurationSeconds: 0 };
        const { peaks } = await scanStitchedPeaks(empty);
        expect(peaks.length).toBe(0);
    });
});
