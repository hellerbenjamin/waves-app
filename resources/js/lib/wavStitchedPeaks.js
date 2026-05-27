/**
 * Compute a single peaks envelope spanning a virtual stitched WAV timeline
 * (a sequence of source files treated as one continuous recording). The output
 * is a flat Float32Array of [max, min, max, min, ...] in [-1, 1], shaped for
 * wavesurfer's `peaks` option. Source PCM is streamed in chunks via
 * `File.slice()`, so multi-GB inputs stay out of memory.
 *
 * The stride accumulator threads across source boundaries — the bucket that
 * straddles two files is exactly one bucket wide on the rendered waveform,
 * not two stitched halves.
 */

/**
 * @typedef {object} StitchedPeaksResult
 * @property {Float32Array} peaks           Interleaved [max,min, max,min, …] in [-1, 1].
 * @property {number}       framesPerWindow Per-source window length used for the inner max/min scan.
 * @property {number}       windowMs        Echoed back so callers know what window was used.
 */

/**
 * @param {import('./wav.js').Stitched} stitched
 * @param {{ windowMs?: number, peakStrides?: number, onProgress?: (p:number)=>void }} [opts]
 * @returns {Promise<StitchedPeaksResult>}
 */
export async function scanStitchedPeaks(stitched, opts = {}) {
    const { windowMs = 20, peakStrides = 4000, onProgress } = opts;
    const { sources, format } = stitched;

    if (!sources?.length) {
        return { peaks: new Float32Array(0), framesPerWindow: 0, windowMs };
    }

    // One window length across the whole timeline so each bucket of the
    // output peaks envelope represents the same audio duration regardless of
    // which source file it falls in.
    const framesPerWindow = Math.max(1, Math.round(format.sampleRate * (windowMs / 1000)));
    const bytesPerWindow = framesPerWindow * format.bytesPerFrame;

    // Total whole windows across all sources determines the stride that lands
    // the output near the requested `peakStrides` buckets.
    let totalWindows = 0;
    for (const s of sources) {
        totalWindows += Math.floor(s.header.dataLength / bytesPerWindow);
    }
    const peakWindowsPerStride = Math.max(1, Math.floor(totalWindows / peakStrides) || 1);
    const expectedBuckets = Math.ceil(totalWindows / peakWindowsPerStride);

    // Float32 + transferable buffer keeps the post-message hop zero-copy.
    const peaks = new Float32Array(expectedBuckets * 2);
    let bucketIndex = 0;
    let peakAccMax = -Infinity;
    let peakAccMin = Infinity;
    let peakCounter = 0;

    // 4 MB chunks: same balance as the silence scanner — small enough to keep
    // memory bounded, large enough to amortise Range-request overhead on S3
    // (not relevant here since these are local Files, but harmless).
    const CHUNK_BYTES = 1 << 22;
    const decodeSample = sampleDecoder(format.bitsPerSample);

    let processedBytes = 0;
    let totalBytes = 0;
    for (const s of sources) totalBytes += s.header.dataLength;

    for (const source of sources) {
        const { file, header } = source;
        const dataEnd = header.dataOffset + header.dataLength;
        let cursor = header.dataOffset;
        const sourceWindows = Math.floor(header.dataLength / bytesPerWindow);
        let windowsLeftInSource = sourceWindows;

        while (cursor < dataEnd && windowsLeftInSource > 0) {
            const end = Math.min(cursor + CHUNK_BYTES, dataEnd);
            const chunk = await file.slice(cursor, end).arrayBuffer();
            const view = new DataView(chunk);

            const wholeWindows = Math.min(
                Math.floor(view.byteLength / bytesPerWindow),
                windowsLeftInSource,
            );

            for (let w = 0; w < wholeWindows; w++) {
                const base = w * bytesPerWindow;
                let wMax = -1.0;
                let wMin = 1.0;

                for (let i = 0; i < framesPerWindow; i++) {
                    const frameOff = base + i * format.bytesPerFrame;
                    for (let c = 0; c < format.channels; c++) {
                        const s = decodeSample(view, frameOff + c * (format.bitsPerSample / 8));
                        if (s > wMax) wMax = s;
                        if (s < wMin) wMin = s;
                    }
                }

                if (wMax > peakAccMax) peakAccMax = wMax;
                if (wMin < peakAccMin) peakAccMin = wMin;
                peakCounter++;
                if (peakCounter >= peakWindowsPerStride) {
                    if (bucketIndex < expectedBuckets) {
                        peaks[bucketIndex * 2] = peakAccMax;
                        peaks[bucketIndex * 2 + 1] = peakAccMin;
                        bucketIndex++;
                    }
                    peakAccMax = -Infinity;
                    peakAccMin = Infinity;
                    peakCounter = 0;
                }
            }

            const consumedBytes = wholeWindows * bytesPerWindow;
            cursor += consumedBytes;
            windowsLeftInSource -= wholeWindows;
            processedBytes += consumedBytes;
            onProgress?.(totalBytes > 0 ? processedBytes / totalBytes : 1);

            // Defensive: if a chunk somehow couldn't fit a single window we'd
            // loop forever. Bail rather than spin.
            if (wholeWindows === 0) break;
        }
    }

    // Flush a trailing partial bucket so the tail of the recording isn't lost
    // from the rendered envelope.
    if (peakCounter > 0 && peakAccMax > -Infinity && bucketIndex < expectedBuckets) {
        peaks[bucketIndex * 2] = peakAccMax;
        peaks[bucketIndex * 2 + 1] = peakAccMin;
        bucketIndex++;
    }

    // Trim if we filled fewer buckets than expected (rounding at the edges).
    const out = bucketIndex * 2 < peaks.length ? peaks.slice(0, bucketIndex * 2) : peaks;
    onProgress?.(1);
    return { peaks: out, framesPerWindow, windowMs };
}

/**
 * Branch-once PCM sample reader: maps a DataView byte offset to a normalised
 * float in [-1, 1]. Selecting the decoder up-front shaves real time off the
 * hot inner loop on multi-GB inputs.
 */
function sampleDecoder(bitsPerSample) {
    if (bitsPerSample === 16) {
        return (view, off) => view.getInt16(off, true) / 32768;
    }
    if (bitsPerSample === 24) {
        return (view, off) => {
            const v0 = view.getUint8(off);
            const v1 = view.getUint8(off + 1);
            const v2 = view.getInt8(off + 2);
            return ((v2 << 16) | (v1 << 8) | v0) / 8388608;
        };
    }
    return (view, off) => view.getInt32(off, true) / 2147483648;
}
