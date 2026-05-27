import { ref } from 'vue';
import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';

const csrfToken = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');

export const apiFetch = (url, { method = 'GET', body } = {}) => fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
        ...(body ? { 'Content-Type': 'application/json' } : {}),
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
});

/**
 * Direct-to-R2 uploads via Uppy + the unified AWS S3 plugin: small files take a
 * single presigned PUT, anything large is uploaded as multipart so multi-gig
 * files don't ride on one request. All signing/finalising goes through the app
 * endpoints named in `routes`; the browser PUTs the bytes straight to R2.
 *
 * The same machinery backs both track and media uploads — only the endpoint
 * names, the per-file init body, and what "finalise" means (creating the DB
 * row) differ, so those are injected by the caller.
 *
 * @param {{
 *   routes: { uploadUrl: string, multipartCreate: string, multipartSign: string, multipartComplete: string, multipartAbort: string, cleanup: string },
 *   initBody: (file) => object,
 *   finalize: (file, key) => Promise<void>,
 *   validate?: (file) => (string|null),
 *   onUploaded?: (file) => void,
 *   onError?: (file, message) => void,
 * }} options
 */
export function useS3Upload({ routes, initBody, finalize, validate, onUploaded, onError }) {
    // The reactive rows rendered as an upload progress list.
    const uploads = ref([]);
    // uppy file id -> reactive row.
    const entries = new Map();
    // uppy file id -> storage key minted when the upload was initiated.
    const keys = new Map();

    const uppy = new Uppy({ autoProceed: true })
        .use(AwsS3, {
            // Below ~100 MB a single PUT is simpler and cheaper than multipart.
            shouldUseMultipart: (file) => file.size > 100 * 1024 * 1024,
            // Target ~25 MB parts so a 4 GB file is ~160 parts instead of ~820;
            // scale up for very large files so we stay under S3's 10,000 cap.
            getChunkSize: (file) => Math.max(25 * 1024 * 1024, Math.ceil(file.size / 9000)),
            // Concurrent parts in flight. Default is 6; 10 better saturates a
            // fast uplink when combined with the larger part size above.
            limit: 10,

            async getUploadParameters(file) {
                const res = await apiFetch(routes.uploadUrl, { method: 'POST', body: initBody(file) });
                if (!res.ok) throw new Error(`init failed (${res.status})`);
                const data = await res.json();
                keys.set(file.id, data.s3_key);
                return { method: 'PUT', url: data.url, headers: data.headers || {} };
            },

            async createMultipartUpload(file) {
                const res = await apiFetch(routes.multipartCreate, { method: 'POST', body: initBody(file) });
                if (!res.ok) throw new Error(`init failed (${res.status})`);
                const data = await res.json();
                keys.set(file.id, data.key);
                return { uploadId: data.uploadId, key: data.key };
            },

            async signPart(file, { uploadId, key, partNumber }) {
                const url = `${routes.multipartSign}?key=${encodeURIComponent(key)}`
                    + `&uploadId=${encodeURIComponent(uploadId)}&partNumber=${partNumber}`;
                const res = await apiFetch(url);
                if (!res.ok) throw new Error(`sign failed (${res.status})`);
                return { url: (await res.json()).url };
            },

            async completeMultipartUpload(file, { uploadId, key, parts }) {
                const res = await apiFetch(routes.multipartComplete, { method: 'POST', body: { key, uploadId, parts } });
                if (!res.ok) throw new Error(`complete failed (${res.status})`);
                return { location: (await res.json()).location };
            },

            async abortMultipartUpload(file, { uploadId, key }) {
                await apiFetch(routes.multipartAbort, { method: 'POST', body: { key, uploadId } });
            },
        });

    uppy.on('upload-progress', (file, progress) => {
        const entry = entries.get(file.id);
        if (!entry) return;
        entry.status = 'uploading';
        if (progress.bytesTotal) entry.progress = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100);
    });

    // The bytes are in storage; create the DB row so the rest of the app sees it.
    uppy.on('upload-success', async (file) => {
        const entry = entries.get(file.id);
        const key = keys.get(file.id);
        if (entry) { entry.status = 'finalizing'; entry.progress = 100; }

        try {
            await finalize(file, key);
            if (entry) {
                entry.status = 'done';
                uploads.value = uploads.value.filter(u => u !== entry);
            }
            onUploaded?.(file);
        } catch (err) {
            if (entry) entry.status = 'error';
            // The bytes reached the bucket but no DB row was created; delete the
            // orphaned object so a failed finalise doesn't leak storage. Best
            // effort — nothing the user can do if cleanup itself fails.
            if (key && routes.cleanup) {
                apiFetch(routes.cleanup, { method: 'POST', body: { key } }).catch(() => {});
            }
            onError?.(file, 'finalize failed');
        } finally {
            entries.delete(file.id);
            keys.delete(file.id);
            uppy.removeFile(file.id);
        }
    });

    uppy.on('upload-error', (file, error) => {
        const entry = entries.get(file?.id);
        if (entry) entry.status = 'error';
        onError?.(file, error?.message || 'error');
        entries.delete(file?.id);
        keys.delete(file?.id);
    });

    const addFiles = (files) => {
        for (const file of files) {
            const problem = validate?.(file);
            if (problem) { onError?.(file, problem); continue; }

            const entry = ref({ name: file.name, progress: 0, status: 'queued' });
            uploads.value.push(entry.value);

            try {
                const id = uppy.addFile({ name: file.name, type: file.type, data: file });
                entries.set(id, entry.value);
            } catch (err) {
                entry.value.status = 'error';
                onError?.(file, err?.message || 'could not queue');
            }
        }
    };

    return { uploads, addFiles };
}
