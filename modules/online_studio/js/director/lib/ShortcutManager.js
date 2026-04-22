/**
 * ShortcutManager — keyboard shortcut registry for Director.
 * Handles modifier keys, prevents conflicts with text inputs,
 * and provides a legend modal.
 */
export class ShortcutManager {
    constructor() {
        this._bindings = new Map();
        this._enabled = true;
        this._handler = this._onKeyDown.bind(this);
        document.addEventListener('keydown', this._handler);
    }

    /**
     * Register a shortcut.
     * @param {string} combo - e.g. 'v', 'ctrl+z', 'ctrl+shift+z', 'delete', '['
     * @param {string} description - human-readable
     * @param {Function} fn - callback(event)
     */
    register(combo, description, fn) {
        this._bindings.set(combo.toLowerCase(), { description, fn });
    }

    unregister(combo) {
        this._bindings.delete(combo.toLowerCase());
    }

    enable() { this._enabled = true; }
    disable() { this._enabled = false; }

    destroy() {
        document.removeEventListener('keydown', this._handler);
        this._bindings.clear();
    }

    legend() {
        const entries = [];
        for (const [combo, { description }] of this._bindings) {
            entries.push({ combo, description });
        }
        return entries.sort((a, b) => a.combo.localeCompare(b.combo));
    }

    _onKeyDown(e) {
        if (!this._enabled) return;
        const tag = (e.target?.tagName || '').toLowerCase();
        const isTyping = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target?.isContentEditable;

        const parts = [];
        if (e.ctrlKey || e.metaKey) parts.push('ctrl');
        if (e.shiftKey) parts.push('shift');
        if (e.altKey) parts.push('alt');

        let key = e.key.toLowerCase();
        if (key === ' ') key = 'space';
        if (key === 'arrowup') key = 'up';
        if (key === 'arrowdown') key = 'down';
        if (key === 'arrowleft') key = 'left';
        if (key === 'arrowright') key = 'right';
        if (key === 'escape') key = 'esc';

        if (!['ctrl', 'shift', 'alt', 'meta', 'control'].includes(key)) {
            parts.push(key);
        }
        const combo = parts.join('+');

        const binding = this._bindings.get(combo);
        if (!binding) return;

        if (isTyping && !combo.startsWith('ctrl+') && !combo.startsWith('esc')) return;

        e.preventDefault();
        e.stopPropagation();
        binding.fn(e);
    }
}
