import { describe, it, expect } from 'vitest';
import { buildWavHeader, readWavHeaders } from '@/lib/wav.js';
import { readTimelineMonoBlock } from '@/lib/pcmStreamPlayer.js';

/**
 * Build a PCM WAV Blob. `fill(frameIndex, channel)` returns the 16-bit sample
 * value to write, so a test can craft distinct per-channel content. Only
 * 16-bit PCM is used here — enough to exercise the decode + channel-sum path.
 */
function makeWav({ name, durationSeconds, sampleRate = 8000, channels = 1, fill }) {
    const bitsPerSample = 16;
    const bytesPerFrame = (bitsPerSample / 8) * channels;
    const frames = Math.round(sampleRate * durationSeconds);
    const dataLength = frames * bytesPerFrame;

    const hdr = buildWavHeader({ sampleRate, channels, bitsPerSample, dataLength });
    const pcm = new Uint8Array(dataLength);
    const dv = new DataView(pcm.buffer);
    for (let i = 0; i < frames; i++) {
        for (let c = 0; c < channels; c++) {
            dv.setInt16((i * channels + c) * 2, fill(i, c), true);
        }
    }

    const blob = new Blob([hdr, pcm], { type: 'audio/wav' });
    if (name) Object.defineProperty(blob, 'name', { value: name });
    return blob;
}

describe('readTimelineMonoBlock', () => {
    it('decodes a region of a single mono file to normalized floats', async () => {
        // Frame i holds value i (so each sample is i/32768).
        const wav = makeWav({ durationSeconds: 1, sampleRate: 8000, channels: 1, fill: (i) => i });
        const stitched = await readWavHeaders([wav]);

        const block = await readTimelineMonoBlock(stitched, 0.25, 0.75);
        expect(block.length).toBe(4000); // 0.5s * 8000
        // First output frame is source frame 2000.
        expect(block[0]).toBeCloseTo(2000 / 32768, 6);
        expect(block[1]).toBeCloseTo(2001 / 32768, 6);
    });

    it('sums every channel into the mono output', async () => {
        // ch0 = 100, ch1 = 300 on every frame → mono sum = 400/32768.
        const wav = makeWav({
            durationSeconds: 0.5, sampleRate: 8000, channels: 2,
            fill: (i, c) => (c === 0 ? 100 : 300),
        });
        const stitched = await readWavHeaders([wav]);

        const block = await readTimelineMonoBlock(stitched, 0, 0.25);
        expect(block.length).toBe(2000);
        expect(block[0]).toBeCloseTo(400 / 32768, 6);
        expect(block[1999]).toBeCloseTo(400 / 32768, 6);
    });

    it('reads seamlessly across a source-file boundary', async () => {
        // a: frames hold value 1; b: frames hold value 2.
        const a = makeWav({ name: 'a.wav', durationSeconds: 2, fill: () => 1 });
        const b = makeWav({ name: 'b.wav', durationSeconds: 2, fill: () => 2 });
        const stitched = await readWavHeaders([a, b]);

        // Region [1.5, 2.5): 0.5s tail of a, then 0.5s head of b.
        const block = await readTimelineMonoBlock(stitched, 1.5, 2.5);
        expect(block.length).toBe(8000); // 1.0s * 8000
        expect(block[0]).toBeCloseTo(1 / 32768, 6);          // still in a
        expect(block[3999]).toBeCloseTo(1 / 32768, 6);       // last frame of a's tail
        expect(block[4000]).toBeCloseTo(2 / 32768, 6);       // first frame of b
        expect(block[7999]).toBeCloseTo(2 / 32768, 6);
    });

    it('returns an empty buffer for a zero-length region', async () => {
        const wav = makeWav({ durationSeconds: 1, fill: (i) => i });
        const stitched = await readWavHeaders([wav]);
        const block = await readTimelineMonoBlock(stitched, 0.5, 0.5);
        expect(block.length).toBe(0);
    });
});
