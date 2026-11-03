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

Games use the same shared activity, breaks, and device-version protection as other work. Their timeline totals use recorded practice milliseconds instead of open-period duration. Editing timeline boundaries cannot fill a game target. The current question finishes before the round ends, so recorded practice can exceed the target slightly. When the target is reached, practice is submitted for parent review; it is never automatically approved. Requesting another round preserves the earlier results and starts a new full target.

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


### Spelling practice

Choose **Spelling practice game** in the planner. Enter the weekly list, one word per line (1–50 English words, including apostrophes or hyphens). The list is saved on that activity, so later lists do not change past results. Copy the activity or use weekday recurrence for the week, then stop that recurrence and create the next week’s list. Unstarted activities can be edited.

All three modes rotate: **Look & spell** (study, cover, type), **Listen & spell** (hear, type), and **Choose the spelling** (hear, select from three spellings). Choice alternatives are generated from letter swaps/omissions; listening identifies the intended word when an alternative is also a real word. Browser text-to-speech uses an English device voice when available, preferring a local voice. Device voice quality and pronunciation vary; words with ambiguous pronunciations or homophones may need parent explanation. No OpenAI key or paid speech service is required. If audio fails, use Look at the word and cover it; the peek is recorded.

A replay is counted separately and is not treated as an error. Peeks flag a word for further practice even if unanswered; assisted correct answers still count for accuracy and time, but are excluded from independent recall speed. Every listed word gets a turn before repeating, with recent mistakes/peeks prioritized on the next pass. Parents see results by mode and word; recognition results are separate from spelling from memory. Times reflect saved interaction, not verified attention. The same breaks, stale-device checks, retries, time accounting, and parent review rules apply as multiplication.

Use five minutes by default (1–15), or optional `question_target` (1–100) to finish after that many saved answers instead. The question target also works for multiplication. Inputs are graded on the server, ignoring case, surrounding spaces, and curly versus straight apostrophes.

```sh
php artisan schoolday schema spelling
php artisan schoolday plan --file=spelling-plan.json --dry-run
php artisan schoolday plan --file=spelling-plan.json --key=spelling-week-one
php artisan schoolday practice ASSIGNMENT_ID --compact
```

Example plan:

```json
{"date":"2026-11-02","assignments":[{"activity_type":"spelling","minutes":5,"spelling_words":["because","friend","different"]}]}
```

`practice ID` and `assignment ID` include per-word and per-mode spelling results. `facts` continues to report multiplication only. Weekly lists also work with `recurring-add`.


### Game drawer and optional extra practice

Students open **Games** in the top navigation to pick a reusable game. Each Play creates a separate optional round, so Noah can replay times tables or spelling throughout the day. Start the schoolday first. Game mode has its own dark play surface; parent reports retain the normal planner layout. Opening the drawer pauses an active question. Each round retains its settings, answer history, help usage, and recorded time. Extra practice does not add required assignments, carryover, day-finish obligations, or review-queue items. Its results still save for Dad, can be inspected/reviewed individually, and contribute to the timeline and multiplication fact history. Optional rounds are excluded when copying or reordering the required daily plan.

Parents use **Games** to add/edit games, configure 1–12 factor ranges, add a named weekly spelling list, or archive old games. Updates affect future rounds only; archiving hides future launches while preserving existing rounds. A required game remains a normal practice assignment scheduled in the Planner. Supported game types are multiplication and the three spelling modes; new game mechanics require implementation before they can be selected. The library can hold as many lesson-specific lists and factor ranges as needed. Existing students receive a standard times-table game, and previously scheduled spelling-game lists are copied into the library during migration.

```sh
php artisan schoolday games --compact
php artisan schoolday schema game
php artisan schoolday game-add --file=game.json --dry-run
php artisan schoolday game-add --file=game.json --key=spelling-week-two
php artisan schoolday game-update GAME_ID --file=patch.json --key=archive-week-one
```

