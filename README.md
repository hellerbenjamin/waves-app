# Waves

Waves is a Laravel + Inertia + Vue app for uploading, browsing, and sharing
audio. Multitrack `.wav` files stream with a per-channel waveform mixer
(wavesurfer.js + Web Audio), and **events** group tracks and collect the photos
and videos from a show, rehearsal, or studio session. Tracks, events, and
individual media can each be shared through an unguessable public link.

## Stack

- **Backend:** Laravel 13 (PHP 8.4), MariaDB, database queue
- **Frontend:** Inertia 2 + Vue 3, PrimeVue 4 (Aura theme), Vite
- **Audio/media:** wavesurfer.js + Web Audio mixer, Uppy uploads
- **Storage:** Cloudflare R2 (S3-compatible); large files upload direct to the
  bucket via S3 multipart
- **Local dev:** [ddev](https://ddev.com)

## Getting started

```bash
ddev start
ddev composer install
ddev npm install
ddev artisan migrate
```

Then open <https://waves.ddev.site>.

### Hot reload

The dev workflow is HMR via the Vite dev server — **don't** run `vite build`.
Start the server in its own terminal and leave it open:

```bash
ddev vite        # wraps `npm run dev` inside the web container; Ctrl-C to stop
```

It serves assets over TLS at `https://waves.ddev.site:5173` (writes
`public/hot`). Edits to Vue/JS hot-reload live in the browser.

#### Splitting only works against a production build

The in-browser splitting flows (long-WAV split-before-upload, and the multi-file
"Stitch & split" recording flow) construct Web Workers to scan PCM in the
background. Workers are subject to the browser's same-origin policy and refuse
to load a script served from a different origin than the page. In dev, the page
is on `https://waves.ddev.site` (port 443) while Vite serves worker scripts
from `https://waves.ddev.site:5173`, so the Worker constructor throws
`SecurityError`. Production is unaffected: `npm run build` emits workers as
bundled files that Laravel serves from the app's own origin via the Vite
manifest. To exercise the splitting flows locally, build the assets once
(`ddev npm run build`) and stop the dev server while testing.

### Background jobs

Waveform extraction and image thumbnails run on the queue, so a worker must be
running for uploads to finish processing:

```bash
ddev artisan queue:work
```

### Tests

```bash
ddev artisan test
```

## How it fits together

- **Storage** lives behind `TrackStorage` and `MediaStorage`, which share their
  S3 multipart/presign plumbing via the `InteractsWithS3` trait. Object keys are
  owner-scoped (`users/{id}/…`, `media/users/{id}/…`) and ownership is enforced
  from the key on the upload endpoints.
- **Events** are folders: a track belongs to at most one event. (Cross-event
  playlists are a planned, separate "tags" feature.) Media (photos/videos)
  optionally belongs to an event too.
- **Sharing** uses a `share_token` on tracks, events, and media to expose public,
  unauthenticated pages. A shared event plays its own tracks and media through
  the event's token.

Agent-facing notes (conventions, gotchas) live in [CLAUDE.md](CLAUDE.md).

## License

Built on the [Laravel framework](https://laravel.com), open-sourced under the
[MIT license](https://opensource.org/licenses/MIT).
