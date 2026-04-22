/**
 * DragHandler — pro-grade pointer interaction for Director viewport.
 *
 * Features:
 * - move: delta-based, accumulates in percentage coords of the stage canvas
 * - scale: computed from pointer distance to element center (uniform)
 * - rotate: angle delta around element center
 * - snap-to-grid with Shift (5% steps for position, 15° for rotation)
 * - locked elements skip interactions
 * - cursor feedback per role
 *
 * Percentages are relative to the viewport canvas (the .dt-viewport__canvas element),
 * so all positions persist across viewport resizes / mobile / desktop.
 */
export class DragHandler {
    constructor(viewportEl, { onMove, onScale, onRotate, onSelect, isLocked }) {
        this._vp = viewportEl;
        this._onMove = onMove;
        this._onScale = onScale;
        this._onRotate = onRotate;
        this._onSelect = onSelect;
        this._isLocked = isLocked || (() => false);
        this._dragging = null;

        this._onPointerDown = this._onPointerDown.bind(this);
        this._onPointerMove = this._onPointerMove.bind(this);
        this._onPointerUp = this._onPointerUp.bind(this);

        viewportEl.addEventListener('pointerdown', this._onPointerDown);
        window.addEventListener('pointermove', this._onPointerMove);
        window.addEventListener('pointerup', this._onPointerUp);
        window.addEventListener('pointercancel', this._onPointerUp);
    }

    destroy() {
        this._vp.removeEventListener('pointerdown', this._onPointerDown);
        window.removeEventListener('pointermove', this._onPointerMove);
        window.removeEventListener('pointerup', this._onPointerUp);
        window.removeEventListener('pointercancel', this._onPointerUp);
    }

    _vpRect() { return this._vp.getBoundingClientRect(); }

    _centerOf(el) {
        const r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }

    _pxDeltaToPct(dxPx, dyPx) {
        const r = this._vpRect();
        return {
            dxPct: (dxPx / r.width) * 100,
            dyPct: (dyPx / r.height) * 100,
        };
    }

    _onPointerDown(e) {
        if (e.button !== undefined && e.button !== 0) return;

        const handle = e.target.closest('[data-drag-role]');
        const el = e.target.closest('[data-scene-id]');

        if (handle && el) {
            const role = handle.dataset.dragRole;
            const sceneId = el.dataset.sceneId;
            if (this._isLocked(sceneId)) return;

            e.preventDefault();
            e.stopPropagation();
            const center = this._centerOf(el);
            this._dragging = {
                role, sceneId, el,
                startX: e.clientX, startY: e.clientY,
                lastX: e.clientX, lastY: e.clientY,
                centerX: center.x, centerY: center.y,
                startDist: Math.hypot(e.clientX - center.x, e.clientY - center.y) || 1,
                startAngle: Math.atan2(e.clientY - center.y, e.clientX - center.x),
                lastAngle: Math.atan2(e.clientY - center.y, e.clientX - center.x),
            };
            this._setCursor(role);
            try { this._vp.setPointerCapture(e.pointerId); } catch (_) {}
            return;
        }

        if (el) {
            const sceneId = el.dataset.sceneId;
            e.preventDefault();
            this._onSelect?.(sceneId);
            if (this._isLocked(sceneId)) return;

            this._dragging = {
                role: 'move', sceneId, el,
                startX: e.clientX, startY: e.clientY,
                lastX: e.clientX, lastY: e.clientY,
            };
            this._setCursor('move');
            try { this._vp.setPointerCapture(e.pointerId); } catch (_) {}
            return;
        }

        this._onSelect?.(null);
    }

    _onPointerMove(e) {
        if (!this._dragging) return;
        const d = this._dragging;

        if (d.role === 'move') {
            const dxPx = e.clientX - d.lastX;
            const dyPx = e.clientY - d.lastY;
            const { dxPct, dyPct } = this._pxDeltaToPct(dxPx, dyPx);
            const snap = e.shiftKey ? this._snap(5) : null;
            this._onMove?.(d.sceneId, dxPct, dyPct, { snap });
            d.lastX = e.clientX;
            d.lastY = e.clientY;
        } else if (d.role === 'scale') {
            const dist = Math.hypot(e.clientX - d.centerX, e.clientY - d.centerY) || 1;
            const ratio = dist / d.startDist;
            d.startDist = dist;
            this._onScale?.(d.sceneId, ratio);
        } else if (d.role === 'rotate') {
            const angle = Math.atan2(e.clientY - d.centerY, e.clientX - d.centerX);
            let deltaDeg = ((angle - d.lastAngle) * 180) / Math.PI;
            if (e.shiftKey) {
                deltaDeg = Math.round(deltaDeg / 15) * 15;
                if (deltaDeg === 0) return;
            }
            d.lastAngle = angle;
            this._onRotate?.(d.sceneId, deltaDeg);
        }
    }

    _onPointerUp(e) {
        if (this._dragging) {
            try { this._vp.releasePointerCapture?.(e.pointerId); } catch (_) {}
            this._dragging = null;
            this._setCursor(null);
        }
    }

    _setCursor(role) {
        this._vp.style.cursor = {
            move: 'grabbing',
            scale: 'nwse-resize',
            rotate: 'grabbing',
        }[role] || '';
    }

    _snap(step) { return step; }
}