Game JSON accepts `activity_type`, `title`, `instructions`, `minutes`, `question_target`, `fact_min`, `fact_max`, `spelling_words`, and `active`. Example: `{"activity_type":"spelling","title":"Spelling · week two","minutes":5,"spelling_words":["friend","because","different"]}`. Archive with `{"active":false}`. Games are optional by default and have no assignment date. `schoolday status` separates `extra_practice` from required progress. `assignments --date=...` includes optional rounds with `is_extra_practice` and `game_id`, and `assignment ID` / `practice ID` inspect their results. No CLI command plays a round on Noah’s behalf.

## Native student API (v1)

The native API covers Noah’s student workflows. All paths below are relative to `/api/v1/student`. It uses the same database, private worksheet storage, assignment rules, daily routine, and practice engine as the website. Parent planning, approvals, catalog edits, and time corrections use the separate parent API below, or the parent website/CLI.

Deploy the code and run `php artisan migrate --force` to create `device_tokens` and add role scopes before using the APIs. No dependency installation or new service is required. Tests use an isolated database; implementing the API does not deploy it or migrate production.

### Authentication and response format

Send `POST /login` with JSON:

```json
{"email":"student@example.com","password":"the-student-password","device_name":"Noah’s iPhone"}
```

A successful login returns HTTP 201:

```json
{
  "data": {
    "token": "a-64-character-opaque-token",
    "token_type": "Bearer",
    "expires_at": "2026-12-03T08:00:00+00:00",
    "device_id": 1,
    "user": {"id": 2, "name": "Noah", "email": "student@example.com", "role": "student"}
  }
}
```

Store the token in iOS Keychain. Send `Authorization: Bearer <token>` on every subsequent request, **including photo downloads**. Send `Accept: application/json` and `Content-Type: application/json` for JSON bodies; uploads use multipart form data. Browser cookies and CSRF tokens are neither needed nor accepted as API authentication. A native token does not authenticate browser or parent routes.

Tokens expire after 30 days with no sliding extension. Sign in again after expiry. Only a token hash is stored; plaintext is returned once at login. Password changes invalidate all existing tokens. The student API only accepts student accounts; parent accounts sign in through the parent API. Device names are labels; signing in again creates a separate token, even with the same name.

JSON success responses have a `data` envelope, with `meta` alongside it where listed. New tokens, photos, messages, and game rounds return 201; other successful actions return 200. Photo downloads return image bytes. Errors always return JSON, including when `Accept` is omitted: `{"message":"..."}`, with an `errors` field for validation failures. Handle 401 by signing in, 403 as a permission failure, 404 as unavailable/foreign data, 409 by refreshing the current state, 422 as invalid input, and 429 using `Retry-After`. Responses are private and `no-store`; no private storage paths, password hashes, or token hashes are returned.

| Method | Path | Input / result |
| --- | --- | --- |
| POST | `/login` | `email`, `password`, `device_name` (max 100 characters); returns token and user |
| GET | `/me` | Student `id`, `name`, `email`, `role` |
| GET | `/devices` | Active devices: `id`, `name`, timestamps, and `current`; never credentials |
| DELETE | `/devices/{device_id}` | Revoke one owned device, including the current one |
| POST | `/logout` | Revoke the current token |
| POST | `/logout-all` | Revoke all of this student’s tokens |

Login is limited to six requests per minute per IP. Authenticated requests share a 240/minute student limit. Uploads, messages, and game launches each have separate 30/minute limits; practice actions have a separate 180/minute limit. These counters are independent of the login counter.

### Plan, assignment work, and photos

