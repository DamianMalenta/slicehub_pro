/**
 * PromotionsPanel — workspace "Promotions": biblioteka sh_promotions + sloty na scenie dania.
 */
import { StudioApi } from '../../studio_api.js';

const RULE_KINDS = [
    { id: 'discount_percent', label: 'Rabat %' },
    { id: 'discount_amount', label: 'Kwota PLN' },
    { id: 'combo_half_price', label: 'Combo ½ ceny' },
    { id: 'free_item_if_threshold', label: 'Gratis od progu' },
    { id: 'bundle', label: 'Pakiet' },
];

export class PromotionsPanel {
    constructor(container, directorApp) {
        this._el = container;
        this._app = directorApp;
        this._promotions = [];
        this._slots = [];
        this._sceneId = null;
        this._renderShell();
    }

    _renderShell() {
        this._el.classList.add('pr-promotions');
        this._el.innerHTML = `
            <div class="pr-pad">
                <div class="pr-head"><i class="fa-solid fa-tags"></i><span>Promocje na scenie</span></div>
                <div id="pr-body" class="pr-body"></div>
            </div>`;
    }

    async refresh() {
        const sku = this._app?._selectedDishSku;
        const body = this._el.querySelector('#pr-body');
        if (!body) return;

        if (!sku) {
            body.innerHTML = `<div class="pr-empty">Wybierz danie z listy po lewej.</div>`;
            return;
        }

        body.innerHTML = `<div class="pr-loading"><i class="fa-solid fa-spinner fa-spin"></i> Ładowanie…</div>`;

        const [pr, sl] = await Promise.all([
            StudioApi.promotionsList(),
            StudioApi.scenePromotionSlotsGet(sku),
        ]);

        this._promotions = pr.success && pr.data?.promotions ? pr.data.promotions : [];
        this._slots = sl.success && sl.data?.slots ? sl.data.slots : [];
        this._sceneId = sl.success ? sl.data?.sceneId ?? null : null;

        this._renderMain(sku);
    }

    _renderMain(sku) {
        const body = this._el.querySelector('#pr-body');
        const sceneHint = !this._sceneId
            ? `<div class="pr-warn"><i class="fa-solid fa-triangle-exclamation"></i> Najpierw zapisz scenę dania (Ctrl+S / Zapisz), żeby powstał rekord <code>sh_atelier_scenes</code> — wtedy przypniesz promocje.</div>`
            : `<div class="pr-ok"><i class="fa-solid fa-link"></i> Scena #${this._sceneId}</div>`;

        const slotRows = this._slots.length
            ? this._slots
                  .map((s, i) => {
                      const opts = this._promoSelectOptions(s.promotionId);
                      return `
                <div class="pr-slot-row" data-idx="${i}">
                    <select class="pr-slot-promo input">${opts}</select>
                    <label class="pr-mini">X<input type="number" class="input pr-slot-x" step="0.1" min="0" max="100" value="${s.slotX}"></label>
                    <label class="pr-mini">Y<input type="number" class="input pr-slot-y" step="0.1" min="0" max="100" value="${s.slotY}"></label>
                    <label class="pr-mini">z<input type="number" class="input pr-slot-z" step="1" min="0" max="500" value="${s.slotZIndex}"></label>
                    <button type="button" class="btn btn--ghost pr-slot-del" title="Usuń"><i class="fa-solid fa-xmark"></i></button>
                </div>`;
                  })
                  .join('')
            : `<div class="pr-muted">Brak slotów — dodaj pierwszy.</div>`;

        body.innerHTML = `
            ${sceneHint}
            <div class="pr-section">
                <div class="pr-section__title">Sloty na scenie (pozycja %)</div>
                <p class="pr-hint">X/Y jak w Scenography — 0–100% powierzchni kadru (badge promocji).</p>
                <div id="pr-slots">${slotRows}</div>
                <div class="pr-actions">
                    <button type="button" class="btn" id="pr-add-slot"><i class="fa-solid fa-plus"></i> Dodaj slot</button>
                    <button type="button" class="btn btn--accent" id="pr-save-slots" ${!this._sceneId ? 'disabled' : ''}>
                        <i class="fa-solid fa-floppy-disk"></i> Zapisz sloty
                    </button>
                </div>
            </div>
            <div class="pr-section">
                <div class="pr-section__title">Biblioteka promocji</div>
                <div class="pr-promo-list">${this._renderPromoCards()}</div>
                <button type="button" class="btn" id="pr-new-promo"><i class="fa-solid fa-plus"></i> Nowa promocja</button>
            </div>
        `;

        body.querySelector('#pr-add-slot').onclick = () => this._addEmptySlot();
        body.querySelector('#pr-save-slots').onclick = () => this._saveSlots(sku);
        body.querySelector('#pr-new-promo').onclick = () => this._openPromoModal(null);

        body.querySelectorAll('.pr-slot-del').forEach((btn) => {
            btn.onclick = () => btn.closest('.pr-slot-row')?.remove();
        });

        body.querySelectorAll('.pr-edit-promo').forEach((btn) => {
            btn.onclick = () => {
                const id = parseInt(btn.dataset.id, 10);
                const p = this._promotions.find((x) => x.id === id);
                if (p) this._openPromoModal(p);
            };
        });
    }

