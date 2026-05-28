import { apiFetch } from './useS3Upload.js';
import { encodeWavChannels, encodeStitchedRegionChannels } from '@/lib/encodeChannels.js';

/**
 * The browser-side ingestion path: encode a recording's channels to mono Opus
 * locally, upload each channel's Opus + peaks straight to the bucket, then
 * finalise the set into a Track. The source WAV never leaves the machine.
 *
 * Two entry points share the upload half:
 *   - uploadWavFile(file, …)        a single multi-channel WAV
 *   - uploadStitchedRegion(s, r, …) one song region of a stitched timeline
 *
 * Progress is reported as a single 0..1 value spanning encode (first ~70%)
 * and upload (last ~30%) so a caller can render one bar per track.
 */
export function useChannelUpload() {
    const uploadWavFile = (file, meta = {}) =>
        run(meta, (onProgress) => encodeWavChannels(file, { onProgress }), file.name, meta);

    const uploadStitchedRegion = (stitched, region, meta = {}) =>
        run(meta, (onProgress) => encodeStitchedRegionChannels(stitched, region, { onProgress }), meta.name, meta);

    return { uploadWavFile, uploadStitchedRegion };
}

/**
 * @param {object} meta            { name, eventId?, onProgress? }
 * @param {(onProgress) => Promise<import('@/lib/encodeChannels.js').EncodedChannels>} encode
 * @param {string} fallbackName    used when meta.name is absent
 */
async function run(meta, encode, fallbackName) {
    const { eventId = null, onProgress } = meta;
    const name = (meta.name || fallbackName || 'Recording').replace(/\.[^.]+$/, '');

    // Encode is the long part; weight it as the first 70% of the bar.
    const encoded = await encode((p) => onProgress?.(p * 0.7));

    const uploaded = [];
    try {
        const init = await apiFetch(route('tracks.channels.init'), {
            method: 'POST',
            body: { channels: encoded.channelCount },
        });
        if (!init.ok) throw new Error(`init failed (${init.status})`);
        const { targets } = await init.json();

        const steps = encoded.channelCount * 2;
        let done = 0;
        const manifest = [];

        for (let i = 0; i < encoded.channelCount; i++) {
            const target = targets[i];
            const { blob, peaks } = encoded.channels[i];

            await putToTarget(target.opus, blob);
            uploaded.push(target.opus_key);
            onProgress?.(0.7 + (++done / steps) * 0.3);

            const peaksBlob = new Blob(
                [JSON.stringify({ sample_rate: encoded.sampleRate, peaks })],
                { type: 'application/json' },
            );
            await putToTarget(target.peaks, peaksBlob);
            uploaded.push(target.peaks_key);
            onProgress?.(0.7 + (++done / steps) * 0.3);

            manifest.push({
                index: target.index,
                opus_key: target.opus_key,
                peaks_key: target.peaks_key,
                size: blob.size,
                label: null,
            });
        }

        const store = await apiFetch(route('tracks.store'), {
            method: 'POST',
            body: {
                original_name: name,
                event_id: eventId,
                duration_seconds: encoded.durationSeconds,
                sample_rate: encoded.sampleRate,
                channels: manifest,
            },
        });
        if (!store.ok) throw new Error(`finalise failed (${store.status})`);

        onProgress?.(1);
    } catch (err) {
        // Best-effort: drop any objects we uploaded before failing so a
        // half-finished track doesn't leak storage.
        for (const key of uploaded) {
            apiFetch(route('tracks.cleanup'), { method: 'POST', body: { key } }).catch(() => {});
        }
        throw err;
    }
}

/**
 * PUT a blob to a presigned target. On S3 the URL is a presigned bucket PUT
 * with signed headers; on a local disk it's a signed app route that streams
 * the body to disk (no special headers).
 */
async function putToTarget(target, blob) {
    const res = await fetch(target.url, {
        method: 'PUT',
        headers: target.headers || {},
        body: blob,
    });
    if (!res.ok) throw new Error(`upload failed (${res.status})`);
}