| Method | Path | Input / result |
| --- | --- | --- |
| GET | `/day?date=YYYY-MM-DD` | Date defaults to today. Returns `today`, server `timezone`, student, ordered `assignments`, prior `unfinished` work, `awaiting_review`, `current_assignment`, `progress`, routine report, unread count, and latest 20 activities. Generates recurring work idempotently for the requested date. |
| GET | `/assignments?date=YYYY-MM-DD&extra=0&limit=100&page=1` | Paginated assignments for a date; `extra=1` selects optional rounds instead of required work. `limit` is 1–100. `meta.has_more` and `meta.next_page` control pagination. |
| GET | `/assignments/{id}` | Assignment details, feedback, book metadata, and photos, including practice assignments |
| POST | `/assignments/{id}/start` | `version`; select this assignment as the current activity |
| PATCH | `/assignments/{id}/draft` | Required `note`, string up to 4,000 characters or `null` to clear |
| POST | `/assignments/{id}/photos` | One multipart `photo`; returns photo metadata with a relative authenticated `url` |
| GET | `/photos/{id}` | Private image bytes; attach the bearer header using the configured API host |
| DELETE | `/photos/{id}` | Delete a current draft photo; submitted revision evidence is preserved |
| POST | `/assignments/{id}/submit` | Optional `note`; also used for resubmission after corrections |

Assignment resources include `id`, `date`, `subject`, `title`, `instructions`, `reference`, `minutes`, `position`, `requires_photo`, `status`, `note`, `feedback`, `revision`, start/submission/approval timestamps, practice configuration (`activity_type`, `question_target`, factor bounds, spelling words), `is_extra_practice`, `game_id`, book metadata, and photo metadata. Photo metadata includes `id`, `assignment_id`, `name`, `revision`, `created_at`, and `url`. Start/draft/submit responses contain `data.assignment` and `data.routine` with the current routine version.

JPEG, PNG, and WebP uploads are limited to 10 MB each, 10,000 pixels per side, and 20 photos per assignment. Convert iOS HEIC captures to JPEG before upload. Required-photo assignments cannot be submitted without evidence. Submitted/approved work is locked until a parent requests corrections. Submission and parental approval are distinct statuses. Practice assignments submit automatically when their target is reached; the normal submit endpoint cannot bypass the game.

The day’s required progress excludes optional practice rounds. `progress.submitted` and `progress.approved` are separate counts. Prior `unfinished` work includes submitted work awaiting review, matching the web dashboard; the finish-day checklist excludes work already submitted.

### Day controls and synchronization

| Method | Path | Input / result |
| --- | --- | --- |
| GET | `/routine?date=YYYY-MM-DD` | Routine report: `version`, requested day and its sessions/corrections, `open_day`, `current`, work/break/idle totals, and totals per assignment |
| POST | `/routine` | `action` and `version`; actions: `day-start`, `activity-stop`, `break`, `resume`, `day-finish` |
| GET | `/routine/finish` | Read-only checklist: `remaining`, `awaiting_review_count`, and routine report |
| POST | `/heartbeat` | Contact update only; returns `last_seen`, `on_break`, unread/submitted counts, outgoing message `read_through`, routine state, and latest relevant activity ID |

Starting work, changing routine state, launching a game, and all practice actions require the latest integer `version`. Read it from the routine state/report; apply the version returned by the successful action. A stale version returns 409 without applying the change. Refresh `/routine` (and the relevant assignment/practice screen) before asking the student to retry. The tablet and iOS app share the same current activity.

`POST /routine` returns `data.version`, `data.day`, `data.current`, and `data.label`. Assignment actions and game launch return that state under `data.routine`; practice actions return it under `data.state`. Day/report responses use `open_day` for the currently open day, which may be earlier than the requested date. An unfinished prior day must be explicitly finished before a new day starts. `day-start` can reopen today while preserving earlier sessions.

Poll heartbeat about every 15 seconds while the app is visible. Heartbeats do not start/stop sessions or prove attention. Work/break totals are reported periods, not verified effort. Leaving the app does not implicitly finish or pause a day: use explicit controls. `/routine/finish` is a read; only `day-finish` changes the day. Finishing with remaining work is allowed and preserves that work.

### Messages and help

| Method | Path | Input / result |
| --- | --- | --- |
| GET | `/messages` | Latest 100 family messages, returned oldest first, without acknowledging them |
| GET | `/messages?after=ID&limit=100` | Next messages after a cursor, oldest first; use `meta.next_after` to continue |
| GET | `/messages?before=ID&limit=100` | Older history, returned oldest first; use `meta.next_before` to continue backward |
| POST | `/messages` | `body` (max 4,000 characters), optional owned `assignment_id`, optional boolean `help` |
| POST | `/messages/read` | `through`: an ID in this student’s conversation; acknowledges incoming messages through that ID, preserving newer unread messages |

