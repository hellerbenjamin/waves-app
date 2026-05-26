import { ref } from 'vue';
import { readWavHeader } from '@/lib/wav.js';

/**
 * Above this duration a WAV gets routed through the in-browser split dialog
 * before upload. Multi-song recordings (shows, rehearsals) almost always run
 * longer than ~12 min and benefit from being cut up before they leave the
 * machine; single songs effectively never do.
 */
const SPLIT_BEFORE_UPLOAD_SECONDS = 12 * 60;

/**
 * Wrap a per-file upload-enqueue function with a duration-aware interception:
 * long WAVs open the split dialog and only the committed segments are sent;
 * everything else passes straight through. The dialog itself is mounted by
 * the caller against the bindings returned here.
 *
 * @param {(file: File|Blob) => void} enqueue  the underlying "upload this now"
 */
export function useSplitBeforeUpload(enqueue) {
    // One dialog at a time. Long files arriving while a dialog is open queue
    // behind it so the user isn't asked to compare two recordings at once.
    const splitDialogVisible = ref(false);
    const pendingSplitFile = ref(null);
    const queue = [];

    const showNext = () => {
        if (splitDialogVisible.value) return;
        const next = queue.shift();
        if (!next) return;
        pendingSplitFile.value = next;
        splitDialogVisible.value = true;
    };

    /**
     * Per-file entry point. Reads just the WAV header to learn the duration;
     * long files open the dialog, short ones (or anything unparseable) fall
     * straight through to the underlying enqueue.
     */
    const enqueueWithSplit = async (file) => {
        if (/\.wav$/i.test(file.name)) {
            try {
                const header = await readWavHeader(file);
                if (header.durationSeconds >= SPLIT_BEFORE_UPLOAD_SECONDS) {
                    queue.push(file);
                    showNext();
                    return;
                }
            } catch {
                // Header unreadable — let the upstream validator/server decide.
            }
        }
        enqueue(file);
    };

    const onSplitCommit = (segments) => {
        // Each segment Blob becomes a normal upload with its chosen filename.
        for (const seg of segments) {
            enqueue(new File([seg.blob], seg.name, { type: 'audio/wav' }));
        }
        pendingSplitFile.value = null;
        showNext();
    };

    const onSplitUploadWhole = () => {
        if (pendingSplitFile.value) enqueue(pendingSplitFile.value);
        pendingSplitFile.value = null;
        showNext();
    };

    const onSplitCancel = () => {
        pendingSplitFile.value = null;
        showNext();
    };

    return {
        splitDialogVisible,
        pendingSplitFile,
        enqueueWithSplit,
        onSplitCommit,
        onSplitUploadWhole,
        onSplitCancel,
    };
}
