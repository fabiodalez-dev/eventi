// Pending reads are kept only in this open page. Never claim offline admission.
export class CheckinQueue {
    constructor(send, key = () => crypto.randomUUID()) {
        this.send = send;
        this.key = key;
        this.entries = [];
        this.running = false;
    }
    add(code) {
        if (this.entries.some((entry) => entry.code === code && entry.state === 'pending')) return;
        this.entries.push({ code, requestKey: this.key(), state: 'pending', message: '' });
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
                    changed();
                    break;
                }
                changed();
            }
        } finally {
            this.running = false;
        }
    }
}
