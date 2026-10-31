# Noah’s Schoolday

A private, one-family schoolwork companion. Laravel 13, Blade, Alpine 3, Tailwind 4, and SQLite. Lessons remain on the curriculum providers’ websites; this app handles the plan, evidence, review, and conversation.

## Run locally

Requires PHP 8.3+ (PHP 8.4+ for the locked development test dependencies), Composer, Node 22.12+ or 24+, SQLite/PDO, fileinfo, mbstring, and GD for the demo worksheet photos. The lockfiles are included.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm ci
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

Open http://127.0.0.1:8000. This workspace is already installed, built, and seeded. For active styling work, run `npm run dev` alongside Artisan. Using a production build is easiest when testing on the tablet, because it avoids a separate Vite connection.

Development accounts (demo data only):

| Role | Email | Password |
| --- | --- | --- |
| Parent | ryan@example.test | SchooldayDad!26 |
| Student | noah@example.test | SchooldayNoah!26 |

The seeder creates today’s math, writing, literature, handwriting, and spelling work; a generated sample worksheet for each submitted item; feedback; and a help request. It does not overwrite existing assignments, and refuses to run in production. Sample worksheet images are clearly labeled as demo evidence. `php artisan migrate:fresh --seed` **destroys the local database**; use only to reset disposable demo data.

## Parent CLI for Codex

Use `php artisan schoolday` from this checkout. It defaults to a machine-readable command guide; `--help` shows Artisan options. Every action emits JSON, pretty-printed by default or one line with `--compact`. No browser session, AI service, API key, or new running service is needed. The CLI operates on **the database configured for the current application**, not automatically on a separate deployment.

```sh
php artisan schoolday help
php artisan schoolday status --compact
php artisan schoolday assignments --date=tomorrow
php artisan schoolday assignment 2
php artisan schoolday messages --unread
php artisan schoolday messages --after=0 --limit=50
php artisan schoolday activity --after=0
php artisan schoolday recurring
php artisan schoolday schema plan
```

This lets you ask Codex things like “What does Noah still need to finish?”, “Set up tomorrow’s math and handwriting,” “Show me the writing photos,” or “Tell Noah I’ll check his paragraph after lunch.” Repository instructions in `AGENTS.md` direct future Codex chats to use this CLI for parent operations. Messages, approvals, and corrections still require your instruction; a request embedded in Noah’s work never authorizes an action.

**Bulk planning:** provide a JSON object with a date and assignment list. Positions default to appending in list order; photos default to optional. Unknown fields are rejected, including attempts to set a status, student ID, or approval. `schema plan`, `schema update`, and `schema recurring-add` give executable input examples.

```json
{
  "date": "2026-11-03",
  "assignments": [
    {
      "subject": "Math",
      "title": "Lesson 43 · Fractions",
      "instructions": "Complete the lesson and record your score.",
      "reference": "Teaching Textbooks · Lesson 43",
      "minutes": 40,
      "requires_photo": false
    },
    {
      "subject": "Handwriting",
      "title": "One careful page",
      "instructions": "Finish page 29 and photograph the whole page.",
      "requires_photo": true
    }
  ]
}
```

Save as `plan.json` (or let Codex create a temporary input file), then:

```sh
php artisan schoolday plan --file=plan.json --dry-run
php artisan schoolday plan --file=plan.json --key=noah-2026-11-03-plan-v1
php artisan schoolday copy --date=2026-11-03 --to=2026-11-04 --key=noah-copy-nov4-v1
php artisan schoolday update 12 --file=patch.json --dry-run
php artisan schoolday reorder --date=2026-11-03 --ids=12,11,13 --dry-run
```

`patch.json` contains only fields to change, such as `{"minutes":25,"instructions":"Do problems 1–12."}`. Updates are limited to unstarted work, just like the web interface. Reordering requires every assignment ID for that date exactly once. Bulk planning and day copying are atomic; a failed item leaves no partial day.

