// Pending reads survive a reload when a storage is given, but the server
// alone confirms an admission: offline, the door only knows "in the list".
// Only a pending read keeps its code in clear: once the server has answered,
// the code is dropped, and settled reads older than `keepHours` are forgotten.
// On a shared tablet the next person must not find valid QR codes in storage.
export class CheckinQueue {
    constructor(send, key = () => crypto.randomUUID(), storage = null, keepHours = 24, now = () => Date.now()) {
        this.send = send;
        this.key = key;
        this.storage = storage;
        const oldest = now() - keepHours * 3600000;
        this.entries = (storage?.read() ?? [])
            .filter((entry) => entry.state === 'pending' || Date.parse(entry.scannedAt ?? '') >= oldest)
            .map((entry) => (entry.state === 'pending' ? entry : settle(entry)));
        this.running = false;
    }
    add(code, extra = {}) {
        if (this.entries.some((entry) => entry.code === code && entry.state === 'pending')) return;
        this.entries.push({ code, requestKey: this.key(), state: 'pending', message: '', scannedAt: new Date().toISOString(), ...extra });
        this.persist();
    }
    pending() {
        return this.entries.filter((entry) => entry.state === 'pending');
    }
    persist() {
        this.storage?.write(this.entries);
    }
    async flush(changed = () => {}) {
        if (this.running) return;
        this.running = true;
        try {
            for (const entry of this.entries) {
                if (entry.state !== 'pending') continue;
                try {
                    const result = await this.send(entry);
                    entry.state = result.accepted ? 'accepted' : 'rejected';
                    entry.message = result.message;
                    delete entry.code;
                } catch {
                    // A timeout can follow a committed check-in. Retry the SAME key.
                    this.persist();
                    changed();
                    break;
                }
                this.persist();
                changed();
            }
        } finally {
            this.running = false;
        }
    }
}

function settle(entry) {
    const { code, ...rest } = entry;
    return rest;
}

// What the door knows without network: hashed codes, names, last known state.
// Codes never reach the browser in clear; the page hashes what it scans.
// The names do: the list is thrown away once the door window has closed and
// the late-sync margin has passed, the same rule the Android app applies.
export class CheckinManifest {
    constructor(storage, digest = sha256Hex, lateSyncHours = 24, now = () => Date.now()) {
        this.storage = storage;
        this.digest = digest;
        this.data = storage?.read() ?? null;
        const until = Date.parse(this.data?.until ?? '');
        if (this.data && (!Number.isFinite(until) || now() > until + lateSyncHours * 3600000)) this.clear();
    }
    load(payload) {
        this.data = { generatedAt: payload.generated_at, until: payload.window?.to ?? null, tickets: Object.fromEntries(payload.tickets.map((t) => [t.h, { name: t.name, status: t.status }])) };
        this.storage?.write(this.data);
    }
    clear() {
        this.data = null;
        this.storage?.clear?.();
    }
    get loaded() {
        return this.data !== null;
    }
    get size() {
        return this.data ? Object.keys(this.data.tickets).length : 0;
    }
    async lookup(code) {
        if (!this.data) return { known: false };
        const ticket = this.data.tickets[await this.digest(code)];
        return ticket ? { known: true, ...ticket } : { known: false };
    }
    async markUsed(code) {
        if (!this.data) return;
        const hash = await this.digest(code);
        if (this.data.tickets[hash]) {
            this.data.tickets[hash].status = 'checked_in';
            this.storage?.write(this.data);
        }
    }
}

export function localStorageStore(key) {
    return {
        read() {
            try { return JSON.parse(window.localStorage.getItem(key)) ?? null; } catch { return null; }
        },
        write(value) {
            try { window.localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage full or blocked: memory only */ }
        },
        clear() {
            try { window.localStorage.removeItem(key); } catch { /* blocked: nothing stored */ }
        },
    };
}

// On logout the door lists go, and settled reads with them. Pending reads
// stay: they are admissions the server has not confirmed yet, and losing them
// would turn people who came in into no-shows.
export function forgetCheckinData(storage = window.localStorage) {
    try {
        for (const key of Object.keys(storage).filter((name) => name.startsWith('checkin:'))) {
            if (key.endsWith(':manifest')) {
                storage.removeItem(key);
                continue;
            }
            const pending = (JSON.parse(storage.getItem(key)) ?? []).filter((entry) => entry.state === 'pending');
            if (pending.length > 0) storage.setItem(key, JSON.stringify(pending));
            else storage.removeItem(key);
        }
    } catch { /* storage blocked or corrupt: nothing more to do */ }
}

export async function sha256Hex(text) {
    const bytes = new TextEncoder().encode(text);
    const buffer = await crypto.subtle.digest('SHA-256', bytes);
    return [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

// What the door does with a scan. The manifest helps offline, it is never the
// final word: with network every code goes to the server, which is the only
// one that knows about bookings made after the list was downloaded. Offline,
// a code already used locally is refused, and an unknown one waits for the
// server with a warning not to let the person in yet.
export function classifyScan({ online, loaded, known, status }) {
    if (online && known && status === 'checked_in') return 'send_warn_used';
    if (online && loaded && !known) return 'send_warn_unknown';
    if (online) return 'send';
    if (known && status === 'checked_in') return 'reject_used';
    if (loaded && !known) return 'pending_unknown';
    return 'pending';
}