Message resources include ID, body, help flag, assignment ID/title, sender ID/name/role, `is_mine`, creation and read timestamps. Listing metadata includes `has_more`, `next_after`, `next_before`, and `unread`. Use either `after` or `before`, never both. Read acknowledgments are explicit; fetching the plan or messages leaves unread counts unchanged. A help request is a message with `help: true` and, when applicable, the assignment ID.

### Games and practice

| Method | Path | Input / result |
| --- | --- | --- |
| GET | `/games` | Active library games with configuration, `rounds_count`, `latest_round_id`, and `meta.routine` |
| POST | `/games/{id}/play` | `version`; creates a new optional assignment/round and selects it as the current activity. Returns `data.assignment` and `data.routine`. |
| GET | `/practice/{assignment_id}` | Assignment, read-only current `practice` snapshot, and full `report` including per-round results (and spelling word/mode summaries) |
| POST | `/practice/{assignment_id}` | `action`, `version`, and action-specific fields below |

Practice action responses contain `data.state`, `running`, `question`, `result`, `complete`, and `summary`. A `listen` action also returns `speech`. Summary includes target, current round, and prior-round statistics. Required practice assignments use these same endpoints. Optional rounds retain their own immutable configuration and history; archived games cannot launch new rounds, while existing rounds remain accessible.

| Action | Additional input | Behavior |
| --- | --- | --- |
| `start` | None | Start/resume this practice and issue a fresh question |
| `next` | Optional previous `attempt_id` and `feedback_ms` (0–5,000) | Credit brief prior-answer feedback once, then issue the next question |
| `answer` | `attempt_id`, `answer`, `response_ms` (100–120,000) | Grade on the server; integer answer for multiplication, string for spelling |
| `pause` | None | Stop practice activity; preserve completed answers and credit |
| `help` | `attempt_id` | Record chart use for multiplication or a peek for spelling |
| `cover` | `attempt_id` | Hide the studied/peeked spelling word |
| `listen` | `attempt_id` | Record audio playback and return `speech` for native text-to-speech |

Multiplication questions contain `id`, `left`, and `right`. Render chart help locally, but call `help` when it is opened so assisted answers stay out of independent recall speed. Spelling rotates `study`, `listen`, and `choose`. Its question contains `id`, `mode`, visibility-filtered `word`, `covered`, `choices`, `audio_plays`, and `can_answer`. Use native speech synthesis for `speech`; cover the study/peeked word or play the listen/choose audio before answering. Results contain correctness, assistance, and the correct answer. The client must not grade or mark work complete itself.

Only recorded answers and brief feedback count toward the game target; background/idle time does not. A question older than two minutes or from an interrupted session must be replaced with a fresh question. An identical answer retry for the same attempt is accepted without double counting; a different answer retry conflicts. Reloading the practice snapshot does not issue a question, reveal a covered word, or record assistance/audio playback.

This is an online API. Push notifications and offline synchronization are not implemented. There is no generic idempotency key for messages, photos, or token creation: after an uncertain network outcome, reconcile against current server data before creating another record. Routine/game-launch retries with a stale version conflict, and repeated submissions do not increment a submitted revision. Do not silently replace stale versions and replay student actions.


## Native parent API (v1)

All parent paths are relative to `/api/v1/parent`. This covers the current parent website workflows, plus atomic bulk planning, incremental activity history, and fact reports. This application still represents **one family and one configured student**; it is not a multi-family authorization system. Parents operate on the student selected by the existing `ParentActions::student()` service. Account provisioning and password resets remain trusted host CLI operations.

### Parent authentication

Use the same login body and response format as the student API, but send `POST /api/v1/parent/login` with a parent account. Store the bearer token in Keychain. The same private response headers, 30-day expiry, password-change invalidation, and JSON error/status conventions apply. Login has its own six-per-minute IP counter; authenticated parent traffic has a separate 240-per-minute account counter, with parent messages separately limited to 30/minute.

