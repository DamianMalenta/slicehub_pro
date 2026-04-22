/**
 * Prosta kolejka zadan z priorytetami i pauza.
 * Przetwarza zadania sekwencyjnie (RPA ma jeden focus).
 */
export class TaskQueue {
  constructor() {
    this.items = [];
    this.running = false;
    this.paused = false;
    this.current = null;
    this.listeners = new Set();
  }

  on(fn) {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  }

  _emit(event) {
    for (const fn of this.listeners) {
      try {
        fn(event);
      } catch {}
    }
  }

  enqueue(task, priority = 5) {
    const item = {
      id: cryptoRandomId(),
      priority,
      task,
      enqueuedAt: Date.now(),
      status: 'pending',
    };
    this.items.push(item);
    this.items.sort((a, b) => a.priority - b.priority);
    this._emit({ type: 'enqueued', item: this._view(item) });
    this._tick();
    return item.id;
  }

  pause() {
    this.paused = true;
    this._emit({ type: 'paused' });
  }

  resume() {
    this.paused = false;
    this._emit({ type: 'resumed' });
    this._tick();
  }

  clear() {
    this.items = [];
    this._emit({ type: 'cleared' });
  }

  snapshot() {
    return {
      paused: this.paused,
      running: this.running,
      current: this.current ? this._view(this.current) : null,
      pending: this.items.map((i) => this._view(i)),
    };
  }

  _view(i) {
    return {
      id: i.id,
      priority: i.priority,
      status: i.status,
      enqueuedAt: i.enqueuedAt,
      label: i.task?.label || 'task',
    };
  }

  async _tick() {
    if (this.running || this.paused) return;
    const next = this.items.shift();
    if (!next) return;
    this.running = true;
    this.current = next;
    next.status = 'running';
    this._emit({ type: 'started', item: this._view(next) });
    try {
      const result = await next.task.run();
      next.status = 'done';
      this._emit({ type: 'completed', item: this._view(next), result });
    } catch (err) {
      next.status = 'failed';
      this._emit({
        type: 'failed',
        item: this._view(next),
        error: err?.message || String(err),
      });
    } finally {
      this.running = false;
      this.current = null;
      setImmediate(() => this._tick());
    }
  }
}

function cryptoRandomId() {
  return Math.random().toString(36).slice(2, 10);
}
