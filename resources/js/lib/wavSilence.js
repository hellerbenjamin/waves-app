/**
 * Stream PCM from a WAV File, computing RMS per short window so we can both
 * (a) find silence ranges for song-boundary detection and (b) emit a
 * downsampled max/min envelope to drive a review waveform — in a single pass
 * over the bytes. Reads through `File.slice()` chunks, so multi-GB files stay
 * out of memory.
 */

/**
 * @typedef {object} ScanOptions
 * @property {number} [silenceDb=-40]   RMS below this counts as silence (dBFS).
 * @property {number} [minSilence=1.5]  Drop silence runs shorter than this (s).
 * @property {number} [windowMs=20]     RMS window length (ms).
 * @property {number} [peakStrides=2000] Max pairs in the envelope; bigger = smoother.
 * @property {(progress:number) => void} [onProgress] 0..1 progress reporter.
 *
 * @typedef {object} ScanResult
 * @property {{start:number,end:number}[]} silences  Silence ranges in seconds.
 * @property {number[]} peaks  Interleaved [max,min, max,min, …] envelope, [-1,1].
 */

/**
 * @param {File|Blob} file
 * @param {import('./wav.js').WavHeader} header
 * @param {ScanOptions} [opts]
 * @returns {Promise<ScanResult>}
 */
export async function scanSilences(file, header, opts = {}) {
    const {
        silenceDb = -40,
        minSilence = 1.5,
        windowMs = 20,
        peakStrides = 2000,
        onProgress,
    } = opts;

    const { sampleRate, channels, bitsPerSample, bytesPerFrame, dataOffset, dataLength } = header;
    const framesPerWindow = Math.max(1, Math.round(sampleRate * (windowMs / 1000)));
    const bytesPerWindow = framesPerWindow * bytesPerFrame;
    const totalWindows = Math.max(1, Math.floor(dataLength / bytesPerWindow));

    // Linear amplitude threshold (RMS is also in 0..1, so compare apples-to-apples).
    const threshold = Math.pow(10, silenceDb / 20);

    // The envelope strides every N windows; N is chosen so the file maps to
    // roughly `peakStrides` total pairs (capped to the input length).
    const peakWindowsPerStride = Math.max(1, Math.floor(totalWindows / peakStrides) || 1);
    const peaks = []; // flat [max,min, max,min, ...]
    let peakAccMax = -Infinity;
    let peakAccMin = Infinity;
    let peakCounter = 0;

    const silences = [];
    let silenceStartWindow = null;

    // 4 MB chunks: a balance between Range-header overhead and per-read latency.
    const CHUNK_BYTES = 1 << 22;
    const dataEnd = dataOffset + dataLength;
    let cursor = dataOffset;
    let windowIndex = 0;

    // Per-sample decoder picked once per scan to keep the inner loop tight.
    const decodeSample = sampleDecoder(bitsPerSample);

    while (cursor < dataEnd) {
        const end = Math.min(cursor + CHUNK_BYTES, dataEnd);
        const chunk = await file.slice(cursor, end).arrayBuffer();
        const view = new DataView(chunk);

        // Process only as many whole windows as fit; bytes past the last whole
        // window are re-read at the start of the next chunk by advancing the
        // file cursor exactly the number of bytes we consumed.
        const wholeWindows = Math.floor(view.byteLength / bytesPerWindow);

        for (let w = 0; w < wholeWindows; w++) {
            const base = w * bytesPerWindow;

            let sumSq = 0;
            let count = 0;
            let wMax = -1.0;
            let wMin = 1.0;

            for (let i = 0; i < framesPerWindow; i++) {
                const frameOff = base + i * bytesPerFrame;
                for (let c = 0; c < channels; c++) {
                    const s = decodeSample(view, frameOff + c * (bitsPerSample / 8));
                    sumSq += s * s;
                    count++;
                    if (s > wMax) wMax = s;
                    if (s < wMin) wMin = s;
                }
            }

            const rms = Math.sqrt(sumSq / count);

            // Silence run-length encoding: open a run on the first quiet
            // window, close it the next loud one, and only keep runs long
            // enough that the user actually meant them as gaps.
            const isSilent = rms < threshold;
            if (isSilent && silenceStartWindow === null) silenceStartWindow = windowIndex;
            if (!isSilent && silenceStartWindow !== null) {
                const startSec = (silenceStartWindow * framesPerWindow) / sampleRate;
                const endSec = (windowIndex * framesPerWindow) / sampleRate;
                if (endSec - startSec >= minSilence) silences.push({ start: startSec, end: endSec });
                silenceStartWindow = null;
            }

            // Downsample into the envelope: accumulate window max/min across
            // N windows, then flush one [max,min] pair when the stride fills.
            if (wMax > peakAccMax) peakAccMax = wMax;
            if (wMin < peakAccMin) peakAccMin = wMin;
            peakCounter++;
            if (peakCounter >= peakWindowsPerStride) {
                peaks.push(Number(peakAccMax.toFixed(4)), Number(peakAccMin.toFixed(4)));
                peakAccMax = -Infinity;
                peakAccMin = Infinity;
                peakCounter = 0;
            }

            windowIndex++;
        }

        cursor += wholeWindows * bytesPerWindow;
        onProgress?.((cursor - dataOffset) / dataLength);
    }

    // Flush an unfinished envelope pair so the last sliver isn't lost.
    if (peakCounter > 0 && peakAccMax > -Infinity) {
        peaks.push(Number(peakAccMax.toFixed(4)), Number(peakAccMin.toFixed(4)));
    }

    // Close a silence run that extends to EOF.
    if (silenceStartWindow !== null) {
        const startSec = (silenceStartWindow * framesPerWindow) / sampleRate;
        const endSec = (windowIndex * framesPerWindow) / sampleRate;
        if (endSec - startSec >= minSilence) silences.push({ start: startSec, end: endSec });
    }

    return { silences, peaks };
}

/**
 * Convert silence ranges to candidate song regions: the gaps between them.
 * Mirrors {@see App\Jobs\DetectSongs::invert} so the client and server agree.
 *
 * @param {{start:number,end:number}[]} silences
 * @param {number} durationSeconds
 * @param {number} minRegion  Drop regions shorter than this.
 * @returns {{start:number,end:number}[]}
 */
export function invertToRegions(silences, durationSeconds, minRegion) {
    const regions = [];
    let cursor = 0;

    for (const s of silences) {
        if (s.start > cursor) regions.push({ start: cursor, end: s.start });
        cursor = Math.max(cursor, s.end);
    }
    if (durationSeconds > cursor) regions.push({ start: cursor, end: durationSeconds });

    return regions.filter((r) => r.end - r.start >= minRegion);
}

/**
 * Return a hot-loop sample reader that maps a DataView byte offset to a
 * normalised float in [-1, 1]. Branching once at scan setup rather than on
 * every sample shaves real time off a multi-GB walk.
 */
function sampleDecoder(bitsPerSample) {
    if (bitsPerSample === 16) {
        return (view, off) => view.getInt16(off, true) / 32768;
    }
    if (bitsPerSample === 24) {
        // 24-bit little-endian signed: pack low/mid bytes unsigned + high byte
        // signed so the sign extends correctly without a manual two's-complement.
        return (view, off) => {
            const v0 = view.getUint8(off);
            const v1 = view.getUint8(off + 1);
            const v2 = view.getInt8(off + 2);
            return ((v2 << 16) | (v1 << 8) | v0) / 8388608;
        };
    }
    // 32-bit PCM.
    return (view, off) => view.getInt32(off, true) / 2147483648;
}
