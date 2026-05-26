/**
 * Stream PCM from a WAV File once, producing both a per-window RMS envelope
 * (for silence detection) and a downsampled max/min envelope (for the review
 * waveform). Detection then runs from the cached RMS in-memory, so dragging
 * the threshold/min-silence sliders re-detects in milliseconds instead of
 * re-reading the file.
 *
 * Reads through `File.slice()` chunks, so multi-GB files stay out of memory.
 * The expensive scan is structured to run inside a Web Worker (see
 * wavSilenceWorker.js); detect/invert are tiny and run on the main thread.
 */

/**
 * @typedef {object} WindowsResult
 * @property {Float32Array} rmsPerWindow    RMS magnitude in [0,1] per window.
 * @property {number[]}     peaks           Interleaved [max,min, max,min, …] envelope, [-1,1].
 * @property {number}       framesPerWindow How many PCM frames each window covers.
 * @property {number}       windowMs        Window length in ms (round-trip for callers).
 */

/**
 * One pass over the PCM: emit per-window RMS and a downsampled peaks envelope.
 *
 * @param {File|Blob} file
 * @param {import('./wav.js').WavHeader} header
 * @param {{ windowMs?: number, peakStrides?: number, onProgress?: (p:number)=>void }} [opts]
 * @returns {Promise<WindowsResult>}
 */
export async function scanWindows(file, header, opts = {}) {
    const { windowMs = 20, peakStrides = 2000, onProgress } = opts;
    const { sampleRate, channels, bitsPerSample, bytesPerFrame, dataOffset, dataLength } = header;

    const framesPerWindow = Math.max(1, Math.round(sampleRate * (windowMs / 1000)));
    const bytesPerWindow = framesPerWindow * bytesPerFrame;
    const totalWindows = Math.max(1, Math.floor(dataLength / bytesPerWindow));

    // Hold one RMS value per window. Float32 is plenty of precision for a
    // 0..1 magnitude and halves the memory vs Float64 — at 20 ms/window over
    // a 4-hour file that's ~5 MB instead of 10.
    const rmsPerWindow = new Float32Array(totalWindows);

    // The envelope strides every N windows; N is chosen so the file maps to
    // roughly `peakStrides` total pairs (capped to the input length).
    const peakWindowsPerStride = Math.max(1, Math.floor(totalWindows / peakStrides) || 1);
    const peaks = []; // flat [max,min, max,min, ...]
    let peakAccMax = -Infinity;
    let peakAccMin = Infinity;
    let peakCounter = 0;

    // 4 MB chunks: a balance between Range-header overhead and per-read latency.
    const CHUNK_BYTES = 1 << 22;
    const dataEnd = dataOffset + dataLength;
    let cursor = dataOffset;
    let windowIndex = 0;

    const decodeSample = sampleDecoder(bitsPerSample);

    while (cursor < dataEnd && windowIndex < totalWindows) {
        const end = Math.min(cursor + CHUNK_BYTES, dataEnd);
        const chunk = await file.slice(cursor, end).arrayBuffer();
        const view = new DataView(chunk);

        // Process only as many whole windows as fit; bytes past the last whole
        // window get re-read by advancing the file cursor exactly the number of
        // bytes we consumed.
        const wholeWindows = Math.min(
            Math.floor(view.byteLength / bytesPerWindow),
            totalWindows - windowIndex,
        );

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

            rmsPerWindow[windowIndex] = Math.sqrt(sumSq / count);

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

    return { rmsPerWindow, peaks, framesPerWindow, windowMs };
}

/**
 * Pure, in-memory pass over a cached RMS envelope. Cheap enough to re-run on
 * every slider tick so the user gets a live preview.
 *
 * @param {Float32Array} rmsPerWindow
 * @param {{ framesPerWindow: number, sampleRate: number }} timing
 * @param {{ silenceDb?: number, minSilence?: number }} [opts]
 * @returns {{start:number,end:number}[]}
 */
export function detectSilences(rmsPerWindow, timing, opts = {}) {
    const { framesPerWindow, sampleRate } = timing;
    const { silenceDb = -40, minSilence = 1.5 } = opts;

    // Linear amplitude threshold to compare directly against RMS magnitude.
    const threshold = Math.pow(10, silenceDb / 20);
    const secondsPerWindow = framesPerWindow / sampleRate;

    const silences = [];
    let silenceStartWindow = null;

    for (let w = 0; w < rmsPerWindow.length; w++) {
        const isSilent = rmsPerWindow[w] < threshold;
        if (isSilent && silenceStartWindow === null) silenceStartWindow = w;
        if (!isSilent && silenceStartWindow !== null) {
            const startSec = silenceStartWindow * secondsPerWindow;
            const endSec = w * secondsPerWindow;
            if (endSec - startSec >= minSilence) silences.push({ start: startSec, end: endSec });
            silenceStartWindow = null;
        }
    }

    if (silenceStartWindow !== null) {
        const startSec = silenceStartWindow * secondsPerWindow;
        const endSec = rmsPerWindow.length * secondsPerWindow;
        if (endSec - startSec >= minSilence) silences.push({ start: startSec, end: endSec });
    }

    return silences;
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
