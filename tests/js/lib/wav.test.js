import { describe, it, expect } from 'vitest';
import {
    readWavHeader,
    buildWavHeader,
    timeToByteOffset,
    buildSegmentBlob,
    readWavHeaders,
    locateOnStitched,
    buildStitchedSegmentBlob,
} from '@/lib/wav.js';

/**
 * Build a PCM WAV Blob whose data section is a recognizable byte sequence:
 * every frame's first byte equals `tag + frameIndex` mod 256. Lets the tests
 * assert that the right source bytes land in the right output positions.
 */
function makeWav({ name, durationSeconds, sampleRate = 8000, channels = 1, bitsPerSample = 16, tag = 0 }) {
    const bytesPerFrame = (bitsPerSample / 8) * channels;
    const frames = Math.round(sampleRate * durationSeconds);
    const dataLength = frames * bytesPerFrame;

    const hdr = buildWavHeader({ sampleRate, channels, bitsPerSample, dataLength });
    const pcm = new Uint8Array(dataLength);
    for (let i = 0; i < frames; i++) {
        pcm[i * bytesPerFrame] = (tag + i) & 0xff;
    }

    const blob = new Blob([hdr, pcm], { type: 'audio/wav' });
    // Mimic File.name so error messages can reference it.
    if (name) Object.defineProperty(blob, 'name', { value: name });
    return blob;
}

async function dataPortion(blob) {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    const dv = new DataView(bytes.buffer);
    const dataLength = dv.getUint32(40, true); // canonical 44-byte header
    return { bytes: bytes.subarray(44, 44 + dataLength), dataLength };
}

describe('readWavHeader', () => {
    it('parses sample rate, channel count and bit depth', async () => {
        const wav = makeWav({ durationSeconds: 1, sampleRate: 44100, channels: 2, bitsPerSample: 24 });
        const h = await readWavHeader(wav);
        expect(h.sampleRate).toBe(44100);
        expect(h.channels).toBe(2);
        expect(h.bitsPerSample).toBe(24);
        expect(h.bytesPerFrame).toBe(6);
        expect(h.dataOffset).toBe(44);
        expect(h.durationSeconds).toBeCloseTo(1, 5);
        expect(h.isRF64).toBe(false);
    });

    it('rejects non-WAV input', async () => {
        const junk = new Blob([new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])]);
        await expect(readWavHeader(junk)).rejects.toThrow(/RIFF|WAV/);
    });
});

describe('buildWavHeader', () => {
    it('emits a canonical 44-byte PCM header with the requested data length', () => {
        const buf = buildWavHeader({ sampleRate: 48000, channels: 1, bitsPerSample: 16, dataLength: 9600 });
        expect(buf.byteLength).toBe(44);
        const v = new DataView(buf);
        // 'RIFF', 'WAVE', 'fmt ', 'data' four-CCs at the canonical offsets
        expect(String.fromCharCode(v.getUint8(0), v.getUint8(1), v.getUint8(2), v.getUint8(3))).toBe('RIFF');
        expect(String.fromCharCode(v.getUint8(8), v.getUint8(9), v.getUint8(10), v.getUint8(11))).toBe('WAVE');
        expect(String.fromCharCode(v.getUint8(36), v.getUint8(37), v.getUint8(38), v.getUint8(39))).toBe('data');

        expect(v.getUint16(20, true)).toBe(1);              // PCM
        expect(v.getUint16(22, true)).toBe(1);              // channels
        expect(v.getUint32(24, true)).toBe(48000);          // sample rate
        expect(v.getUint16(34, true)).toBe(16);             // bits per sample
        expect(v.getUint32(40, true)).toBe(9600);           // data length
        expect(v.getUint32(4, true)).toBe(36 + 9600);       // RIFF size
    });
});

describe('timeToByteOffset', () => {
    it('snaps to frame boundaries', async () => {
        const wav = makeWav({ durationSeconds: 1, sampleRate: 8000, channels: 2, bitsPerSample: 16 });
        const h = await readWavHeader(wav);
        // 2ch * 16-bit = 4 bytes/frame; 0.5s = 4000 frames * 4 = 16000 bytes past data offset
        expect(timeToByteOffset(h, 0.5)).toBe(h.dataOffset + 16000);
        expect(timeToByteOffset(h, 0)).toBe(h.dataOffset);
    });

    it('clamps to the end of the data chunk', async () => {
        const wav = makeWav({ durationSeconds: 1, sampleRate: 8000 });
        const h = await readWavHeader(wav);
        expect(timeToByteOffset(h, 99)).toBe(h.dataOffset + h.dataLength);
    });
});

describe('buildSegmentBlob (single file)', () => {
    it('emits a fresh header and the requested PCM slice', async () => {
        const wav = makeWav({ durationSeconds: 1, sampleRate: 8000, tag: 0 });
        const h = await readWavHeader(wav);
        const seg = buildSegmentBlob(wav, h, { start: 0.25, end: 0.75 });
        const { bytes, dataLength } = await dataPortion(seg);
        // 0.5s * 8000 fr/s * 2 bytes/fr = 8000 bytes
        expect(dataLength).toBe(8000);
        // First frame in the output is the 2000th frame of the source: tag(0) + 2000 = 208 (mod 256)
        expect(bytes[0]).toBe(2000 & 0xff);
    });
});