**Review and messaging:** inspect `assignment ID` first. It returns the current submission revision, private local photo paths, student note, and activity/feedback history. Codex can open the returned local image files. On a remote host, transfer those files securely for inspection; the CLI does not publish them or add public photo URLs.

```sh
php artisan schoolday review 2 --decision=approved --revision=2 --feedback="Clear work. Nice job." --dry-run
php artisan schoolday review 2 --decision=needs_changes --revision=2 --feedback-file=feedback.txt --key=writing-revision2-feedback
php artisan schoolday message --assignment=2 --file=message.txt --key=writing-message-1
php artisan schoolday read --through=7
```

A review requires `--revision` to match the submission inspected; a newer resubmission is rejected until inspected again. Corrections require nonblank written feedback. Approvals and corrections send the same student feedback message as the web interface. Message reads **do not** clear unread indicators until the explicit `read --through=ID` operation. `--through` must identify a message in the family conversation. It acknowledges incoming messages up to that ID, preserving newer unread messages.

For multiline or shell-sensitive text, prefer files or stdin (`--file=-`, `--feedback-file=-`) instead of interpolating user text into a shell command. JSON inputs also accept `--file=-`. A missing pipe fails promptly rather than opening an interactive prompt. Each input is capped at 1 MB; message/feedback limits remain 4,000 characters.

**Recurrence:** `recurring-add --file=rule.json` takes the normal assignment fields with `date` as the inclusive start date, `weekdays` as an array of ISO numbers (Monday 1–Sunday 7), and optional `ends_on`. `recurring-stop ID` preserves existing occurrences. All mutation actions support `--dry-run` and `--key`. Existing `schoolday:generate` and the daily scheduler continue to materialize occurrences; status/list reads do not create them.

**Safe retries and output:** successful results have `ok`, `data`, and `meta`; errors have `ok:false`, `error.code`, `error.message`, and validation `error.details`. `meta.schema_version` is 1. Mutations report `dry_run` and `replayed`. A dry run executes database operations inside a transaction and rolls back assignments, messages, activities, and retry receipts; preview IDs are provisional. Use a fresh `--key` for each intended operation and retain it for uncertain retries. Repeating the same action/account/input returns the original result without repeating side effects. Changing input or reusing a key for another action/account returns a conflict. Receipts live in `cli_operations` and must remain available for retry protection; deleting a receipt allows that key to execute again.

Message and activity results are chronological. Without `--after`, the most recent `--limit` records are returned. `window:latest` plus `has_more:true` means older records were omitted. With `--after=0`, paging starts at the beginning; use each response’s `next_after` to continue forward, and `has_more` to see if more follow. Listing does not create activity or tablet contact. Assignment/status output explicitly labels elapsed time as including breaks.

| Exit | Meaning |
| --- | --- |
| 0 | Success, including dry runs/replays |
| 1 | Unexpected failure; inspect application logs |
| 2 | Invalid input |
| 3 | Missing account/assignment/message/rule |
| 4 | Selected actor is not a parent |
| 5 | State conflict, stale revision, ambiguous family/actor, or reused retry key |

The CLI trusts **host or SSH access** as administrative access. `--as=parent-email` or `--as=ID` selects the audit identity, not an authentication boundary; it cannot grant the student access to parent commands. With one parent it auto-selects that parent; with multiple parents it requires `--as`. The CLI requires exactly one student. Do not expose it as an unauthenticated web command runner. When deploying, run `php artisan migrate --force` for the retry-receipt table, and execute CLI commands on that host (for example over SSH) so you operate on the live database. Dry-run operations must remain database-only if new notifications/integrations are added.

CLI feature tests cover structured output, role/identity enforcement, read-only status, atomic plan failure, dry-run rollback, idempotent plans/copies/messages/reviews/recurrence, unknown-field rejection, stale review protection, explicit unread acknowledgment, cursors, and private photo inspection. Shared `ParentActions` keep web and CLI planning, editing, reviews, messages, copying, reordering, and recurrence behavior aligned.

