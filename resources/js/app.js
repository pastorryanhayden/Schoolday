import Alpine from "alpinejs";
window.Alpine = Alpine;
async function api(url, data = {}, method = "POST") {
    const headers = {
        Accept: "application/json",
        "X-CSRF-TOKEN": document.querySelector("meta[name=csrf-token]").content,
    };
    if (!(data instanceof FormData))
        headers["Content-Type"] = "application/json";
    const response = await fetch(url, {
        method,
        headers,
        body: data instanceof FormData ? data : JSON.stringify(data),
    });
    let result;
    try {
        result = await response.json();
    } catch {
        throw new Error("The server could not save this. Please retry.");
    }
    if (!response.ok)
        throw new Error(
            Object.values(result.errors ?? {}).flat()[0] ??
                result.message ??
                "Could not save. Please retry.",
        );
    return result;
}
// Pending image blobs stay in IndexedDB until the server confirms a successful upload.
function photoDB() {
    return new Promise((resolve, reject) => {
        const r = indexedDB.open("schoolday-drafts", 1);
        r.onupgradeneeded = () =>
            r.result.createObjectStore("photos", { keyPath: "id" });
        r.onsuccess = () => resolve(r.result);
        r.onerror = () =>
            reject(
                new Error(
                    "Device storage is unavailable. Keep this page open.",
                ),
            );
    });
}
async function photoStore(method, value) {
    const db = await photoDB();
    return new Promise((resolve, reject) => {
        const t = db.transaction(
            "photos",
            method === "getAll" ? "readonly" : "readwrite",
        );
        const store = t.objectStore("photos");
        const r = value === undefined ? store[method]() : store[method](value);
        t.oncomplete = () => {
            resolve(r.result);
            db.close();
        };
        t.onerror = () => {
            reject(t.error);
            db.close();
        };
    });
}
Alpine.data("shell", (authenticated, latest, routine) => ({
    routineStale: false,
    routineVersion: routine?.version ?? 0,
    routineLabel: routine?.label ?? "Schoolday is not running",
    online: navigator.onLine,
    changed: false,
    unread: 0,
    readThrough: 0,
    nowMs: Date.now(),
    elapsedSince(start) {
        const minutes = Math.max(
            0,
            Math.floor((this.nowMs - Date.parse(start)) / 60000),
        );
        return minutes < 60
            ? minutes + " min elapsed"
            : Math.floor(minutes / 60) +
                  " hr " +
                  (minutes % 60) +
                  " min elapsed";
    },
    lastSeen: null,
    onBreak: false,
    tabletStatus: "Checking…",
    lastContact: "Checking device contact…",
    async init() {
        window.addEventListener('routine-sync', (event) => {
            if (!routine || event.detail.version >= routine.version) {
                routine = event.detail;
                this.routineStale = false;
                this.routineVersion = routine.version;
                this.routineLabel = routine.label;
            }
        });
        window.addEventListener("online", () => {
            this.online = true;
        });
        window.addEventListener("offline", () => {
            this.online = false;
        });
        if (authenticated) {
            const poll = async () => {
                if (document.hidden) return;
                try {
                    const data = await api("/heartbeat");
                    this.online = true;
                    this.unread = data.unread;
                    this.readThrough = data.read_through;
                    this.nowMs = Date.now();
                    if (routine && data.routine.version > routine.version) this.routineStale = true;
                    if (!routine || data.routine.version >= routine.version) this.routineLabel = data.routine.label;
                    this.lastSeen = data.last_seen;
                    this.onBreak = data.on_break;
                    const elapsed = data.last_seen
                        ? Math.floor(
                              (Date.now() - Date.parse(data.last_seen)) / 1000,
                          )
                        : null;
                    this.tabletStatus = elapsed !== null && elapsed < 90
                          ? "Connected"
                          : "No recent contact";
                    this.lastContact =
                        elapsed === null
                            ? "Waiting for a device to check in."
                            : "Last contact " +
                              (elapsed < 60
                                  ? elapsed + " seconds ago"
                                  : Math.floor(elapsed / 60) + " minutes ago");
                    if (data.latest > latest) this.changed = true;
                } catch {
                    this.online = false;
                }
            };
            await poll();
            setInterval(poll, 15000);
        }
        if ("serviceWorker" in navigator)
            navigator.serviceWorker.register("/sw.js").catch(() => {});
    },
}));
Alpine.data("dashboard", (unread, through) => ({
    tab: "overview",
    recurring: false,
    help: false,
    readVisible: false,
    init() {
        this.unread = unread;
        this.help = new URLSearchParams(location.search).get("help") === "1";
        const set = () => {
            this.tab = ["messages", "planner", "activity"].includes(
                location.hash.slice(1),
            )
                ? location.hash.slice(1)
                : "overview";
            if (this.tab === "messages") this.markRead();
        };
        set();
        window.addEventListener("hashchange", set);
    },
    async markRead() {
        if (document.hidden || !through) return;
        try {
            await api("/messages/read", { through });
            this.unread = 0;
            this.readVisible = true;
        } catch {}
    },
}));
Alpine.data("messageDraft", (user, assignment = "general") => ({
    body: "",
    sending: false,
    key: `message-${user}-${assignment}`,
    init() {
        try {
            this.body = localStorage.getItem(this.key) ?? "";
        } catch {}
        if (
            document
                .querySelector(".flash.success")
                ?.textContent.includes("Message sent")
        ) {
            this.body = "";
            localStorage.removeItem(this.key);
        }
        window.addEventListener("pageshow", () => (this.sending = false));
    },
    save() {
        try {
            localStorage.setItem(this.key, this.body);
        } catch {}
    },
}));
Alpine.data("ordering", (ids) => ({
    ids,
    error: "",
    async move(id, delta) {
        let i = this.ids.indexOf(id),
            j = i + delta;
        if (j < 0 || j >= this.ids.length) return;
        const next = [...this.ids];
        [next[i], next[j]] = [next[j], next[i]];
        try {
            await api("/parent/reorder", { ids: next });
            this.ids = next;
            next.forEach((id) =>
                this.$root.appendChild(
                    this.$root.querySelector(`[data-assignment="${id}"]`),
                ),
            );
        } catch (e) {
            this.error = e.message;
        }
    },
}));
Alpine.data("work", (id, user, serverNote, photos, revision, editable) => ({
    photos,
    revision,
    note: serverNote,
    zoom: null,
    scale: 100,
    busy: 0,
    error: "",
    draftStatus: "Draft saved on server",
    submitting: false,
    timer: null,
    key: `work-${user}-${id}`,
    async init() {
        if (!editable) return;
        try {
            const draft = localStorage.getItem(this.key);
            if (draft !== null) {
                this.note = draft;
                this.draftStatus = "Draft restored from this device";
            }
        } catch {}
        try {
            for (const p of await photoStore("getAll"))
                if (p.key === this.key)
                    this.photos.push({
                        ...p,
                        url: URL.createObjectURL(p.file),
                        pending: true,
                    });
        } catch (e) {
            this.error = e.message;
        }
        window.addEventListener("beforeunload", (e) => {
            if (this.busy) {
                e.preventDefault();
                e.returnValue = "";
            }
        });
    },
    saveDraft() {
        try {
            localStorage.setItem(this.key, this.note);
            this.draftStatus = "Saved on this device · saving to server…";
        } catch {
            this.draftStatus =
                "Device storage unavailable · keep this page open";
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(async () => {
            try {
                await api(`/assignments/${id}/draft`, { note: this.note });
                this.draftStatus = "Draft saved securely";
            } catch (e) {
                this.draftStatus = "Saved on this device · server save failed";
                this.error = e.message;
            }
        }, 600);
    },
    async add(files) {
        for (const file of files) {
            if (
                !["image/jpeg", "image/png", "image/webp"].includes(
                    file.type,
                ) ||
                file.size > 10 * 1024 * 1024
            ) {
                this.error = "Choose a JPG, PNG or WebP photo up to 10 MB.";
                continue;
            }
            if (this.photos.length >= 20) {
                this.error = "Maximum 20 photos per assignment.";
                break;
            }
            const p = {
                id:
                    crypto.randomUUID?.() ??
                    "draft-" +
                        Date.now() +
                        "-" +
                        Math.random().toString(36).slice(2),
                name: file.name,
                file,
                key: this.key,
                pending: true,
                revision: revision + 1,
                url: URL.createObjectURL(file),
            };
            this.photos.push(p);
            try {
                await photoStore("put", {
                    id: p.id,
                    name: p.name,
                    file,
                    key: this.key,
                    revision: p.revision,
                });
            } catch (e) {
                this.error = e.message;
            }
            await this.upload(p);
        }
    },
    async upload(p) {
        if (p.uploading) return;
        p.uploading = true;
        this.busy++;
        this.error = "";
        const data = new FormData();
        data.append("photo", p.file);
        try {
            const saved = await api(`/assignments/${id}/photos`, data);
            try {
                await photoStore("delete", p.id);
            } catch {}
            const index = this.photos.findIndex((photo) => photo.id === p.id);
            URL.revokeObjectURL(p.url);
            this.photos[index] = {
                ...saved,
                revision: revision + 1,
                pending: false,
            };
        } catch (e) {
            this.error =
                e.message +
                " Your photo is still on this device. Retry the upload.";
        } finally {
            p.uploading = false;
            this.busy--;
        }
    },
    async remove(p) {
        if (p.uploading) return;
        try {
            if (p.pending) {
                await photoStore("delete", p.id);
                URL.revokeObjectURL(p.url);
            } else await api(`/photos/${p.id}`, {}, "DELETE");
            this.photos = this.photos.filter((photo) => photo.id !== p.id);
        } catch (e) {
            this.error = e.message;
        }
    },
}));
Alpine.data('bookPicker', (books, initialType = 'assignment') => ({
  activityType: initialType,
  chooseType() {
    if (this.activityType !== 'multiplication') return;
    const defaults = {book_id:'',subject:'Math',title:'Multiplication practice',instructions:'Practice accurate recall, one fact at a time.',reference:'',minutes:5};
    for (const [field,value] of Object.entries(defaults)) {
      const input = this.$root.querySelector(`[name="${field}"]`);
      if (input) input.value = value;
    }
    this.$root.querySelector('input[type="checkbox"][name="requires_photo"]').checked = false;
  },
  apply(id) {
    const book = books.find((item) => String(item.id) === String(id));
    if (!book) return;
    for (const field of ['title', 'subject', 'reference', 'instructions', 'minutes']) {
      const input = this.$root.querySelector(`[name="${field}"]`);
      if (input) input.value = book[field] ?? (field === 'instructions' ? 'Complete the assigned lesson or pages.' : '');
    }
    const evidence = this.$root.querySelector('input[type="checkbox"][name="requires_photo"]');
    if (evidence) evidence.checked = book.requires_photo;
  }
}));
Alpine.data('multiplication', (config) => ({
    version: config.version,
    summary: config.summary,
    approved: config.status === 'approved',
    mode: config.complete ? 'complete' : 'ready',
    question: null,
    feedback: null,
    answer: '',
    busy: false,
    error: '',
    openedAt: 0,
    feedbackAt: 0,
    chartOpen: false,
    timeout: null,
    pending: null,
    pauseRequested: false,
    init() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) this.interrupted();
        });
        window.addEventListener('blur', () => this.interrupted());
    },
    append(digit) {
        this.answer = (this.answer + digit).slice(0, 3);
    },
    interrupted() {
        if (!['question', 'feedback'].includes(this.mode)) return;
        clearTimeout(this.timeout);
        if (this.busy) this.pauseRequested = true;
        else this.pause('Paused while you were away. Resume for a fresh question.');
    },
    async request(action, extra = {}) {
        if (this.busy) return;
        this.busy = true;
        this.error = '';
        clearTimeout(this.timeout);
        try {
            const result = await api(config.url, {action, version: this.version, ...extra});
            this.version = result.state.version;
            this.$dispatch('routine-sync', result.state);
            this.summary = result.summary;
            this.feedback = result.result;
            if (result.result) this.feedbackAt = performance.now();
            this.pending = null;
            if (result.complete) this.mode = 'complete';
            else if (action === 'help') {
                this.chartOpen = true;
                this.mode = 'question';
                this.timeout = setTimeout(() => this.pause('Paused after a long gap. Resume when ready.'), Math.max(0,90000-(performance.now()-this.openedAt)));
                this.$nextTick(() => this.$refs.chartClose.focus({preventScroll:true}));
            }
            else if (result.question) {
                this.chartOpen = false;
                this.question = result.question;
                this.answer = '';
                this.mode = 'question';
                this.openedAt = performance.now();
                this.timeout = setTimeout(() => this.pause('Paused after a long gap. Resume when you’re ready.'), 90000);
                this.$nextTick(() => this.$refs.answer.focus({preventScroll:true}));
            } else this.mode = result.result && result.running ? 'feedback' : 'paused';
        } catch (error) {
            this.error = error.message;
            this.mode = action === 'answer' ? 'unsaved' : 'paused';
        } finally {
            this.busy = false;
            if (this.pauseRequested || (document.hidden && ['question','feedback'].includes(this.mode))) {
                this.pauseRequested = false;
                if (['question','feedback'].includes(this.mode)) await this.pause('Paused while you were away.');
            }
        }
    },
    help() { return this.request('help', {attempt_id:this.question.id}); },
    closeChart() { this.chartOpen=false; this.$nextTick(() => this.$refs.answer.focus({preventScroll:true})); },
    start() { return this.request('start'); },
    next() { return this.request('next', {attempt_id:this.feedback.attempt_id, feedback_ms:Math.max(0, Math.min(5000, Math.round(performance.now()-this.feedbackAt)))}); },
    answerQuestion() {
        if (this.busy || this.answer === '' || this.mode !== 'question') return;
        this.pending = {attempt_id:this.question.id, answer:Number(this.answer), response_ms:Math.max(100,Math.round(performance.now()-this.openedAt))};
        return this.request('answer', this.pending);
    },
    retry() { if (this.pending) return this.request('answer', this.pending); },
    async pause(message = '') {
        if (this.busy) { this.pauseRequested = true; return; }
        this.chartOpen = false;
        this.mode = 'paused';
        await this.request('pause');
        if (message && !this.error) this.error = message;
    },
}));

Alpine.start();
