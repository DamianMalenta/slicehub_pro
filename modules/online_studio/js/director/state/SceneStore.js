/**
 * SceneStore — single source of truth for DishSceneSpec.
 * Immutable-style: every mutation creates a new spec object,
 * pushes to HistoryStack, and fires onChange subscribers.
 */
import { HistoryStack } from './HistoryStack.js';

export function createDefaultSpec(dishMeta = {}) {
    return {
        stage: {
            boardUrl: '',
            aspect: '16/10',
            lightX: 50, lightY: 15,
            grainIntensity: 6,
            vignetteIntensity: 35,
            lutName: 'none',
            letterbox: 0,
        },
        pizza: {
            x: 25, y: 55,
            scale: 1.2,
            rotation: 0,
            visible: true,
            layers: [],
        },
        companions: [],
        infoBlock: {
            x: 55, y: 8, w: 42, h: 38,
            theme: 'glass-dark',
            align: 'left',
            bgOpacity: 0.85,
            visible: true, locked: false,
        },
        modifierGrid: {
            position: 'below-stage',
            style: 'chips',
            density: 'comfortable',
            visible: true,
        },
        ambient: {
            crumbs: { count: 0, seed: dishMeta.name || 'default' },
            steam: { count: 0, intensity: 50 },
            oilSheen: { enabled: false, x: 45, y: 35 },
        },
    };
}

export class SceneStore {
    constructor() {
        this._spec = createDefaultSpec();
        this._history = new HistoryStack(50);
        this._listeners = new Set();
        this._dishMeta = {};
        this._history.push(structuredClone(this._spec));
    }

    get spec() { return this._spec; }
    get dishMeta() { return this._dishMeta; }

    load(spec, dishMeta = {}) {
        this._dishMeta = dishMeta;
        this._spec = structuredClone(spec);
        this._history.clear();
        this._history.push(structuredClone(this._spec));
        this._fire();
    }

    /** Apply a partial patch to spec. Deep-merges one level. */
    patch(path, value, label = '') {
        const next = structuredClone(this._spec);
        const keys = path.split('.');
        let target = next;
        for (let i = 0; i < keys.length - 1; i++) {
            if (target[keys[i]] === undefined) target[keys[i]] = {};
            target = target[keys[i]];
        }
        const last = keys[keys.length - 1];
        if (typeof value === 'object' && !Array.isArray(value) && value !== null
            && typeof target[last] === 'object' && !Array.isArray(target[last])) {
            target[last] = { ...target[last], ...value };
        } else {
            target[last] = value;
        }
        this._spec = next;
        this._history.push(structuredClone(next), label);
        this._fire();
    }

    /** Replace entire spec (for presets, magic enhance). */
    replace(spec, label = '') {
        this._spec = structuredClone(spec);
        this._history.push(structuredClone(spec), label);
        this._fire();
    }

    undo() {
        const prev = this._history.undo();
        if (prev) { this._spec = structuredClone(prev); this._fire(); return true; }
        return false;
    }

    redo() {
        const next = this._history.redo();
        if (next) { this._spec = structuredClone(next); this._fire(); return true; }
        return false;
    }

    canUndo() { return this._history.canUndo(); }
    canRedo() { return this._history.canRedo(); }
    historyLabels() { return this._history.labels(); }

    onChange(fn) { this._listeners.add(fn); return () => this._listeners.delete(fn); }

    _fire() { this._listeners.forEach(fn => fn(this._spec)); }
}