    _promoSelectOptions(selectedId = 0) {
        let o = '<option value="">— wybierz promocję —</option>';
        const sid = Number(selectedId);
        this._promotions
            .filter((p) => p.isActive !== false)
            .forEach((p) => {
                const sel = Number(p.id) === sid ? 'selected' : '';
                o += `<option value="${p.id}" ${sel}>${this._esc(p.name)} (${this._esc(p.asciiKey)})</option>`;
            });
        return o;
    }

    _addEmptySlot() {
        const wrap = this._el.querySelector('#pr-slots');
        if (!wrap) return;
        wrap.querySelector('.pr-muted')?.remove();
        const div = document.createElement('div');
        div.className = 'pr-slot-row';
        div.innerHTML = `
            <select class="pr-slot-promo input">${this._promoSelectOptions(0)}</select>
            <label class="pr-mini">X<input type="number" class="input pr-slot-x" step="0.1" min="0" max="100" value="50"></label>
            <label class="pr-mini">Y<input type="number" class="input pr-slot-y" step="0.1" min="0" max="100" value="50"></label>
            <label class="pr-mini">z<input type="number" class="input pr-slot-z" step="1" min="0" max="500" value="100"></label>
            <button type="button" class="btn btn--ghost pr-slot-del"><i class="fa-solid fa-xmark"></i></button>
        `;
        div.querySelector('.pr-slot-del').onclick = () => div.remove();
        wrap.appendChild(div);
    }

    async _saveSlots(sku) {
        const rows = this._el.querySelectorAll('#pr-slots .pr-slot-row');
        const slots = [];
        rows.forEach((row) => {
            const pid = parseInt(row.querySelector('.pr-slot-promo')?.value, 10);
            if (!pid) return;
            slots.push({
                promotionId: pid,
                slotX: parseFloat(row.querySelector('.pr-slot-x')?.value) || 50,
                slotY: parseFloat(row.querySelector('.pr-slot-y')?.value) || 50,
                slotZIndex: parseInt(row.querySelector('.pr-slot-z')?.value, 10) || 100,
            });
        });
        const r = await StudioApi.scenePromotionSlotsSave(sku, slots);
        if (r.success) {
            this._app._Studio?.toast?.('Sloty promocji zapisane.', 'ok', 2200);
            void this._app._panels.viewport?.reloadPromotionSlots?.();
            await this.refresh();
        } else {
            this._app._Studio?.toast?.(r.message || 'Błąd zapisu', 'err', 3500);
        }
    }

    _renderPromoCards() {
        if (!this._promotions.length) {
            return `<div class="pr-muted">Brak promocji — utwórz pierwszą.</div>`;
        }
        return this._promotions
            .map(
                (p) => `
            <div class="pr-card">
                <div class="pr-card__main">
                    <strong>${this._esc(p.name)}</strong>
                    <span class="pr-card__sku">${this._esc(p.asciiKey)}</span>
                    <span class="pr-badge pr-badge--${this._esc(p.badgeStyle || 'amber')}">${this._esc(p.badgeText || p.ruleKind)}</span>
                </div>
                <button type="button" class="btn btn--xs pr-edit-promo" data-id="${p.id}"><i class="fa-solid fa-pen"></i></button>
            </div>`
            )
            .join('');
    }

