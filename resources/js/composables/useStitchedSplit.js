import { ref } from 'vue';

/**
 * Drive the multi-file stitched-split dialog: collect a batch of WAV files,
 * route them through the dialog for region definition, and hand each committed
 * segment to the caller's upload enqueue function. Mirrors the shape of
 * useSplitBeforeUpload so the integration on the page side is symmetrical.
 *
 * @param {(file: File|Blob) => void} enqueue  the underlying "upload this now"
 */
export function useStitchedSplit(enqueue) {
    const stitchedDialogVisible = ref(false);
    const pendingStitchedFiles = ref([]);

    /**
     * Open the dialog with a fresh batch. Caller is responsible for any
     * size/format prechecks beyond the WAV-extension filter (the dialog
     * surfaces format-mismatch errors after parsing each header).
     */
    const openStitchedSplit = (files) => {
        if (!files?.length) return;
        pendingStitchedFiles.value = Array.from(files);
        stitchedDialogVisible.value = true;
    };

    const onStitchedCommit = (segments) => {
        for (const seg of segments) {
            enqueue(new File([seg.blob], seg.name, { type: 'audio/wav' }));
        }
        pendingStitchedFiles.value = [];
    };

    const onStitchedCancel = () => {
        pendingStitchedFiles.value = [];
    };

    return {
        stitchedDialogVisible,
        pendingStitchedFiles,
        openStitchedSplit,
        onStitchedCommit,
        onStitchedCancel,
    };
}