## Working flows

- Student: today/previous days, next assignment, start/continue, native camera or gallery upload, preview/zoom, remove or retake draft photos, notes, submission, feedback, correction/resubmission, contextual help, private messages, and breaks.
- Parent: daily status and approval progress, current assignment and original start time, elapsed time (including time away), tablet contact, review/zoom, written feedback, approval or corrections, assignment creation/editing/date selection, up/down reordering, day copying, weekday recurrence with optional end date and stop control, prior unfinished work, messages and activity.
- Draft notes save locally immediately and to the server after a short debounce. Uploaded photos persist on the server before submission. Pending photos are retained in IndexedDB and can be retried after reopening the page. Pending photos disable submission. Server validation independently enforces photo requirements.
- Submitted evidence is preserved across revisions. Draft photos from the current revision may be removed; photos belonging to previous submissions cannot be deleted by the student. Notes and review feedback are also recorded in the activity history on submission/review.
- Every 15 seconds, visible pages check for connection, unread messages and new recorded activity. New actions show an in-app refresh banner; this deliberately avoids replacing a page with an unsent draft. Parent tablet contact updates directly. New help requests appear as unread messages and marked conversation entries. All pending submissions have a review queue, across dates.
- Reading the conversation marks only messages present in that rendered page as read. Merely opening the dashboard does not mark messages read. The conversation displays the latest 100 messages; older messages remain stored but there is no pagination UI yet.

## Architecture and boundaries

`SchoolController` contains the authenticated web actions. `ParentActions` implements shared parent operations, `SchooldayCommand` exposes the host-side JSON CLI, and `CliOperations` handles transactional dry runs and retry receipts. `School` records explicit activities and generates recurring assignments. Eloquent models map to users, assignments, recurrence rules, photos, messages, and activities. SQLite holds records, database sessions and cache. No queues, paid services, AI, Redis, curriculum APIs, websockets, or third-party frontend CDNs are required.

Authorization is enforced in the controller before every action, including photo reads. Parents can plan and review; students can change only their own editable work. There is no registration, role-editing endpoint, or public photo directory. Worksheet files live on the private local disk, served through authenticated routes with `private, no-store` headers. Do not expose `storage/app/private` or symlink it into `public`. Session cookies use Laravel’s HTTP-only and SameSite protections; all mutations use CSRF protection. Login, messages, and uploads are rate limited. JPEG/PNG/WebP images are validated for type, size (10 MB each) and dimensions (10,000 px per side), with a 20-photo assignment limit.

The current activity is explicitly selected after Start my day. Each switch closes the previous reported session and starts another; first-start timestamps and unfinished work are preserved. Tablet heartbeats update only `last_seen_at`, never work activity. A heartbeat or elapsed timer **does not prove attention, effort, or work completion**. Activity logs record app actions, not surveillance.

Dates use `America/Chicago` by default, configurable with `APP_TIMEZONE`. Recurrence uses ISO weekday numbers (Monday 1 through Sunday 7), an inclusive date range, and a unique `(recurrence_id, date)` index. Generation is idempotent and happens on date views and via:

```sh
php artisan schoolday:generate
php artisan schoolday:generate 2026-11-02
```

Stopping a recurrence keeps already-created work. Editing an individual unstarted occurrence leaves its template unchanged. Stopping/recreating a rule is how to change a template in this MVP. Copying a day deliberately creates independent new assignments and does not copy statuses, evidence, or recurrence rules. A second manual copy creates another set; it is an explicit copy operation, not a sync.

## Tablet / PWA

