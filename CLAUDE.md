# Waves

Waves is a Laravel + Inertia + Vue app for uploading, browsing, and sharing
audio. Multitrack `.wav` files are streamed with a per-channel waveform mixer
(wavesurfer.js + Web Audio). **Events** group tracks and collect photos/videos
from a show, rehearsal, or session, and anything can be shared via an
unguessable public link.

## Environment & commands

This project runs under **ddev** (PHP 8.4, Node 24, MariaDB 11.8). Run tooling
*through* ddev, never bare:

- `ddev artisan …`, `ddev composer …`, `ddev npm …`
- `ddev artisan test` — run the test suite (PHPUnit, `tests/Feature`)
- `ddev artisan migrate`
- `ddev artisan queue:work` — required for background jobs (queue is the
  `database` driver); peaks extraction and thumbnails won't run without it

### Frontend / hot reload

- **Don't run `vite build` / `npm run build`.** Development is HMR via the Vite
  dev server. After editing Vue/JS, HMR picks it up — no build step.
- Start it with **`ddev vite`** (custom command wrapping `npm run dev` inside the
  web container; run it in its own terminal and leave it open). It writes
  `public/hot` → `https://waves.ddev.site:5173`, which ddev-router exposes over
  TLS. Verify with `cat public/hot` and `curl -sk https://waves.ddev.site:5173/@vite/client`.
- The on-disk `public/build/manifest.json` is a **stale prod build**. Feature
  tests that render a brand-new Inertia page must call `$this->withoutVite()` in
  `setUp()`, or they fail with "Unable to locate file in Vite manifest".

## Architecture

- **Stack:** Inertia 2 + Vue 3, PrimeVue 4 (Aura theme, dark mode via `.dark`),
  Ziggy for `route()` in JS, Uppy for uploads, wavesurfer.js for waveforms.
- **Storage:** object storage is the `tracks_disk` filesystem (Cloudflare R2 via
  the `s3` driver in prod; `local` works for dev). `App\Services\TrackStorage`
  and `App\Services\MediaStorage` share the S3 plumbing through the
  `App\Services\Concerns\InteractsWithS3` trait. Large files (multi-GB video,
  `.wav`) upload **direct to the bucket** via S3 multipart, signed by app
  endpoints (no Companion). Keys are owner-scoped: `users/{id}/*.wav` for tracks,
  `media/users/{id}/*` for media — ownership is enforced from the key prefix on
  the upload endpoints (no row exists yet).
- **Models & relationships:** a `Track` belongs to at most one `Event`
  (`tracks.event_id`, nulled on event delete — events are folders, not playlists).
  `Media` (photos/videos) likewise optionally belongs to an `Event`.
  *Cross-event playlists are a planned separate "tags" relationship — keep it
  distinct from events; it is not built yet.*
- **Jobs:** `ExtractPeaks` (waveform envelope from a `.wav`) and
  `GenerateThumbnail` (downscaled JPEG for images via GD; videos are skipped —
  poster frames would need ffmpeg).
- **Sharing:** `share_token` on `Track`, `Event`, and `Media` powers public,
  unauthenticated routes. A shared event streams its own tracks/media through
  the event token, so members don't each need their own link.
- **Authorization:** policies are auto-discovered (`App\Policies\{Model}Policy`),
  owner-only. Controllers call `$this->authorize(...)`; form requests authorize
  via `$user->can(...)`.

## Conventions

- Match the surrounding code's comment density and style — existing files favor
  short "why" comments over narration.
- Keep controllers thin; storage/IO logic lives in the `Services`.
- New Inertia pages go under `resources/js/Pages/<Area>/`; shared public pages
  render under `PublicLayout`, authenticated ones under `AuthenticatedLayout`.

## Git

- Committing directly to `master` is fine in this repo — no feature branch
  required. Still only commit/push when asked.