| Method | Path | Behavior |
| --- | --- | --- |
| POST | `/login` | `email`, `password`, `device_name`; issues a parent token (201) |
| GET | `/me` | Current parent identity |
| GET | `/devices` | This parent's active devices; never Noah's or another parent's credentials |
| DELETE | `/devices/{id}` | Revoke one owned device |
| POST | `/logout` | Revoke the current token |
| POST | `/logout-all` | Revoke all of this parent's tokens |

Tokens record the role at issuance. Both the token role and current account role must match the requested API. Student tokens cannot access parent routes, parent tokens cannot perform student actions, and changing an account role does not elevate an existing token. The role migration preserves existing student tokens by defaulting old records to `student`. Run all pending migrations when deploying this update.

### Monitoring and review

| Method | Path | Input / result |
| --- | --- | --- |
| GET | `/day?date=YYYY-MM-DD` | Same plan/report shape as the student day endpoint, for the configured student. Unread counts belong to the authenticated parent. |
| GET | `/reviews?limit=100&page=1` | Required assignments awaiting review, ordered by submission time; excludes optional rounds. `meta.has_more` and `meta.next_page` control pagination. |
| GET | `/assignments?date=YYYY-MM-DD&extra=0&limit=100&page=1` | Plan listing; `extra=1` selects extra rounds. Same pagination as student listing. |
| GET | `/assignments/{id}` | Assignment, student note, feedback, submission revision, and private photo metadata. `meta.history` contains assignment activity history. |
| GET | `/photos/{id}` | Private worksheet image bytes; attach the parent bearer token. Parent assignment/photo resources return parent API photo URLs. |
| POST | `/assignments/{id}/review` | Required `revision` and `decision` (`approved` or `needs_changes`); optional `feedback`, required for corrections. Returns the reviewed assignment. |
| GET | `/activity?after=ID&limit=100` | Relevant student/assignment and current parent activity. Without `after`, returns latest history; with `after`, returns the next batch. Results are oldest first with `meta.has_more` and `meta.next_after`. |
| POST | `/heartbeat` | Current student contact, routine, unread/review counts, message read receipt cursor, and activity cursor. Does **not** update Noah's contact or start/stop activity. |
| GET | `/practice/{assignment_id}` | Read-only practice report plus assignment; multiplication also returns fact history. No questions are issued and no answers, assistance, audio plays, or time are recorded. |
| GET | `/facts` | Multiplication accuracy, independent recall speed, chart assistance, and drill flags across recorded history |

Inspect the current assignment note, revision, and relevant photos before reviewing. Supply that exact revision; stale reviews return 409 without changing status or sending feedback. A correction requires nonblank feedback. Successful reviews record the parent's identity and send the same feedback message as the website. Inspection/listing does not acknowledge messages. Submitted work and approved work remain separate states. Contact and elapsed sessions are not proof of attention or completion.

### Planning and reusable configuration

| Method | Path | Input / result |
| --- | --- | --- |
| POST | `/assignments` | Assignment fields below; creates required work (201) |
| PATCH | `/assignments/{id}` | Partial assignment fields; only unstarted work is editable |
| POST | `/plan` | `date` plus `assignments` array (1–100 items); creates the entire plan atomically (201). The top-level date applies to every item. Omitted positions append after existing required work. |
| POST | `/copy` | `from` and `to` dates; copies required assignments as new work (201), preserving original text and practice configuration, excluding extra rounds |
| POST | `/reorder` | `date` and complete ordered `ids` array for required work on that date; extra rounds are excluded |
| GET | `/books` | All books, including archived records, ordered active first |
| POST | `/books` | Book fields below; creates a book (201) |
| PATCH | `/books/{id}` | Partial book fields; `active: false` archives it |
| GET | `/games` | All games, including archived records, configurations, active flags, round counts, and latest round IDs |
| POST | `/games` | Game fields below; creates a library game (201) |
| PATCH | `/games/{id}` | Partial game fields; `active: false` archives it |
| GET | `/recurrences` | All recurrence rules, including stopped rules; returns start/end dates, weekdays, assignment configuration, and active flag |
| POST | `/recurrences` | Assignment fields plus `weekdays` and optional `ends_on`; `date` is the start date (201) |
| POST | `/recurrences/{id}/stop` | Stop future generation while preserving existing assignments |

