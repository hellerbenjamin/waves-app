/**
 * Tiny WAV-PCM toolkit for the browser: parse a file's RIFF (or RF64) header
 * to learn its sample rate / channel count / data offset, build a fresh PCM
 * header for a segment, and translate seconds ↔ byte offsets. Everything
 * works on `File` slices so multi-GB sources stay out of memory.
 *
 * Only PCM is supported: format code 1, or WAVE_FORMAT_EXTENSIBLE (0xfffe)
 * with a PCM subformat GUID. Anything else throws — the split-before-upload
 * flow falls back to a normal upload.
 */

// FourCC packed as little-endian uint32. Each character's ASCII byte sits in
// the lowest 8 bits first, so `'RIFF'` reads as 0x46464952 from a uint32-LE.
const FCC = (s) => s.charCodeAt(0) | (s.charCodeAt(1) << 8) | (s.charCodeAt(2) << 16) | (s.charCodeAt(3) << 24);

const FCC_RIFF = FCC('RIFF');
const FCC_RF64 = FCC('RF64');
const FCC_WAVE = FCC('WAVE');
const FCC_FMT = FCC('fmt ');
const FCC_DATA = FCC('data');
const FCC_DS64 = FCC('ds64');

// Most WAVs put fmt right after the RIFF/WAVE preamble; 256 KB is generous
// enough to skip past JUNK/BEXT/iXML chunks that pro DAWs sometimes inject
// before the data chunk header.
const HEADER_SCAN_BYTES = 256 * 1024;

/**
 * @typedef {object} WavHeader
 * @property {number} sampleRate          e.g. 44100, 48000, 96000
 * @property {number} channels            e.g. 1, 2, 8
 * @property {number} bitsPerSample       16 | 24 | 32
 * @property {number} bytesPerFrame       (bitsPerSample/8) * channels
 * @property {number} dataOffset          byte offset to the first PCM sample in the file
 * @property {number} dataLength          PCM payload length in bytes
 * @property {number} durationSeconds     dataLength / bytesPerFrame / sampleRate
 * @property {boolean} isRF64             true for 64-bit-size RF64 files (>4 GB)
 */

/**
 * Read just enough of a File to identify the PCM format and locate the data
 * chunk. Throws a descriptive Error if the file isn't a recognisable PCM WAV
 * so callers can fall back to a vanilla upload.
 *
 * @param {File|Blob} file
 * @returns {Promise<WavHeader>}
 */
export async function readWavHeader(file) {
    const buf = await file.slice(0, Math.min(file.size, HEADER_SCAN_BYTES)).arrayBuffer();
    const view = new DataView(buf);

    const riff = view.getUint32(0, true);
    if (riff !== FCC_RIFF && riff !== FCC_RF64) throw new Error('Not a WAV file (missing RIFF/RF64).');
    if (view.getUint32(8, true) !== FCC_WAVE) throw new Error('Not a WAVE file.');

    let sampleRate = 0;
    let channels = 0;
    let bitsPerSample = 0;
    let dataOffset = 0;
    let dataLength = 0;
    let ds64DataSize = null; // 64-bit override from the ds64 chunk

    let off = 12;
    while (off + 8 <= view.byteLength) {
        const id = view.getUint32(off, true);
        const size = view.getUint32(off + 4, true);

        if (id === FCC_DS64) {
            // RF64 spec: ds64 carries 64-bit replacements for the (otherwise
            // 0xFFFFFFFF) RIFF size, data size and sample count. We only need
            // the data size to know how many PCM bytes to read.
            ds64DataSize = Number(view.getBigUint64(off + 8 + 8, true));
        } else if (id === FCC_FMT) {
            const fmtCode = view.getUint16(off + 8, true);
            channels = view.getUint16(off + 10, true);
            sampleRate = view.getUint32(off + 12, true);
            bitsPerSample = view.getUint16(off + 22, true);

            if (fmtCode === 0xfffe) {
                // WAVE_FORMAT_EXTENSIBLE: the 16-byte SubFormat GUID at +24
                // begins with a 2-byte format code; only 1 (PCM) is supported.
                const sub = view.getUint16(off + 8 + 24, true);
                if (sub !== 1) throw new Error(`Unsupported WAV subformat ${sub} (only PCM).`);
            } else if (fmtCode !== 1) {
                throw new Error(`Unsupported WAV format ${fmtCode} (only PCM).`);
            }
        } else if (id === FCC_DATA) {
            dataOffset = off + 8;
            // RF64 marks unknown sizes as 0xFFFFFFFF and the real value lives
            // in ds64; standard RIFF carries it inline.
            dataLength = (size === 0xffffffff && ds64DataSize != null) ? ds64DataSize : size;
            break;
        }

        // RIFF chunks pad to an even byte boundary.
        off += 8 + size + (size & 1);
    }

    if (!sampleRate || !channels || !bitsPerSample || !dataOffset) {
        throw new Error('Missing fmt or data chunk in WAV header.');
    }
    if (![16, 24, 32].includes(bitsPerSample)) {
        throw new Error(`Unsupported bit depth ${bitsPerSample} (only 16/24/32-bit PCM).`);
    }

    const bytesPerFrame = (bitsPerSample / 8) * channels;
    const totalFrames = Math.floor(dataLength / bytesPerFrame);

    return {
        sampleRate,
        channels,
        bitsPerSample,
        bytesPerFrame,
        dataOffset,
        dataLength: totalFrames * bytesPerFrame, // trim any trailing partial frame
        durationSeconds: totalFrames / sampleRate,
        isRF64: riff === FCC_RF64,
    };
}

