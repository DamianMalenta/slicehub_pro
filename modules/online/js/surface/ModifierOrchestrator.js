/**
 * ModifierOrchestrator — single place for modifier chip state + micro-animation.
 *
 * Contract:
 *   0 -> 1 = materialize scatter layer
 *   1 -> 2 = upgrade to hero bubble
 *   2 -> 0 = dematerialize all visuals for this modifier
 */
export class ModifierOrchestrator {
    constructor(ctx, handlers = {}) {
        this._ctx = ctx;
        this._handlers = handlers;
        this._reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    }

    bind(root) {
        if (!root) return;
        root.querySelectorAll('.sc-mod').forEach((btn) => {
            btn.addEventListener('click', () => this.cycle(btn));
        });
    }

    cycle(btn) {
        const sku = btn.dataset.sku;
        const cur = this._ctx.modCounts.get(sku) || 0;
        const next = (cur + 1) % 3;

        if (next === 0) {
            this._ctx.modCounts.delete(sku);
        } else {
            this._ctx.modCounts.set(sku, next);
        }

        btn.dataset.count = String(next);
        btn.classList.toggle('sc-mod--on', next > 0);
        btn.classList.toggle('sc-mod--hot', next === 2);
        const countEl = btn.querySelector('.sc-mod__count');
        if (countEl) {
            countEl.textContent = next > 0 ? '×' + next : '';
        }

        this._ctx._lastModChange = { sku, prev: cur, next, ts: Date.now() };
        this._playChipAnimation(btn, cur, next);
        this._emit(btn, sku, cur, next);
        this._handlers.onModifierChange?.(sku, next, cur);
    }

    _playChipAnimation(btn, prev, next) {
        if (this._reducedMotion || !btn?.animate) {
            return;
        }
        const keyframes = next === 0
            ? [
                { transform: 'scale(1)', filter: 'blur(0px)' },
                { transform: 'scale(.92)', filter: 'blur(1px)' },
                { transform: 'scale(1)', filter: 'blur(0px)' },
            ]
            : prev === 1 && next === 2
                ? [
                    { transform: 'scale(.92)' },
                    { transform: 'scale(1.08)' },
                    { transform: 'scale(1)' },
                ]
                : [
                    { transform: 'scale(.96)' },
                    { transform: 'scale(1.04)' },
                    { transform: 'scale(1)' },
                ];
        btn.animate(keyframes, {
            duration: next === 2 ? 320 : 240,
            easing: 'cubic-bezier(.2,.9,.3,1.4)',
        });
    }

    _emit(btn, sku, prev, next) {
        try {
            btn.dispatchEvent(new CustomEvent('sh:mod-toggled', {
                detail: { sku, prev, next },
                bubbles: true,
            }));
        } catch (_) {
            // ignore older engines
        }
    }
}