Assignment fields: `date`, `activity_type`, `book_id`, `subject`, `title`, `instructions`, `reference`, `minutes`, `requires_photo`, `position`, `fact_min`, `fact_max`, `question_target`, and `spelling_words`. A normal assignment needs date, subject, title, and instructions. Photo requirement defaults false and position defaults zero for a single create. A `book_id` fills omitted book defaults; specify the actual lesson/reference because it does not advance automatically. Multiplication/spelling assignments have the same targets, bounds, word normalization, and defaults as the CLI. Spelling words accept an array or one-per-line string. Partial updates preserve unspecified fields. Status, student ownership, revisions, approval, and arbitrary fields cannot be set through planning inputs.

Example bulk plan:

```json
{
  "date": "2026-11-04",
  "assignments": [
    {"subject":"Reading","title":"Chapter 4","instructions":"Read the chapter and tell Dad what happened."},
    {"activity_type":"multiplication","fact_min":2,"fact_max":6,"question_target":20},
    {"activity_type":"spelling","spelling_words":["friend","because","another"],"question_target":15}
  ]
}
```

Book fields: `subject`, `title`, optional `publisher`, `level`, `reference`, `instructions`, `minutes`, `requires_photo`, `active`. Game fields: `activity_type` (`multiplication` or `spelling`), `title`, `instructions`, `minutes`, `question_target`, `fact_min`, `fact_max`, `spelling_words`, `active`. Book edits preserve existing assignment text; game edits preserve existing round snapshots. Recurrence weekdays use ISO day numbers 1–7 (Monday–Sunday); optional end date cannot precede the start. Assignment deletion, recurrence editing, and new game mechanics are not existing parent features and are not exposed by this API.

### Parent messages

`GET /messages`, `GET /messages?after=ID`, `GET /messages?before=ID`, and `POST /messages/read` use the same pagination, envelopes, and explicit `through` acknowledgment as the student API. They operate on the configured student's family conversation, with `is_mine`, unread counts, and sender identity relative to the signed-in parent. `POST /messages` accepts `body` and optional owned `assignment_id`; the server supplies the parent sender identity. Another parent's device sessions remain private even though parents share the family conversation.

### Audited timeline corrections

| Method | Path | Input / result |
| --- | --- | --- |
| GET | `/timeline?date=YYYY-MM-DD` | Routine report with current version, day ID, sessions/IDs, totals, and correction audit history |
| POST | `/timeline/{day_id}/periods` | Add a missing period: `version`, `kind` (`work`, `break`, `idle`), `started_at`, `ended_at`, `reason`; work requires an owned `assignment_id` |
| PATCH | `/timeline/{day_id}/periods/{session_id}` | Correct an existing period: `version`, `started_at`, `ended_at`, `reason`, optional `kind` and `assignment_id` |
| PATCH | `/timeline/{day_id}` | Correct day boundaries: `version`, `started_at`, optional `finished_at`, and `reason` |

Use explicit-offset ISO 8601 timestamps and the report's current version. Successful correction responses return the updated report and version. Overlapping/out-of-bounds periods fail with 422; stale versions fail with 409. Sessions must belong to the specified day, and all records must belong to the configured student. Each successful correction preserves before/after values, reason, and editor. These actions do not impersonate Noah's day/activity buttons or infer missing work time. A closed day cannot be reopened through a correction.

Bulk planning is atomic: one invalid item rolls back the whole plan and its audit entries. As with the student API, parent creates, copy, messages, and configuration writes have no generic retry/idempotency key: reconcile server state after uncertain network outcomes before creating again. Reviews require the inspected submission revision; timeline corrections require the read routine version. Offline synchronization and push delivery remain unimplemented.
