/**
 * Web Worker entry point for stitched-timeline peaks. The main thread posts
 * the list of source { file, header } pairs (plus the shared format); we
 * stream the PCM and post back a single Float32Array peaks envelope as a
 * transferable so ownership moves without a copy.
 */

import { scanStitchedPeaks } from './wavStitchedPeaks.js';

self.onmessage = async (event) => {
    const { stitched, opts } = event.data;

    try {
        const result = await scanStitchedPeaks(stitched, {
            ...(opts || {}),
            onProgress: throttleProgress((p) => self.postMessage({ type: 'progress', progress: p })),
        });

        self.postMessage(
            {
                type: 'done',
                peaks: result.peaks,
                framesPerWindow: result.framesPerWindow,
                windowMs: result.windowMs,
            },
            [result.peaks.buffer],
        );
    } catch (err) {
        self.postMessage({ type: 'error', message: err?.message || String(err) });
    }
};

function throttleProgress(post) {
    let last = 0;
    return (p) => {
        const now = performance.now();
        if (now - last >= 33 || p === 1) {
            last = now;
            post(p);
        }
    };
}
