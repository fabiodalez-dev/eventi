// Pending reads survive a reload when a storage is given, but the server
// alone confirms an admission: offline, the door only knows "in the list".
export class CheckinQueue {
    constructor(send, key = () => crypto.randomUUID(), storage = null) {
        this.send = send;
        this.key = key;
        this.storage = storage;
        this.entries = storage?.read() ?? [];
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

// What the door knows without network: hashed codes, names, last known state.
// Codes never reach the browser in clear; the page hashes what it scans.
export class CheckinManifest {
    constructor(storage, digest = sha256Hex) {
        this.storage = storage;
        this.digest = digest;
        this.data = storage?.read() ?? null;
    }
    load(payload) {
        this.data = { generatedAt: payload.generated_at, tickets: Object.fromEntries(payload.tickets.map((t) => [t.h, { name: t.name, status: t.status }])) };
        this.storage?.write(this.data);
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
    };
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
    if (online) return 'send';
    if (known && status === 'checked_in') return 'reject_used';
    if (loaded && !known) return 'pending_unknown';
    return 'pending';
}