describe('readWavHeaders', () => {
    it('stitches multiple files into a virtual timeline', async () => {
        const a = makeWav({ name: 'a.wav', durationSeconds: 2 });
        const b = makeWav({ name: 'b.wav', durationSeconds: 3 });
        const stitched = await readWavHeaders([a, b]);
        expect(stitched.totalDurationSeconds).toBeCloseTo(5, 5);
        expect(stitched.sources.length).toBe(2);
        expect(stitched.sources[0].startSeconds).toBe(0);
        expect(stitched.sources[1].startSeconds).toBeCloseTo(2, 5);
        expect(stitched.format).toMatchObject({ sampleRate: 8000, channels: 1, bitsPerSample: 16 });
    });

    it('throws naming the offending file on format mismatch', async () => {
        const a = makeWav({ name: 'a.wav', durationSeconds: 1, sampleRate: 8000 });
        const c = makeWav({ name: 'c.wav', durationSeconds: 1, sampleRate: 44100 });
        await expect(readWavHeaders([a, c])).rejects.toThrow(/c\.wav.*doesn't match/i);
    });

    it('rejects empty input', async () => {
        await expect(readWavHeaders([])).rejects.toThrow(/No files/);
    });
});

describe('locateOnStitched', () => {
    it('maps points inside each source to per-source offsets', async () => {
        const a = makeWav({ durationSeconds: 2 });
        const b = makeWav({ durationSeconds: 3 });
        const stitched = await readWavHeaders([a, b]);
        expect(locateOnStitched(stitched, 0.5)).toEqual({ sourceIndex: 0, secondsWithinSource: 0.5 });
        expect(locateOnStitched(stitched, 2.5)).toMatchObject({ sourceIndex: 1 });
        expect(locateOnStitched(stitched, 2.5).secondsWithinSource).toBeCloseTo(0.5, 5);
    });

    it('clamps out-of-range inputs to the nearest edge', async () => {
        const a = makeWav({ durationSeconds: 2 });
        const stitched = await readWavHeaders([a]);
        expect(locateOnStitched(stitched, -1)).toEqual({ sourceIndex: 0, secondsWithinSource: 0 });
        expect(locateOnStitched(stitched, 99).sourceIndex).toBe(0);
        expect(locateOnStitched(stitched, 99).secondsWithinSource).toBeCloseTo(2, 5);
    });
});

describe('buildStitchedSegmentBlob', () => {
    it('returns a header-only blob for an empty or inverted region', async () => {
        const a = makeWav({ durationSeconds: 1 });
        const stitched = await readWavHeaders([a]);
        const out = buildStitchedSegmentBlob(stitched, { start: 1, end: 1 });
        const { dataLength } = await dataPortion(out);
        expect(dataLength).toBe(0);
        expect(out.size).toBe(44);
    });

    it('produces a contiguous slice for a region inside one source', async () => {
        const a = makeWav({ durationSeconds: 1, tag: 10 });
        const stitched = await readWavHeaders([a]);
        const out = buildStitchedSegmentBlob(stitched, { start: 0.25, end: 0.75 });
        const { bytes, dataLength } = await dataPortion(out);
        expect(dataLength).toBe(8000); // 0.5s * 8000 * 2
        // Source byte 0 = tag(10); 2000 frames in = 10 + 2000 = 2010 → mod 256
        expect(bytes[0]).toBe(2010 & 0xff);
    });

    it('refuses a region whose PCM would overflow the 32-bit RIFF size field', () => {
        // Skip the 4 GB allocation — construct a stitched object directly with
        // a header whose dataLength claims 5 GB. timeToByteOffset reads the
        // header at face value; Blob.slice clamps to the real (tiny) blob.
        const sampleRate = 48000;
        const channels = 8;
        const bitsPerSample = 24;
        const bytesPerFrame = (bitsPerSample / 8) * channels; // 24
        const fakeDataLength = 5 * 1024 ** 3; // 5 GiB
        const fakeDurationSeconds = fakeDataLength / bytesPerFrame / sampleRate;

        const tiny = new Blob([new Uint8Array(64)]);
        const fakeHeader = {
            sampleRate, channels, bitsPerSample, bytesPerFrame,
            dataOffset: 44,
            dataLength: fakeDataLength,
            durationSeconds: fakeDurationSeconds,
            isRF64: false,
        };
        const stitched = {
            sources: [{ file: tiny, header: fakeHeader, startSeconds: 0, endSeconds: fakeDurationSeconds }],
            format: { sampleRate, channels, bitsPerSample, bytesPerFrame },
            totalDurationSeconds: fakeDurationSeconds,
        };

        expect(() => buildStitchedSegmentBlob(stitched, { start: 0, end: fakeDurationSeconds }))
            .toThrow(/4 GB|exceeds/i);
    });

    it('stitches across a source boundary in order', async () => {
        const a = makeWav({ name: 'a.wav', durationSeconds: 2, tag: 0 });   // bytes: 0,1,2,...
        const b = makeWav({ name: 'b.wav', durationSeconds: 3, tag: 100 }); // bytes: 100,101,...
        const stitched = await readWavHeaders([a, b]);

        // Region [1.5, 4.0): 0.5s tail of a (frames 12000..16000), then 2.0s head of b (frames 0..16000).
        // Frame size = 2 bytes => 0.5*8000*2 + 2*8000*2 = 8000 + 32000 = 40000 bytes.
        const out = buildStitchedSegmentBlob(stitched, { start: 1.5, end: 4.0 });
        const { bytes, dataLength } = await dataPortion(out);
        expect(dataLength).toBe(40000);

        // First frame of output is frame 12000 of a → byte tag(0)+12000 mod 256
        expect(bytes[0]).toBe(12000 & 0xff);
        // The boundary frame (first frame of b) lands at output frame 4000 → byte offset 8000
        expect(bytes[8000]).toBe(100 & 0xff);
    });
});
