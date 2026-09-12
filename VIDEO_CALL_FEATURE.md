# Video Call feature

This adds a live, browser-based video call between a client and their lawyer,
attached to confirmed appointments. No new services or dependencies are
required - it reuses the existing PHP + MySQL stack and plain-JS conventions
already used across the app (see `public/js/chat.js`, `public/js/case-files.js`).

## What was added

| File | Purpose |
| --- | --- |
| `config/video_call/helpers.php` | DB table bootstrap, session/signal helpers, shared room renderer |
| `client/video-call.php` | Client-facing call room entry point |
| `lawyer/video-call.php` | Lawyer-facing call room entry point |
| `files/video-call/signal.php` | JSON polling endpoint used for WebRTC signaling (join/leave/offer/answer/ICE candidates) |
| `video_call_signal.php` | Thin root dispatcher for the endpoint above (mirrors `case_files.php`, `message_attachment.php`) |
| `public/js/video-call.js` | Browser-side WebRTC + polling logic, camera/mic controls |
| `public/css/style.css` | Appended `.video-call-*` styles matching the existing navy/gold theme |
| `client/appointment.php`, `lawyer/appointment.php` | Added a **"Join call"** button that appears once an appointment is `confirmed` |

## How it works

- Each confirmed appointment gets one `video_call_sessions` row (created on
  first visit - no migration to run, tables are created with
  `CREATE TABLE IF NOT EXISTS` the first time either page loads, the same
  pattern already used by `lex_case_files_table_ensure()`).
- Signaling (WebRTC offer/answer/ICE candidates) is exchanged by short-poll
  JSON requests to `video_call_signal.php` every ~2.5s and stored in
  `video_call_signals`. No WebSocket/Node process is required, so it works on
  plain PHP hosting.
- Only the two participants on the appointment (matched by `client_id` /
  `lawyer_id`) can load the room or exchange signals for it.
- Media (audio/video) travels directly between the two browsers via WebRTC
  (peer-to-peer) once the connection is established; only signaling metadata
  touches the server/database.

## `config/` is now in the repo

`config/bootstrap.php`, `config/app.php`, `config/db.php`,
`config/messages/*.php`, `config/case_files/*.php`, `config/home/page.php`
and `sql/migrations/` (previously missing) have since been added - see
`DATABASE.md`. This feature's calls into `lex_pdo()`, `lex_require_role()`,
`lex_user_client_id()`/`lex_user_lawyer_id()`, `lex_csrf_*`, `lex_audit()`,
`lex_page_header()`/`lex_page_footer()`, `lex_e()`, `lex_app_url()`, and
`lex_rate_limit_*` all resolve to real implementations now, and the full
flow below has been tested end-to-end (seeded users, confirmed an
appointment, opened both call rooms, exchanged signals, verified the
session transitions `waiting` -> `active`).

## Testing

1. `cp .env.example .env`, set your DB credentials, run `php scripts/migrate.php`.
2. As a lawyer, confirm an appointment for a client (Lawyer -> Appointments).
3. Open the client account in one browser/device and the lawyer account in
   another, go to Appointments on both, and click **Join call**.
4. Allow camera/microphone access on both - the call should connect within a
   couple of seconds.

## Known limitations / follow-ups

- Only STUN (`stun.l.google.com`) is used for NAT traversal, no TURN server.
  This works for most home/office networks but can fail behind strict
  corporate firewalls or symmetric NATs. For production reliability, add a
  TURN server (e.g. coturn, or a managed provider) to the `ICE_SERVERS` array
  in `public/js/video-call.js`.
- The call is 1:1 (one client, one lawyer) - matching how appointments work
  today. Group calls would need a different (SFU-based) architecture.
- Presence/"otherPresent" detection is a 12-second last-seen heartbeat via
  polling, not a real-time push - joining/leaving is detected within a few
  seconds, not instantly.