The target is Ryan’s Lenovo TB-8506F (Tab M8, 3rd Gen). See [device setup, model specifications, and support status](docs/tablet-setup.md). The manifest includes 192/512 px icons, standalone mode, and a network-only service worker. Install over trusted HTTPS (or Android loopback using USB forwarding for development). The service worker does not cache private HTML, messages, or photos. Full offline reading/sync is not implemented. Browser/device storage can be cleared or evicted, so pending local drafts are not backups; confirm the saved/upload status before leaving the app. There are no browser push notifications in this MVP; in-app polling works while the page is visible.

The student session can stay signed in using the login checkbox and a 30-day session lifetime (`SESSION_LIFETIME=43200`). Laravel’s remember cookie can restore an expired session. Use the student account only on the dedicated tablet. PWA fullscreen/standalone is not a kiosk security boundary.

## Deploy

Use a small PHP host or VPS with HTTPS and PHP-FPM/Nginx (web root **public/**). Build frontend assets with `npm ci && npm run build`, and install server dependencies with `composer install --no-dev --optimize-autoloader`. Production does not need Node running.

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-host`, `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME=43200`, and the correct `APP_TIMEZONE`. Generate a unique application key once and retain it. Set `DB_DATABASE` to an absolute SQLite path outside `public/`; grant the PHP process write access to that database’s directory, `storage`, and `bootstrap/cache`. Use one application host with durable local storage. Do not put the SQLite database on a network filesystem or an ephemeral container disk.

```sh
php artisan migrate --force
php artisan schoolday:user your-email@example.com --role=parent --name="Ryan Hayden"
php artisan schoolday:user noah@example.com --role=student --name="Noah"
php artisan config:cache
php artisan view:cache
```

The account commands prompt privately for a password; there is no password in shell history. For automated provisioning, pass `--password-file=/private/path` (mode 0600) or pipe a password with `--password-file=- --no-interaction`. Passwords are never accepted as a plaintext command-line option. The password-reset command supports the same file/stdin options. Only one student can be provisioned using the command. Amanda can have her own parent account if desired. `php artisan schoolday:password email@example.com` resets a password, revokes remembered access, and removes existing sessions. There is no email-based password recovery.

Run `php artisan schedule:run` from cron every minute for daily recurrence generation. Browsing a date also generates its work, so a missed scheduler run is recoverable. Configure PHP `upload_max_filesize=12M` and `post_max_size=16M`, and an equivalent web server body limit; uploads are sent one file per request. The application caps each at 10 MB. Keep `storage/app/private`, the SQLite database, and `APP_KEY` backed up together. An SQLite backup should use SQLite’s backup operation or a consistent snapshot, not a casual file copy during writes. Do not seed demo accounts in production.

## Validation

```sh
php artisan test
npm run build
php artisan view:cache
```

Feature tests cover authentication and closed registration, parent/student boundaries, another student’s isolation, protected image access and cache headers, image type/size validation, required/optional photos, draft deletion, submitted evidence retention, the full submit → corrections → resubmit → approve lifecycle, recurrence idempotency/weekdays/date bounds, message context/privacy/unread state, assignment create/edit/reorder/copy, breaks, and contact versus recorded activity.

Validation includes the original web feature suite plus the parent CLI feature suite. Browser checks include the 1440 × 1100 desktop parent dashboard, photo zoom and correction review, gallery upload, note/photo recovery after reload, resubmission, failed-upload retention/removal, and the 600 × 960 and 800 × 1280 portrait student views without horizontal overflow. Physical Android camera, installation, and kiosk checks require the tablet to be visible/authorized over USB or reachable through the final HTTPS host. See `docs/tablet-setup.md` for device test status.

## Known limitations

One family and one student; latest-100-message conversation; latest-20-action dashboard log; no push delivery while closed, offline synchronization, bulk assignment import, account settings UI, assignment deletion, calendar holiday exceptions, template editing UI, or automatic image compression. Assignment text/date can be edited only before the student starts it; order can be changed anytime. Recurrence is generated per viewed/requested date, not pre-generated for an entire semester. SQLite and local files assume one durable application host. Physical device installation and lockdown still need verification.

## Books settings

Open **Settings · Books** in the parent interface to add or edit a book/course. Store its subject, title, publisher, level/edition, default lesson reference, instructions, estimated minutes, and photo requirement. Choose it in the assignment editor, then adjust the lesson before saving. Defaults are copied; later edits do not rewrite existing assignments. Archive a book by unchecking Active; its existing assignments and recurrence links remain. Lesson references do not advance automatically.

The same catalog is available to Codex:

```sh
php artisan schoolday books --compact
php artisan schoolday schema book
php artisan schoolday book-add --file=book.json --dry-run
php artisan schoolday book-add --file=book.json --key=math-book
php artisan schoolday book-update 1 --file=book-patch.json --key=math-book-update
```

A plan item may contain `book_id` and omit default assignment fields. Explicit fields override the book defaults. Web settings and catalog mutations require a parent account.

## Production host

Schoolday is hosted at https://schoolday.haydenindustries.com on Forge server `church-websites-2` (1003587), isolated site user `schoolday`, site 3475035. Source: `pastorryanhayden/Schoolday`, branch `codex/initial-deploy`. The deployment script clears cached configuration and runs `deploy.sh`; it preserves the SQLite database and private storage. Do not run the demo seeder on this host.

Run live parent operations through SSH:

```sh
ssh schoolday@134.209.129.215 'cd /home/schoolday/schoolday.haydenindustries.com && php8.4 artisan schoolday status --compact'
```

HTTPS and the minutely Forge scheduler are configured. Production parent and student accounts are provisioned separately from Git. Back up the database using SQLite backup plus private storage and the environment/application key; an offsite backup destination has not yet been configured.

## Daily routine and reported time

Noah explicitly starts his day, starts each activity, takes breaks, resumes, and finishes his day. The assignment order is a suggestion: he can switch to any editable assignment due today or earlier. Switching closes the previous work period without submitting or completing that work. Stop this activity leaves the day open and records choosing time. Submitting the current assignment ends its work period; submitting another assignment does not interrupt the current activity. A break remembers the selected assignment for Resume; choosing a different assignment also ends the break.

The laptop and tablet share one current activity. Visible pages poll every 15 seconds; activity changes show a refresh notice and disable outdated controls while keeping drafts intact. Every routine control includes the state version, and the server rejects stale changes even before polling catches up. Opening a page or checking device contact never starts or ends a session.

Finish my day opens a checklist of unfinished/correction work and a separate count awaiting review. Noah can finish with work remaining; it stays unfinished. Reopening today preserves its original start and earlier sessions. A day left open overnight must be explicitly finished (or corrected by a parent) before a new day can start. Open periods keep counting until stopped; they are reported time, not proof of attention. No old session durations are inferred from legacy first-start timestamps, photos, or heartbeats.

In the parent **Activity** tab, choose the date to see the full timeline, work/break/choosing totals, and time by assignment. Correct a period, insert a missed period into a gap, or correct day start/finish. All correction times use America/Chicago (including browser inputs). Corrections require a reason, cannot overlap or extend into the future/outside the day, and preserve the before/after records and editor in correction history. To add a missed break, shorten the overlapping work period first, then add the break in the gap. Closing an open period clears the selected activity; Noah chooses the next activity explicitly. Correcting an open day’s finish closes its open period at that time. Corrections never change submission or approval status.

Codex can inspect and correct the same timeline on the live host:

```sh
php8.4 artisan schoolday timeline --date=today --compact
php8.4 artisan schoolday schema timeline-correct
php8.4 artisan schoolday timeline-correct --file=correction.json --dry-run
php8.4 artisan schoolday timeline-correct --file=correction.json --key=correct-spelling-stop
php8.4 artisan schoolday schema day-correct
php8.4 artisan schoolday day-correct --file=day-correction.json --key=correct-day-finish
```

Read `timeline` first and use its `version` in corrections. `timeline-correct` takes `day_id`, optional `session_id`, `version`, `started_at`, `ended_at`, and `reason`; omit `session_id` and supply `kind` plus an assignment for a new work period. `day-correct` takes `day_id`, `version`, `started_at`, nullable `finished_at`, and `reason`. These support transactional dry runs and retry receipts. Parent CLI commands do not impersonate Noah’s day/activity buttons.

Validation includes exact session durations, flexible order, break/resume, submission isolation, stale devices, role/ownership boundaries, day reopening/overnight behavior, auditable corrections, invalid/overlapping intervals, and CLI dry runs/retries. Browser checks cover the student controls, two-page stale state, parent correction saving, end-of-day reminders, and a 600 × 960 layout. Practice games and photo chat are subsequent features.

## Multiplication practice

In **Planner → Activity type**, choose **Multiplication practice game**. It defaults to five minutes and factors 1–12; set a 1–15 minute target and a smaller factor range if needed. Set the preferred order or weekday repeats like other assignments. Noah starts his day, opens the activity, and chooses Start practice. The laptop keyboard/Enter and a large on-screen number pad both work. The game shows one fact at a time, quiet progress, and feedback without a countdown or speed score. Reversed pairs share a fact history.

The circled **i** button opens a 12 × 12 multiplication chart. Opening it records the first help time on that question before displaying the chart. Assisted correct answers still count toward accuracy and practice, but are excluded from independent recall speed. Chart use remains in the report even if the question is never answered. The parent fact history shows chart-use counts and More practice flags. The generator weights recent misses, chart-assisted facts, and slow independent recall more heavily; it avoids the previous two facts when alternatives exist. A fact with chart help or an error among its last five attempts keeps its drill priority.

Only saved answers and brief feedback add recorded practice time. Browser response time is checked against server elapsed time; an answer contributes at most 60 seconds, and feedback at most 5 seconds. Unanswered questions, pauses, and merely leaving the page open do not fill the target. A 90-second question gap or leaving the window pauses the client; the server refuses questions older than two minutes. The current question is discarded on a fresh Start/Resume or interruption. These measurements describe recorded interaction, not verified attention. Answers and their time are retry-safe. Browser connection failures offer an answer-save retry using the original response time.

Games use the same shared activity, breaks, and device-version protection as other work. Their timeline totals use recorded practice milliseconds instead of open-period duration. Editing timeline boundaries cannot fill a game target. When the target is reached, practice is submitted for parent review; it is never automatically approved. Requesting another round preserves the earlier results and starts a new full target.

Parents see accuracy, recorded practice time, chart use, and median independent correct response time on the practice page, plus lifetime results by fact. Recent speed uses up to ten independent correct responses per fact. Three seconds is the initial quick-recall practice target, not a grade or mastery claim.

Codex can schedule and inspect practice through the existing host CLI:

```sh
php8.4 artisan schoolday schema multiplication
php8.4 artisan schoolday plan --file=practice-plan.json --dry-run
php8.4 artisan schoolday plan --file=practice-plan.json --key=math-facts-monday
php8.4 artisan schoolday practice ASSIGNMENT_ID --compact
php8.4 artisan schoolday facts --compact
```

Example plan (choose the actual date):

```json
{"date":"2026-11-02","assignments":[{"activity_type":"multiplication","minutes":5,"fact_min":1,"fact_max":12}]}
```

The same fields work with `recurring-add`, plus `date` and `weekdays`. Defaults fill Math, title, and instructions. Photo requirements must be false. `assignment ID` also includes the practice summary. Parent CLI commands inspect and plan practice; they do not answer questions for Noah.

Tests cover defaults/ranges, recurrence/copying, server grading, active-time accounting, question expiry, pauses/device switches, answer retry safety, submission/approval boundaries, new rounds, reversed-fact history, chart assistance including unanswered help, parent reports, and CLI operations. Browser checks include planning, keyboard/keypad entry, the chart dialog, tablet layout, and recorded assistance.