/**
 * Build a standard 44-byte RIFF PCM header for a fresh data body. The output
 * is the canonical layout (no JUNK/fact/extensible chunks) — adequate for
 * every consumer we care about, including ffmpeg, the browser <audio>
 * element, and our own ExtractPeaks job.
 *
 * @param {{ sampleRate:number, channels:number, bitsPerSample:number, dataLength:number }} spec
 * @returns {ArrayBuffer}
 */
export function buildWavHeader({ sampleRate, channels, bitsPerSample, dataLength }) {
    const bytesPerFrame = (bitsPerSample / 8) * channels;
    const buf = new ArrayBuffer(44);
    const v = new DataView(buf);
    const bytes = new Uint8Array(buf);

    writeFourCC(bytes, 0, 'RIFF');
    v.setUint32(4, 36 + dataLength, true); // file size minus the leading 8 bytes
    writeFourCC(bytes, 8, 'WAVE');
    writeFourCC(bytes, 12, 'fmt ');
    v.setUint32(16, 16, true);                          // fmt chunk size
    v.setUint16(20, 1, true);                           // PCM
    v.setUint16(22, channels, true);
    v.setUint32(24, sampleRate, true);
    v.setUint32(28, sampleRate * bytesPerFrame, true);  // byte rate
    v.setUint16(32, bytesPerFrame, true);               // block align
    v.setUint16(34, bitsPerSample, true);
    writeFourCC(bytes, 36, 'data');
    v.setUint32(40, dataLength, true);
    return buf;
}

function writeFourCC(bytes, offset, s) {
    bytes[offset] = s.charCodeAt(0);
    bytes[offset + 1] = s.charCodeAt(1);
    bytes[offset + 2] = s.charCodeAt(2);
    bytes[offset + 3] = s.charCodeAt(3);
}

/**
 * Byte offset (from the start of the file) of the PCM frame at `seconds`,
 * snapped to a frame boundary so a segment slice never starts mid-sample.
 */
export function timeToByteOffset(header, seconds) {
    const frame = Math.max(0, Math.round(seconds * header.sampleRate));
    const clamped = Math.min(Math.floor(header.dataLength / header.bytesPerFrame), frame);
    return header.dataOffset + clamped * header.bytesPerFrame;
}

/**
 * Compose a Blob holding a complete PCM WAV file for a sub-range of `file`:
 * a fresh canonical header in front of a `File.slice()` of the source PCM.
 * The slice itself is a zero-copy view — no bytes are read into memory.
 */
export function buildSegmentBlob(file, header, { start, end }) {
    const startByte = timeToByteOffset(header, start);
    const endByte = timeToByteOffset(header, end);
    const dataLength = Math.max(0, endByte - startByte);

    const hdr = buildWavHeader({
        sampleRate: header.sampleRate,
        channels: header.channels,
        bitsPerSample: header.bitsPerSample,
        dataLength,
    });

    return new Blob([hdr, file.slice(startByte, endByte)], { type: 'audio/wav' });
}

/**
 * @typedef {object} StitchedSource
 * @property {File|Blob} file
 * @property {WavHeader} header
 * @property {number} startSeconds  inclusive start on the stitched timeline
 * @property {number} endSeconds    exclusive end on the stitched timeline
 */

/**
 * @typedef {object} Stitched
 * @property {StitchedSource[]} sources              in user-chosen order
 * @property {{ sampleRate:number, channels:number, bitsPerSample:number, bytesPerFrame:number }} format
 * @property {number} totalDurationSeconds
 */

/**
 * Read every file's WAV header and assemble a virtual stitched timeline. The
 * mixer that produced these files split at 4 GB, so two adjacent chunks are
 * format-identical by construction; we still validate, because a user can pick
 * unrelated files. A mismatch throws with the offending filename — the caller
 * surfaces that and asks them to fix the selection.
 *
 * @param {Array<File|Blob>} files  ordered as the user wants them stitched
 * @returns {Promise<Stitched>}
 */