    _openPromoModal(existing) {
        const overlay = document.createElement('div');
        overlay.className = 'pr-modal-bg';
        const rk = existing?.ruleKind || 'discount_percent';
        const rule = existing?.rule || {};
        const pct = rule.percent != null ? rule.percent : (rule.discount_percent != null ? rule.discount_percent : 10);
        const amt = rule.amount_zl != null ? rule.amount_zl : '';

        overlay.innerHTML = `
            <div class="pr-modal">
                <div class="pr-modal__head">${existing ? 'Edytuj promocję' : 'Nowa promocja'}</div>
                <div class="pr-modal__body">
                    <label class="pr-field">SKU (ascii_key)<input type="text" id="pm-ascii" class="input" value="${existing ? this._esc(existing.asciiKey) : ''}" ${existing ? 'readonly' : ''} placeholder="PROMO_SUMMER"></label>
                    <label class="pr-field">Nazwa<input type="text" id="pm-name" class="input" value="${existing ? this._esc(existing.name) : ''}" placeholder="Letnia promocja"></label>
                    <label class="pr-field">Reguła
                        <select id="pm-kind" class="input">${RULE_KINDS.map((k) => `<option value="${k.id}" ${k.id === rk ? 'selected' : ''}>${k.label}</option>`).join('')}</select>
                    </label>
                    <div id="pm-rule-fields" class="pr-rule-fields"></div>
                    <label class="pr-field">Tekst badge<input type="text" id="pm-badge" class="input" value="${existing?.badgeText ? this._esc(existing.badgeText) : '-15%'}" maxlength="32"></label>
                    <label class="pr-field">Styl badge
                        <select id="pm-bstyle" class="input">
                            ${['amber', 'neon', 'gold', 'red_burst', 'vintage'].map((s) => `<option value="${s}" ${(existing?.badgeStyle || 'amber') === s ? 'selected' : ''}>${s}</option>`).join('')}
                        </select>
                    </label>
                </div>
                <div class="pr-modal__foot">
                    <button type="button" class="btn" id="pm-cancel">Anuluj</button>
                    <button type="button" class="btn btn--accent" id="pm-save">Zapisz</button>
                </div>
            </div>`;

        document.body.appendChild(overlay);

        const ruleBox = overlay.querySelector('#pm-rule-fields');
        const syncRule = () => {
            const kind = overlay.querySelector('#pm-kind').value;
            if (kind === 'discount_percent') {
                ruleBox.innerHTML = `<label class="pr-field">Procent <input type="number" id="pm-pct" class="input" min="1" max="99" value="${pct}"></label>`;
            } else if (kind === 'discount_amount') {
                ruleBox.innerHTML = `<label class="pr-field">Kwota PLN <input type="number" id="pm-amt" class="input" step="0.01" min="0" value="${amt || '5'}"></label>`;
            } else {
                ruleBox.innerHTML = `<label class="pr-field">rule_json ( uproszczony — edytuj później w DB jeśli potrzeba )<textarea id="pm-raw" class="input" rows="3">{}</textarea></label>`;
            }
        };
        syncRule();
        overlay.querySelector('#pm-kind').onchange = syncRule;

        const close = () => overlay.remove();
        overlay.querySelector('#pm-cancel').onclick = close;
        overlay.onclick = (e) => {
            if (e.target === overlay) close();
        };

        overlay.querySelector('#pm-save').onclick = async () => {
            const asciiKey = overlay.querySelector('#pm-ascii').value.trim();
            const name = overlay.querySelector('#pm-name').value.trim();
            const ruleKind = overlay.querySelector('#pm-kind').value;
            let rule = {};
            if (ruleKind === 'discount_percent') {
                rule = { percent: parseFloat(overlay.querySelector('#pm-pct')?.value) || 10 };
            } else if (ruleKind === 'discount_amount') {
                rule = { amount_zl: parseFloat(overlay.querySelector('#pm-amt')?.value) || 0 };
            } else {
                try {
                    rule = JSON.parse(overlay.querySelector('#pm-raw')?.value || '{}');
                } catch (_) {
                    rule = {};
                }
            }
            const r = await StudioApi.promotionSave({
                id: existing?.id || 0,
                asciiKey,
                name,
                ruleKind,
                rule,
                badgeText: overlay.querySelector('#pm-badge').value.trim(),
                badgeStyle: overlay.querySelector('#pm-bstyle').value,
                isActive: true,
            });
            if (r.success) {
                this._app._Studio?.toast?.('Promocja zapisana.', 'ok', 2000);
                close();
                await this.refresh();
            } else {
                this._app._Studio?.toast?.(r.message || 'Błąd', 'err', 3500);
            }
        };
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
}
