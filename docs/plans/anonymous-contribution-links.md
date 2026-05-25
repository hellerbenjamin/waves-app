# Plan: Anonymous upload-contribution links for events

## Context

Today Waves is single-owner from top to bottom. Every upload path requires an
authenticated user, and **authorization is encoded in the S3 key prefix**:
the upload endpoints authorize purely by
`str_starts_with($key, 'media/users/{auth-id}/')` (see
`MediaController::assertOwnsKey`, `app/Http/Controllers/MediaController.php:222`).
There is no way to upload without a logged-in account.

The real-world need is the opposite: send a band member — or an audience member —
a link by SMS, and let them upload photos/videos straight from their phone with
**no account**. Read-only sharing already exists via `share_token`; this adds the
missing *write* primitive.

Per product decisions:
- **Contribution links only** — no teams, no new accounts. An unguessable
  per-event token grants anonymous upload, mirroring how `share_token` grants
  anonymous read.
- **Auto-visible** — contributed media appears in the event immediately; the
  owner deletes anything unwanted (existing delete flow already covers this).
- **Link-only delivery for v1** — generate a copyable link the owner texts
  manually. No SMS provider, no cost. (SMS automation is a clean later phase.)

Key design choice: **contributed media still belongs to the event owner's
account** (`media.user_id` = event owner). This keeps every existing policy,
cascade, streaming, and listing path unchanged — we only *add* attribution
(which invite, contributor's name). The only thing that changes about ownership
is *who is allowed to create the row*, which the invite token authorizes instead
of `auth()`.

## Approach

A new `EventInvite` model holds the write-token. Public, unauthenticated,
token-scoped upload endpoints (parallel to the existing public share routes)
reuse the S3 multipart machinery but authorize by the invite token + an
event-scoped key prefix instead of by user id. The event owner manages invites
from `Events/Show`; contributors get a mobile-first public upload page.

### Phase 1 — Data model & invite primitive

**Migration: `create_event_invites_table`**
- `id`, `event_id` (FK, cascadeOnDelete), `created_by` (FK users, cascadeOnDelete)
- `token` (string, unique) — `Str::random(40)`, the access control
- `label` (nullable string) — e.g. "Band", "Audience"
- `expires_at` (nullable timestamp), `revoked_at` (nullable timestamp)
- `uploads_count` (unsigned int, default 0)
- `timestamps`

**Migration: `add_contribution_columns_to_media_table`**
- `event_invite_id` (nullable FK, nullOnDelete) — null for owner uploads
- `contributor_name` (nullable string) — free-text name the uploader typed

**Models**
- New `App\Models\EventInvite` (fillable, `belongsTo` event + creator,
  `hasMany` media; helper `isUsable(): bool` → not revoked and not expired).
- `Event`: add `hasMany(EventInvite::class)` as `invites()`.
- `Media`: add `event_invite_id`, `contributor_name` to `$fillable`;
  `belongsTo(EventInvite::class)` as `invite()`.

**Policy** — `EventInvitePolicy` (auto-discovered, owner-only): a user may
create/revoke invites only on events where `event->user_id === user->id`.
Mirror `EventPolicy` exactly (`app/Policies/EventPolicy.php`).

### Phase 2 — Storage: event-scoped contribution keys

In `App\Services\MediaStorage` (`app/Services/MediaStorage.php`) add:

```php
// Contribution uploads are keyed under the event, not a user, so the upload
// endpoints can authorize from an invite token instead of auth().
public function newContribKey(int $eventId, string $extension): string
```
→ returns `media/events/{eventId}/contrib/{ulid}.ext` (same ext-normalisation
as `newMediaKey`). Add a matching `uploadTarget` overload or a small
`contribUploadTarget(int $eventId, ...)` that signs against this key.

The existing `assertOwnsKey` check (user prefix) is replaced for this flow by an
event-prefix check: `str_starts_with($key, "media/events/{$invite->event_id}/")`.

### Phase 3 — Extract the shared multipart-controller glue

`TrackController` and `MediaController` already duplicate the multipart dance
(`createMultipart` / `signPart` / `completeMultipart` / `abortMultipart` /
`cleanup` / `uploadPut`), differing only in storage service + the ownership
assertion. Adding a third copy in the contribution controller would triple it.

Extract a trait `App\Http\Controllers\Concerns\SignsDirectUploads` holding those
six methods, parameterized by two abstract hooks the using controller supplies:
`protected function uploadStorage(): MediaStorage|TrackStorage` and
`protected function assertCanWriteKey(Request $request, string $key): void`.
`MediaController` and the new `ContributionController` both use it. (Leave
`TrackController` as-is for now unless trivially compatible — note it but don't
force it; the goal is not to expand scope.)

### Phase 4 — Public contribution endpoints (anonymous, token-scoped)

New `App\Http\Controllers\ContributionController` (no `AuthorizesRequests`,
authorized entirely by the invite token, exactly like the public share
controllers). The `{invite:token}` route-model-binds the `EventInvite`; a
`->missing()` / explicit `abort_unless($invite->isUsable(), 410)` guards
revoked/expired tokens.

Methods (all under the public route group, all keyed via `newContribKey` and
asserting the event-prefix):
- `show(EventInvite $invite)` → renders `Contribute/Show` with the event name,
  date, label, and the token-scoped route names.
- `uploadUrl` / `createMultipart` / `signPart` / `completeMultipart` /
  `abortMultipart` / `cleanup` — via the `SignsDirectUploads` trait, with
  `assertCanWriteKey` checking the `media/events/{event_id}/` prefix.
- `store(EventInvite $invite, ...)` → validates `s3_key` (event prefix), `mime`
  (must be in `Media::ALLOWED_MIMES`), `size`, `original_name`, optional
  `contributor_name`; `abort_unless($invite->isUsable(), 410)`; creates the
  Media row with `user_id = $invite->event->user_id`, `event_id`,
  `event_invite_id`, `contributor_name`, `kind`; increments
  `uploads_count`; dispatches `GenerateThumbnail` for images (same as
  `MediaController::store`, `app/Http/Controllers/MediaController.php:126`).

**Routes** (`routes/web.php`, in the public block at top, alongside the existing
`/events/share/...` routes):
```
GET  /contribute/{invite:token}                       contribute.show
POST /contribute/{invite:token}/upload-url            contribute.upload-url
POST /contribute/{invite:token}/multipart             contribute.multipart.create
GET  /contribute/{invite:token}/multipart/sign        contribute.multipart.sign
POST /contribute/{invite:token}/multipart/complete    contribute.multipart.complete
POST /contribute/{invite:token}/multipart/abort       contribute.multipart.abort
POST /contribute/{invite:token}/cleanup               contribute.cleanup
POST /contribute/{invite:token}                        contribute.store
```
These are guest routes in the `web` group. Laravel sets the `XSRF-TOKEN` cookie
on the guest page response, so `useS3Upload`'s `apiFetch` CSRF header works
without exemptions. (Verify; if any POST fails CSRF for guests, add those upload
routes to the CSRF `$except` list — the token in the URL is the real guard.)

### Phase 5 — Owner UI: manage invites on the event page

Owner-side invite management routes (in the authenticated `events` group,
`routes/web.php:65`):
```
POST   /events/{event}/invites              events.invites.store
DELETE /events/{event}/invites/{invite}     events.invites.destroy   (revoke)
```
Implement as methods on `EventController` (consistent with `share`/`unshare`
there) or a thin `EventInviteController`; either authorizes via the
`EventInvitePolicy`. `store` creates an invite (`label`, optional `expires_at`)
and returns/redirects with `route('contribute.show', $invite->token)`.
`destroy` sets `revoked_at = now()`.

`Events/Show.vue` (`resources/js/Pages/Events/Show.vue`, owner view where
`canEdit`): add a "Collect uploads" panel — create-invite form (label + optional
expiry), and a list of active invites each showing the full link with a
**copy-to-clipboard** button (v1 delivery = owner texts it manually). Surface
contributor attribution on contributed media cards (show `contributor_name`
when present). `EventController::eventProps` /`mediaCard`
(`app/Http/Controllers/EventController.php:191`) gains `contributor_name`, and
the show payload gains the invite list.

### Phase 6 — Public contribution page (mobile-first)

`resources/js/Pages/Contribute/Show.vue` under `PublicLayout`:
- Event name/date/label header, friendly "Add your photos & videos" copy.
- Optional "Your name" text field → sent as `contributor_name` in the `store`
  finalize body.
- File input `accept="image/*,video/*"` with `capture="environment"` so phones
  offer the camera directly; reuse `useS3Upload`
  (`resources/js/composables/useS3Upload.js`) pointed at the `contribute.*`
  routes, with `initBody`/`finalize` including the contributor name.
- Progress list + a clear "Thanks, uploaded!" confirmation. No app chrome / no
  auth links.

## Files

**New**
- `database/migrations/..._create_event_invites_table.php`
- `database/migrations/..._add_contribution_columns_to_media_table.php`
- `app/Models/EventInvite.php`
- `app/Policies/EventInvitePolicy.php`
- `app/Http/Controllers/ContributionController.php`
- `app/Http/Controllers/Concerns/SignsDirectUploads.php`
- `app/Http/Requests/StoreContributionRequest.php` (+ invite-store request)
- `resources/js/Pages/Contribute/Show.vue`
- `database/factories/EventInviteFactory.php`
- `tests/Feature/ContributionTest.php`

**Modified**
- `app/Models/Event.php`, `app/Models/Media.php`
- `app/Services/MediaStorage.php` (contrib key + upload target)
- `app/Http/Controllers/MediaController.php` (adopt `SignsDirectUploads` trait)
- `app/Http/Controllers/EventController.php` (invite mgmt + attribution in props)
- `routes/web.php`
- `resources/js/Pages/Events/Show.vue`

## Verification

- **Tests** (`ddev artisan test`): new `tests/Feature/ContributionTest.php`
  covering — a valid token can mint an upload URL, finalize a Media row owned by
  the event owner with `event_invite_id`/`contributor_name` set, and have it
  appear in the event; a **revoked/expired token returns 410**; a key whose
  prefix is for a *different* event is **rejected (403)**; the public store route
  works with **no authenticated user**. Render test for `Contribute/Show` must
  call `$this->withoutVite()` in `setUp()` (new Inertia page — see CLAUDE.md /
  the vite-manifest-tests memory).
- **Queue**: run `ddev artisan queue:work` so `GenerateThumbnail` runs for
  contributed images.
- **Manual (HMR via `ddev vite`)**: as owner, create an event → "Collect
  uploads" → copy the link. Open the link in a separate/incognito session (no
  login), enter a name, upload a phone photo and a short video; confirm both
  appear in the owner's event with the contributor name, and that revoking the
  invite makes the link 410.
- Confirm guest POSTs pass CSRF (XSRF-TOKEN cookie present on the public page);
  only if they don't, add the `contribute.*` upload routes to CSRF `$except`.
```
