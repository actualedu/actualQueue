# Office Hours Submission App

This app powers a live office-hours workflow where students submit screenshots, watch their queue status, upvote entries, and admins manage/resolve the queue.

## Main Pages

- Home (student submit page): `/submit/` (served by `index.html`)
- Admin panel: `/submit/admin.php`
- Student queue view: `/submit/stats.php`

## How It Works

1. A student submits a username + image from `index.html` to `upload.php`.
2. `upload.php` validates security checks, stores the image in `uploadedImages/`, and appends an entry to `logs/submissions.json`.
3. Students view the live queue in `stats.php`, which polls `queue.php` every few seconds.
4. Admins manage the queue in `admin.php` (mark done, delete entries, post to Discord forum, or run lottery spin).
5. Queue and spin state are file-based JSON in `logs/` (no database required).

## Student Side

### Submission Flow (`index.html` -> `upload.php`)

- Uses a standard form POST with `multipart/form-data`.
- Fetches CSRF token from `upload.php?action=csrf` before submit.
- Supports click-to-upload, drag/drop, and camera capture.
- On success/error, `upload.php` returns a confirmation page with queue position.

### Validation and Protections in `upload.php`

- CSRF token required.
- Honeypot field (`website`) blocks simple bots.
- Queue cap (`MAX_QUEUE`, currently 99).
- Per-IP submit cooldown (`MIN_SECONDS_BETWEEN`, currently 30s).
- Max file size 10MB.
- Allowed MIME types: JPEG, PNG, GIF, WEBP.
- Image content validation via `getimagesize`.
- Duplicate image detection using SHA-1 hash markers in `logs/`.
- Username moderation against exact/partial banned-word lists.

### Live Queue (`stats.php`)

- Polls `queue.php` every 3 seconds.
- Displays each entry with:
  - Username
  - Posted time
  - Vote count
  - Odds percentage
  - Link to the uploaded image
- Supports public upvotes via `POST queue.php?upvote=1`.
- Upvotes have a per-IP 3-minute cooldown (stored in `logs/upvote_rate.json`).
- If a winner is selected by admin, the page animates a spin wheel based on saved `logs/spin.json` data.

## Admin Side (`admin.php`)

### Authentication

- Session-based login.
- Admin key must come from environment:
  - `CONNECT_QUEUE_ADMIN_KEY` (preferred), or
  - `CONNECT_ADMIN_SECRET` (fallback)
- If no key is set, `admin.php` fails closed with HTTP 500.

### Admin Actions

- `Mark Done (oldest)`: posts oldest item to Discord forum, removes it from queue, deletes its image.
- `Clear All`: removes all queue entries and all uploaded images.
- `Delete #`: removes a specific queue index.
- `Post #`: posts one queue entry to Discord forum without deleting.
- `Post All`: posts entire queue to Discord forum without deleting.
- `Choose Winner`: weighted lottery pick, marks one entry as winner, moves it to top, writes spin payload for frontend animation.

### Lottery Logic

- Each non-winner entry accumulates base votes over time.
- Formula used in both admin and student views:
  - `baseVotes = 10 + floor(ageMinutes * 2)`
  - final votes apply a logarithmic upvote boost: `floor(baseVotes * (1 + log(upvotes + 1)))`
- Winner entries are excluded from future vote growth/upvotes.

## API Endpoints

### `upload.php`

- `GET upload.php?action=csrf` -> `{ "token": "..." }`
- `POST upload.php` -> HTML success/error page

### `queue.php`

- `GET queue.php` -> sanitized public queue entries
- `GET queue.php?spin=1` -> latest spin event payload
- `POST queue.php?upvote=1` -> upvote response JSON
- `GET queue.php?full=1` -> full queue data (admin session required)

## Storage Layout

- `uploadedImages/` - uploaded screenshots
- `logs/submissions.json` - queue source of truth
- `logs/spin.json` - latest spin event for wheel animation
- `logs/upvote_rate.json` - upvote cooldown timestamps by IP
- `rate_limit/` - submit cooldown marker files by IP
- `logs/*.log` - operational/error logs

## Discord Integration

- `admin.php` uses `forumWebhook.php` to post queue items into a Discord forum webhook.
- `upload.php` also attempts best-effort forwarding through `fwdDiscord.php`/webhook helpers.
- You can optionally configure YouTube timestamp links in admin Discord posts with:
  - `YOUTUBE_API_KEY`
  - `YOUTUBE_CHANNEL_ID`

## Requirements

- PHP 5.6+ compatible runtime
- cURL extension (for Discord and YouTube API calls)
- Fileinfo support (`finfo`) recommended for MIME detection
- Writable directories:
  - `logs/`
  - `uploadedImages/`
  - `rate_limit/`

Use `permcheck.php` if you need permission diagnostics.

## Notes

- This is a file-backed app (JSON/files), not a DB-backed app.
- Keep webhook URLs and admin secrets out of source control for production.
