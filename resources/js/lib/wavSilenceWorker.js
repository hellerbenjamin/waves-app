/**
 * Web Worker entry point for the heavy PCM scan. The main thread posts the
 * File and parsed header in; we stream the PCM, build the RMS envelope and a
 * downsampled peaks array, and post the result back along with periodic
 * progress messages. The big buffer is sent as a transferable so the main
 * thread takes ownership without a copy.
 *
 * Detection itself (threshold comparison + run-length encoding) is fast
 * enough to keep on the main thread — re-running it on every slider tick
 * never touches the worker.
 */

import { scanWindows } from './wavSilence.js';

self.onmessage = async (event) => {
    const { file, header, opts } = event.data;

    try {
        const result = await scanWindows(file, header, {
            ...(opts || {}),
            // Throttle progress posts: a smooth bar doesn't need more than
            // ~30/s, and serialising thousands of tiny messages is wasteful.
            onProgress: throttleProgress((p) => self.postMessage({ type: 'progress', progress: p })),
        });

        // Transfer the Float32Array buffer instead of copying it — at multi-GB
        // input sizes this can be tens of MB and the main thread doesn't need
        // a second copy once we're done with it.
        self.postMessage(
            {
                type: 'done',
                rmsBuffer: result.rmsPerWindow.buffer,
                peaks: result.peaks,
                framesPerWindow: result.framesPerWindow,
                windowMs: result.windowMs,
            },
            [result.rmsPerWindow.buffer],
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
