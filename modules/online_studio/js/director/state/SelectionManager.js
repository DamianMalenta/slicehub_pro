/**
 * SelectionManager — tracks which scene element is selected.
 * Types: 'stage' | 'pizza' | 'layer' | 'companion' | 'infoBlock' | 'modifierGrid'
 */
export class SelectionManager {
    constructor() {
        this._type = 'stage';
        this._id = null;
        this._listeners = new Set();
    }

    get type() { return this._type; }
    get id() { return this._id; }
    get key() { return this._id ? `${this._type}:${this._id}` : this._type; }

    select(type, id = null) {
        if (this._type === type && this._id === id) return;
        this._type = type;
        this._id = id;
        this._fire();
    }

    deselect() { this.select('stage', null); }

    isSelected(type, id = null) {
        return this._type === type && this._id === id;
    }

    onChange(fn) { this._listeners.add(fn); return () => this._listeners.delete(fn); }

    _fire() { this._listeners.forEach(fn => fn(this._type, this._id)); }
}
