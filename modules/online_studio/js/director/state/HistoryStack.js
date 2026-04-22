/**
 * HistoryStack — undo/redo with snapshot labels.
 * Fixed-size ring: oldest entries get evicted when full.
 */
export class HistoryStack {
    constructor(maxSize = 50) {
        this._stack = [];
        this._cursor = -1;
        this._max = maxSize;
    }

    push(snapshot, label = '') {
        this._stack = this._stack.slice(0, this._cursor + 1);
        this._stack.push({ snapshot, label: label || `Step ${this._stack.length + 1}`, ts: Date.now() });
        if (this._stack.length > this._max) this._stack.shift();
        this._cursor = this._stack.length - 1;
    }

    undo() {
        if (!this.canUndo()) return null;
        this._cursor--;
        return this._stack[this._cursor].snapshot;
    }

    redo() {
        if (!this.canRedo()) return null;
        this._cursor++;
        return this._stack[this._cursor].snapshot;
    }

    canUndo() { return this._cursor > 0; }
    canRedo() { return this._cursor < this._stack.length - 1; }

    current() { return this._cursor >= 0 ? this._stack[this._cursor].snapshot : null; }

    labels() {
        return this._stack.map((e, i) => ({
            label: e.label,
            ts: e.ts,
            active: i === this._cursor,
        }));
    }

    clear() { this._stack = []; this._cursor = -1; }
}