export async function readWavHeaders(files) {
    if (!files?.length) throw new Error('No files provided.');

    const sources = [];
    let format = null;
    let cursor = 0;

    for (const file of files) {
        const header = await readWavHeader(file);

        const fmt = {
            sampleRate: header.sampleRate,
            channels: header.channels,
            bitsPerSample: header.bitsPerSample,
            bytesPerFrame: header.bytesPerFrame,
        };

        if (format === null) {
            format = fmt;
        } else if (
            fmt.sampleRate !== format.sampleRate
            || fmt.channels !== format.channels
            || fmt.bitsPerSample !== format.bitsPerSample
        ) {
            const name = file.name || 'file';
            throw new Error(
                `"${name}" (${fmt.channels}ch @ ${fmt.sampleRate} Hz, ${fmt.bitsPerSample}-bit) doesn't match the first file `
                + `(${format.channels}ch @ ${format.sampleRate} Hz, ${format.bitsPerSample}-bit). `
                + `All files must share sample rate, channel count, and bit depth.`,
            );
        }

        const startSeconds = cursor;
        const endSeconds = cursor + header.durationSeconds;
        sources.push({ file, header, startSeconds, endSeconds });
        cursor = endSeconds;
    }

    return { sources, format, totalDurationSeconds: cursor };
}

/**
 * Map a point on the stitched timeline to {sourceIndex, secondsWithinSource}.
 * Out-of-range inputs clamp to the start/end of the stitched range — callers
 * generally pass region edges derived from this same timeline, so clamping is
 * the kindest behaviour.
 */
export function locateOnStitched(stitched, seconds) {
    const { sources, totalDurationSeconds } = stitched;
    if (seconds <= 0) return { sourceIndex: 0, secondsWithinSource: 0 };
    if (seconds >= totalDurationSeconds) {
        const last = sources.length - 1;
        return { sourceIndex: last, secondsWithinSource: sources[last].header.durationSeconds };
    }
    // Linear scan is fine: N is small (handful of mixer chunks).
    for (let i = 0; i < sources.length; i++) {
        const s = sources[i];
        if (seconds < s.endSeconds) {
            return { sourceIndex: i, secondsWithinSource: seconds - s.startSeconds };
        }
    }
    const last = sources.length - 1;
    return { sourceIndex: last, secondsWithinSource: sources[last].header.durationSeconds };
}

/**
 * Compose a Blob for a region `[start, end)` (in seconds) on the stitched
 * timeline: a fresh PCM header followed by zero-copy `File.slice()` views
 * across however many source files the region spans. No PCM bytes are read.
 *
 * Output is a standard 32-bit-size RIFF WAV; a single region above 4 GB would
 * overflow it. Callers should reject that case upstream — songs are normally
 * minutes long, so this is theoretical.
 *
 * @param {Stitched} stitched
 * @param {{ start:number, end:number }} region  seconds on the stitched timeline
 * @returns {Blob}
 */
export function buildStitchedSegmentBlob(stitched, { start, end }) {
    if (end <= start) {
        const hdr = buildWavHeader({ ...stitched.format, dataLength: 0 });
        return new Blob([hdr], { type: 'audio/wav' });
    }

    const begin = locateOnStitched(stitched, start);
    const finish = locateOnStitched(stitched, end);

    const parts = [];
    let dataLength = 0;

    for (let i = begin.sourceIndex; i <= finish.sourceIndex; i++) {
        const src = stitched.sources[i];
        const sliceStart = (i === begin.sourceIndex) ? begin.secondsWithinSource : 0;
        const sliceEnd = (i === finish.sourceIndex) ? finish.secondsWithinSource : src.header.durationSeconds;

        const startByte = timeToByteOffset(src.header, sliceStart);
        const endByte = timeToByteOffset(src.header, sliceEnd);
        if (endByte > startByte) {
            parts.push(src.file.slice(startByte, endByte));
            dataLength += (endByte - startByte);
        }
    }

    // Classic RIFF caps both the data and overall size fields at 4 GiB. We
    // emit canonical 32-bit headers; refuse rather than silently wrap, which
    // would produce a Blob whose header claims a truncated length.
    const MAX_DATA_LENGTH = 0xffffffff - 36;
    if (dataLength > MAX_DATA_LENGTH) {
        throw new Error(
            `Region exceeds the 4 GB limit for a single WAV file `
            + `(${dataLength.toLocaleString()} bytes). Split it into smaller songs.`,
        );
    }

    const hdr = buildWavHeader({ ...stitched.format, dataLength });
    return new Blob([hdr, ...parts], { type: 'audio/wav' });
}
