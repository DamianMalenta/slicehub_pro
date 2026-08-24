/**
 * SliceHub Online — Checkout Overlay (Faza 5.1).
 *
 * Przepływ:
 *   1. openCheckoutOverlay({ state, api, onSuccess })
 *      - Waliduje że koszyk nie jest pusty.
 *      - Pokazuje formularz (kontakt + dostawa/odbiór + metoda płatności).
 *      - Na submit:
 *        a. init_checkout → lock_token
 *        b. guest_checkout → orderNumber + trackingToken
 *        c. Zapis trackingToken + phone w localStorage (dla Track Order UX).
 *        d. Czyści koszyk.
 *        e. Pokazuje success screen z CTA "Śledź zamówienie".
 *   2. Success screen (embedded w tym samym overlayu) zawiera:
 *      - Numer zamówienia, skrócony podgląd pozycji, kwotę.
 *      - CTA „Śledź zamówienie” → otwiera track.html z tokenem.
 *      - CTA „Zamów więcej” → zamyka overlay i otwiera menu.
 *
 * UI korzysta z CSS zdefiniowanego w style.css (sekcja „Checkout overlay”).
 */

function h(tag, attrs = {}, children = []) {
    const el = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
        if (k === 'class') el.className = v;
        else if (k === 'html') el.innerHTML = v;
        else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
        else if (v !== null && v !== undefined && v !== false) el.setAttribute(k, v === true ? '' : v);
    });
    (Array.isArray(children) ? children : [children]).forEach((c) => {
        if (c == null || c === false) return;
        el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function formatMoneyPl(val) {
    if (val == null || val === '') return '—';
    const n = typeof val === 'string' ? parseFloat(val.replace(',', '.')) : Number(val);
    if (Number.isNaN(n)) return String(val);
    return n.toFixed(2).replace('.', ',') + ' zł';
}

function normalizePhonePl(raw) {
    return String(raw || '').replace(/[^\d+]/g, '').replace(/^\+?48/, '+48');
}

function validateForm(values, orderType) {
    const errs = [];
    if (!values.customerName || values.customerName.trim().length < 2) {
        errs.push({ field: 'customerName', msg: 'Podaj imię.' });
    }
    if (!values.customerPhone || values.customerPhone.replace(/\D/g, '').length < 9) {
        errs.push({ field: 'customerPhone', msg: 'Numer telefonu musi mieć min. 9 cyfr.' });
    }
    if (values.customerEmail && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(values.customerEmail)) {
        errs.push({ field: 'customerEmail', msg: 'Niepoprawny email.' });
    }
    if (orderType === 'delivery') {
        if (!values.deliveryAddress || values.deliveryAddress.trim().length < 5) {
            errs.push({ field: 'deliveryAddress', msg: 'Podaj pełny adres dostawy.' });
        }
    }
    return errs;
}

function renderErrorsInto(containerEl, errors) {
    containerEl.innerHTML = '';
    if (!errors?.length) return;
    containerEl.innerHTML = `<ul class="checkout-errors">${errors
        .map((e) => `<li>${escapeHtml(e.msg)}</li>`)
        .join('')}</ul>`;
}

function lsKey(tenantId, suffix) {
    return `online_${suffix}_${tenantId}`;
}

/**
 * Otwiera overlay finalizacji zamówienia.
 *
 * @param {Object} opts
 * @param {Object} opts.state - Współdzielony stan aplikacji (state.cart, state.channel, state.orderType, state.lastCalc).
 * @param {Object} opts.api - OnlineAPI (import default).
 * @param {Function} opts.onSuccess - Callback po poprawnym checkoucie: (orderData) => void.
 * @param {Function} opts.cartLinesForApi - Funkcja zwracająca tablicę linii w formacie API.
 * @param {Function} opts.persistCart - Funkcja zapisująca koszyk do localStorage.
 * @param {Function} opts.refreshCartUi - Funkcja przerysowująca koszyk.
 */
export function openCheckoutOverlay({ state, api, onSuccess, cartLinesForApi, persistCart, refreshCartUi }) {
    if (!state.cart || state.cart.length === 0) {
        alert('Koszyk jest pusty.');
        return;
    }

    const tenantId = api.getTenantId();
    const existing = document.getElementById('checkout-overlay');
    if (existing) existing.remove();

    const overlay = h('div', { id: 'checkout-overlay', class: 'checkout-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'checkout-title' });
    overlay.innerHTML = '';
    document.body.appendChild(overlay);

    const close = () => {
        if (countdownTimer) clearInterval(countdownTimer);
        overlay.classList.add('is-closing');
        setTimeout(() => overlay.remove(), 200);
    };

    overlay.addEventListener('click', (ev) => {
        if (ev.target === overlay) close();
    });

    document.addEventListener('keydown', function onEsc(e) {
        if (e.key === 'Escape') {
            document.removeEventListener('keydown', onEsc);
            close();
        }
    });

    // Restore saved contact (UX — żeby klient nie wpisywał drugi raz)
    let saved = {};
    try {
        saved = JSON.parse(localStorage.getItem(lsKey(tenantId, 'contact')) || '{}');
    } catch (_) { saved = {}; }

    const orderType = state.orderType || 'delivery';
    const lastCalc = state.lastCalc;
    const grandTotal = lastCalc?.grand_total || '—';
    const itemCount = state.cart.reduce((s, l) => s + (l.qty || 1), 0);

    overlay.innerHTML = `
        <div class="checkout-panel" role="document">
            <header class="checkout-head">
                <div>
                    <p class="checkout-eyebrow">Finalizacja</p>
                    <h2 id="checkout-title" class="checkout-title">Ostatni krok</h2>
                    <p class="checkout-subtitle">${itemCount} ${itemCount === 1 ? 'pozycja' : 'pozycji'} · ${escapeHtml(formatMoneyPl(grandTotal))}</p>
                </div>
                <button type="button" class="checkout-close" aria-label="Zamknij">✕</button>
            </header>

            <form class="checkout-form" id="checkout-form" novalidate>
                <fieldset class="checkout-field-group">
                    <legend class="checkout-legend"><i class="fa-solid fa-user"></i> Dane kontaktowe</legend>
                    <label class="checkout-label">
                        <span>Imię <em>*</em></span>
                        <input type="text" name="customerName" autocomplete="given-name" value="${escapeHtml(saved.customerName || '')}" required minlength="2" maxlength="64">
                    </label>
                    <label class="checkout-label">
                        <span>Telefon <em>*</em></span>
                        <input type="tel" name="customerPhone" autocomplete="tel" value="${escapeHtml(saved.customerPhone || '')}" required minlength="9" maxlength="20" inputmode="tel">
                    </label>
                    <label class="checkout-label">
                        <span>Email <small>(opcjonalnie — wyślemy potwierdzenie)</small></span>
                        <input type="email" name="customerEmail" autocomplete="email" value="${escapeHtml(saved.customerEmail || '')}" maxlength="120">
                    </label>
                </fieldset>

                ${orderType === 'delivery' ? `
                <fieldset class="checkout-field-group">
                    <legend class="checkout-legend"><i class="fa-solid fa-motorcycle"></i> Adres dostawy</legend>
                    <label class="checkout-label">
                        <span>Ulica i numer <em>*</em></span>
                        <input type="text" name="deliveryAddress" autocomplete="street-address" value="${escapeHtml(saved.deliveryAddress || '')}" required minlength="5" maxlength="200" placeholder="np. Marszałkowska 10/4, 00-001 Warszawa">
                    </label>
                    <label class="checkout-label">
                        <span>Uwagi dla kuriera <small>(domofon, piętro, kod bramy…)</small></span>
                        <textarea name="deliveryNotes" rows="2" maxlength="300">${escapeHtml(saved.deliveryNotes || '')}</textarea>
                    </label>
                </fieldset>
                ` : `
                <fieldset class="checkout-field-group">
                    <legend class="checkout-legend"><i class="fa-solid fa-bag-shopping"></i> Odbiór osobisty</legend>
                    <p class="checkout-note">Powiadomimy Cię SMS-em, gdy zamówienie będzie gotowe do odbioru.</p>
                </fieldset>
                `}

                <fieldset class="checkout-field-group" id="checkout-time-group">
                    <legend class="checkout-legend"><i class="fa-solid fa-clock"></i> Czas realizacji</legend>
                    <div class="checkout-payment-grid">
                        <label class="checkout-payment-option">
                            <input type="radio" name="timeMode" value="asap" checked>
                            <span class="checkout-payment-option__body">
                                <strong><i class="fa-solid fa-bolt"></i> Jak najszybciej</strong>
                                <small id="checkout-asap-eta">Szacujemy czas…</small>
                            </span>
                        </label>
                        <label class="checkout-payment-option">
                            <input type="radio" name="timeMode" value="scheduled">
                            <span class="checkout-payment-option__body">
                                <strong><i class="fa-solid fa-calendar-day"></i> Wybierz godzinę</strong>
                                <small>rezerwacja na konkretny czas</small>
                            </span>
                        </label>
                    </div>
                    <div class="checkout-quick-pills" id="checkout-quick-pills">
                        <span class="checkout-quick-pills__label">Szybki wybór <small style="font-weight:600;color:#a8a29e">(od najszybszego)</small>:</span>
                        <button type="button" class="checkout-quick-pill" data-quick-min="30" title="30 min po najszybszym możliwym czasie">+30m</button>
                        <button type="button" class="checkout-quick-pill" data-quick-min="45" title="45 min po najszybszym możliwym czasie">+45m</button>
                        <button type="button" class="checkout-quick-pill" data-quick-min="60" title="60 min po najszybszym możliwym czasie">+60m</button>
                        <button type="button" class="checkout-quick-pill" data-quick-min="90" title="90 min po najszybszym możliwym czasie">+90m</button>
                    </div>
                    <div id="checkout-scheduled-wrap" class="checkout-scheduled" hidden>
                        <div class="checkout-scheduled__date-row">
                            <label class="checkout-label checkout-label--inline">
                                <span>Data</span>
                                <input type="date" id="checkout-date-picker" class="checkout-date-input">
                            </label>
                            <button type="button" class="checkout-date-today" id="checkout-date-today">Dziś</button>
                        </div>
                        <label class="checkout-label">
                            <span>Godzina ${orderType === 'delivery' ? 'dostawy' : 'odbioru'}</span>
                            <select name="requestedTimeSlot" id="checkout-time-slot" disabled>
                                <option value="">Ładuję dostępne godziny…</option>
                            </select>
                        </label>
                        <p class="checkout-note" id="checkout-slot-note">Dostępne godziny uwzględniają godziny otwarcia i czas przygotowania.</p>
                        <div id="checkout-time-confirm" class="checkout-time-confirm" hidden>
                            <div class="checkout-time-confirm__row">
                                <i class="fa-solid fa-circle-check checkout-time-confirm__icon"></i>
                                <div>
                                    <strong id="checkout-time-confirm-label">—</strong>
                                    <small id="checkout-time-confirm-countdown">—</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="checkout-field-group">
                    <legend class="checkout-legend"><i class="fa-solid fa-credit-card"></i> Metoda płatności</legend>
                    <div class="checkout-payment-grid">
                        <label class="checkout-payment-option">
                            <input type="radio" name="paymentMethod" value="cash_on_delivery" checked>
                            <span class="checkout-payment-option__body">
                                <strong><i class="fa-solid fa-money-bill-wave"></i> Gotówka</strong>
                                <small>przy ${orderType === 'delivery' ? 'dostawie' : 'odbiorze'}</small>
                            </span>
                        </label>
                        <label class="checkout-payment-option">
                            <input type="radio" name="paymentMethod" value="card_on_delivery">
                            <span class="checkout-payment-option__body">
                                <strong><i class="fa-solid fa-credit-card"></i> Karta</strong>
                                <small>${orderType === 'delivery' ? 'u kuriera' : 'w restauracji'}</small>
                            </span>
                        </label>
                        <label class="checkout-payment-option checkout-payment-option--disabled" title="Wkrótce dostępne">
                            <input type="radio" name="paymentMethod" value="online_transfer" disabled>
                            <span class="checkout-payment-option__body">
                                <strong><i class="fa-solid fa-globe"></i> Online</strong>
                                <small>przelew / BLIK · wkrótce</small>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="checkout-consents">
                    <label class="checkout-consent">
                        <input type="checkbox" name="consent" required>
                        <span>Akceptuję warunki i potwierdzam, że dane są poprawne. <em>*</em></span>
                    </label>
                    <label class="checkout-consent checkout-consent--optional">
                        <input type="checkbox" name="smsConsent">
                        <span>Zgadzam się na powiadomienia SMS o statusie zamówienia.</span>
                    </label>
                    <label class="checkout-consent checkout-consent--optional">
                        <input type="checkbox" name="marketingConsent">
                        <span>Zgadzam się na otrzymywanie ofert i promocji SMS/email (możesz odwołać w każdej chwili).</span>
                    </label>
                </div>

                <div id="checkout-err" class="checkout-err" aria-live="polite"></div>

                <div class="checkout-actions">
                    <button type="button" class="checkout-btn checkout-btn--ghost" id="checkout-cancel">Powrót do koszyka</button>
                    <button type="submit" class="checkout-btn checkout-btn--primary" id="checkout-submit">
                        <i class="fa-solid fa-lock"></i>
                        <span>Zamów za ${escapeHtml(formatMoneyPl(grandTotal))}</span>
                    </button>
                </div>
            </form>
        </div>
    `;

    overlay.querySelector('.checkout-close')?.addEventListener('click', close);
    overlay.querySelector('#checkout-cancel')?.addEventListener('click', close);

    // ── Promised time wiring (PromisedTimeEngine — ASAP / scheduled) ──────────
    // ASAP: pokazujemy estymowany czas (prep × load + channel buffer).
    // Scheduled: ładujemy sloty z backendu (filtrowane po godzinach otwarcia),
    //            wybór trafia do requested_time jako ISO — backend waliduje
    //            przez PromisedTimeEngine::calculate('scheduled').
    const timeGroup   = overlay.querySelector('#checkout-time-group');
    const asapEtaEl   = overlay.querySelector('#checkout-asap-eta');
    const scheduledWrap = overlay.querySelector('#checkout-scheduled-wrap');
    const slotSelect  = overlay.querySelector('#checkout-time-slot');
    const slotNote    = overlay.querySelector('#checkout-slot-note');
    const datePicker  = overlay.querySelector('#checkout-date-picker');
    const dateTodayBtn = overlay.querySelector('#checkout-date-today');
    const confirmPanel    = overlay.querySelector('#checkout-time-confirm');
    const confirmLabel    = overlay.querySelector('#checkout-time-confirm-label');
    const confirmCountdown = overlay.querySelector('#checkout-time-confirm-countdown');
    let slotsCache = [];
    let slotsLoaded = false;
    let countdownTimer = null;
    // Faza 2 fix: ASAP promised_time z backendu — baseline dla pigułek szybkiego wyboru.
    // Uwzględnia base_prep_minutes + obciążenie kuchni + channel buffer.
    // Pigułki NIE mogą liczyć od Date.now() — mogłyby celować poniżej minimalnego lead time.
    let asapPromisedTime = null;

    // ── Panel potwierdzenia wybranego czasu (Faza 1) ────────────────────────
    // Pokazuje "Dziś 19:30" + "Za 45 min" (zielone) lub "Spóźnione 12 min"
    // (czerwone, pulsujące). Czysto frontendowe — dane z slotsCache / ISO z <select>.
    // Inspiracja: legacy pos.html updateScheduledDisplay(), ale bez datetime-local.
    function clearCountdown() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        if (confirmPanel) confirmPanel.hidden = true;
    }

    function updateConfirmPanel(isoSlot) {
        if (!confirmPanel || !confirmLabel || !confirmCountdown) return;
        if (!isoSlot) { clearCountdown(); return; }
        const target = new Date(isoSlot);
        if (isNaN(target.getTime())) { clearCountdown(); return; }
        const now = new Date();
        const isToday = target.getDate() === now.getDate()
            && target.getMonth() === now.getMonth()
            && target.getFullYear() === now.getFullYear();
        const hh = String(target.getHours()).padStart(2, '0');
        const mm = String(target.getMinutes()).padStart(2, '0');
        const dateLabel = isToday
            ? `Dziś ${hh}:${mm}`
            : `${String(target.getDate()).padStart(2, '0')}.${String(target.getMonth() + 1).padStart(2, '0')} ${hh}:${mm}`;
        confirmLabel.textContent = dateLabel;
        confirmPanel.hidden = false;
        confirmPanel.classList.remove('checkout-time-confirm--late');

        function tick() {
            const diffMs = target.getTime() - Date.now();
            const diffMin = Math.round(diffMs / 60000);
            if (diffMin >= 0) {
                confirmCountdown.textContent = `Za ${diffMin} min`;
                confirmCountdown.classList.remove('checkout-time-confirm__countdown--late');
                confirmPanel.classList.remove('checkout-time-confirm--late');
            } else {
                confirmCountdown.textContent = `Spóźnione ${Math.abs(diffMin)} min`;
                confirmCountdown.classList.add('checkout-time-confirm__countdown--late');
                confirmPanel.classList.add('checkout-time-confirm--late');
            }
        }
        tick();
        if (countdownTimer) clearInterval(countdownTimer);
        countdownTimer = setInterval(tick, 30000); // odśwież co 30s
    }

    async function loadAsapEstimate() {
        if (!asapEtaEl) return;
        try {
            const res = await api.estimateTime({ mode: 'asap', order_type: orderType });
            if (res.success && res.data?.estimated_minutes != null) {
                const mins = res.data.estimated_minutes;
                const eta  = res.data.promised_time
                    ? new Date(res.data.promised_time).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })
                    : null;
                asapEtaEl.textContent = eta
                    ? `~${mins} min (ok. ${eta})`
                    : `~${mins} min`;
                // Faza 2 fix: zachowaj ASAP promised_time jako baseline dla pigułek.
                if (res.data.promised_time) {
                    asapPromisedTime = new Date(res.data.promised_time);
                }
            } else {
                asapEtaEl.textContent = res.message || 'Jak najszybciej';
            }
        } catch (_) {
            asapEtaEl.textContent = 'Jak najszybciej';
        }
    }

    async function loadSlots(forceDate) {
        if (!slotSelect) return;
        // Faza 3: forceDate pozwala przeładować sloty dla innej daty.
        // Bez forceDate: ładuj tylko raz (idempotentny dla today).
        const selectedDate = forceDate || '';
        if (slotsLoaded && !forceDate) return;
        slotSelect.disabled = true;
        slotSelect.innerHTML = '<option value="">Ładuję dostępne godziny…</option>';
        clearCountdown();
        try {
            const payload = {
                mode: 'slots',
                order_type: orderType,
                interval: 15,
                count: 12,
            };
            if (selectedDate) payload.date = selectedDate;
            const res = await api.estimateTime(payload);
            if (res.success && Array.isArray(res.data?.slots)) {
                slotsCache = res.data.slots;
                if (slotsCache.length === 0) {
                    slotSelect.innerHTML = '<option value="">Brak dostępnych godzin — wybierz ASAP.</option>';
                    if (slotNote) slotNote.textContent = 'Lokal jest obecnie zamknięty. Spróbuj później lub wybierz ASAP.';
                } else {
                    slotSelect.innerHTML = '<option value="">— wybierz godzinę —</option>'
                        + slotsCache.map((s) =>
                            `<option value="${escapeHtml(s.iso)}">${escapeHtml(s.label)}</option>`
                        ).join('');
                    if (slotNote) slotNote.textContent = 'Dostępne godziny uwzględniają godziny otwarcia i czas przygotowania.';
                }
            } else {
                slotSelect.innerHTML = '<option value="">Nie udało się pobrać godzin.</option>';
                if (slotNote) slotNote.textContent = res.message || 'Spróbuj wybrać ASAP.';
            }
        } catch (e) {
            slotSelect.innerHTML = '<option value="">Nie udało się pobrać godzin.</option>';
            if (slotNote) slotNote.textContent = e.message || 'Spróbuj wybrać ASAP.';
        } finally {
            slotSelect.disabled = false;
            slotsLoaded = true;
        }
    }

    if (timeGroup) {
        timeGroup.querySelectorAll('input[name="timeMode"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                const mode = radio.value;
                if (mode === 'scheduled') {
                    if (scheduledWrap) scheduledWrap.hidden = false;
                    loadSlots();
                } else {
                    if (scheduledWrap) scheduledWrap.hidden = true;
                    clearCountdown();
                }
            });
        });
        loadAsapEstimate();

        // Faza 3: Date picker init — min = today, default = today.
        // Zmiana daty przeładowuje sloty (forceDate) i czyści panel potwierdzenia.
        if (datePicker) {
            const todayIso = new Date().toISOString().split('T')[0];
            datePicker.min = todayIso;
            datePicker.value = todayIso;
            datePicker.addEventListener('change', () => {
                const sel = datePicker.value || todayIso;
                slotsLoaded = false;
                loadSlots(sel);
            });
        }
        if (dateTodayBtn) {
            dateTodayBtn.addEventListener('click', () => {
                if (!datePicker) return;
                const todayIso = new Date().toISOString().split('T')[0];
                if (datePicker.value === todayIso) return;
                datePicker.value = todayIso;
                slotsLoaded = false;
                loadSlots(todayIso);
            });
        }

        // Slot select → panel potwierdzenia (Faza 1).
        if (slotSelect) {
            slotSelect.addEventListener('change', () => {
                const iso = (slotSelect.value || '').trim();
                if (iso) updateConfirmPanel(iso);
                else clearCountdown();
            });
        }

        // Quick-time pills (Faza 2 fix) — kliknięcie wylicza cel od ASAP baseline
        // (promised_time z backendu — uwzględnia base_prep_minutes + load + buffer),
        // NIE od Date.now(). Pigułka +30m = ASAP + 30 min, nie teraz + 30 min.
        // Przełącza radio na 'scheduled', ładuje sloty, zaznacza najbliższy slot ≥ cel.
        // Fallback: custom ISO — backend PromisedTimeEngine zwaliduje.
        const quickPills = timeGroup.querySelectorAll('.checkout-quick-pill');
        quickPills.forEach((pill) => {
            pill.addEventListener('click', async () => {
                const mins = parseInt(pill.dataset.quickMin || '0', 10);
                if (!mins) return;

                // Faza 2 fix: baseline = ASAP promised_time (z backendu).
                // Jeśli ASAP jeszcze niezaładowany, załaduj i poczekaj.
                if (!asapPromisedTime) {
                    await loadAsapEstimate();
                }
                // Fallback: jeśli ASAP nadal null (błąd API), użyj now + minimalny buffer.
                // Backend i tak zwaliduje przez PromisedTimeEngine — nie przepuści poniżej lead time.
                const baseline = asapPromisedTime || new Date(Date.now() + 15 * 60000);
                const target = new Date(baseline.getTime() + mins * 60000);

                // Przełącz radio na scheduled (uruchomi change handler → loadSlots).
                const scheduledRadio = timeGroup.querySelector('input[name="timeMode"][value="scheduled"]');
                if (scheduledRadio && !scheduledRadio.checked) {
                    scheduledRadio.checked = true;
                    if (scheduledWrap) scheduledWrap.hidden = false;
                    loadSlots();
                }

                // Poczekaj na załadowanie slotów (loadSlots jest idempotentny —
                // jeśli już załadowane, zwraca natychmiast).
                if (!slotsLoaded) {
                    await new Promise((r) => setTimeout(r, 600));
                }

                // Znajdź najbliższy slot ≥ target (lub pierwszy po celu).
                // Sloty w slotsCache mają format { iso: "2026-08-24T19:15", label, ... }.
                const targetMs = target.getTime();
                let bestSlot = null;
                let bestDiff = Infinity;
                for (const s of slotsCache) {
                    if (!s.iso) continue;
                    const slotMs = new Date(s.iso).getTime();
                    if (isNaN(slotMs)) continue;
                    const diff = slotMs - targetMs;
                    // Preferuj slot ≥ target (diff ≥ 0), najmniejszy diff.
                    // Jeśli wszystkie < target, weź najmniejszy diff absolutny.
                    if (diff >= 0 && diff < bestDiff) {
                        bestDiff = diff;
                        bestSlot = s;
                    }
                }
                if (!bestSlot && slotsCache.length > 0) {
                    // Fallback: najbliższy absolutnie (nawet < target).
                    for (const s of slotsCache) {
                        if (!s.iso) continue;
                        const slotMs = new Date(s.iso).getTime();
                        if (isNaN(slotMs)) continue;
                        const diff = Math.abs(slotMs - targetMs);
                        if (diff < bestDiff) {
                            bestDiff = diff;
                            bestSlot = s;
                        }
                    }
                }

                if (bestSlot && slotSelect) {
                    slotSelect.value = bestSlot.iso;
                    updateConfirmPanel(bestSlot.iso);
                } else if (slotSelect) {
                    // Brak pasującego slotu — wstaw custom option z ISO celu.
                    // Backend PromisedTimeEngine::calculate('scheduled') zwaliduje.
                    const isoTarget = `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}-${String(target.getDate()).padStart(2, '0')}T${String(target.getHours()).padStart(2, '0')}:${String(target.getMinutes()).padStart(2, '0')}`;
                    const customOpt = document.createElement('option');
                    customOpt.value = isoTarget;
                    customOpt.textContent = `${String(target.getHours()).padStart(2, '0')}:${String(target.getMinutes()).padStart(2, '0')} (niestandardowy)`;
                    slotSelect.appendChild(customOpt);
                    slotSelect.value = isoTarget;
                    updateConfirmPanel(isoTarget);
                }
            });
        });

        // Wpięcie B — Doorway preorder → auto-aktywacja scheduled.
        // Gdy klient wszedł przez „Zamów z wyprzedzeniem" (state.preOrder),
        // automatycznie zaznacz radio 'scheduled' — istniejący change handler
        // pokaże sloty i wywoła loadSlots(). Zero nowej logiki — czysty wiring.
        if (state.preOrder) {
            const scheduledRadio = timeGroup.querySelector('input[name="timeMode"][value="scheduled"]');
            if (scheduledRadio) {
                scheduledRadio.checked = true;
                if (scheduledWrap) scheduledWrap.hidden = false;
                loadSlots();
            }
        }
    }

    const form = overlay.querySelector('#checkout-form');
    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const errEl = overlay.querySelector('#checkout-err');
        const submitBtn = overlay.querySelector('#checkout-submit');

        const fd = new FormData(form);
        const timeMode = (fd.get('timeMode') || 'asap').toString();
        const selectedSlot = (fd.get('requestedTimeSlot') || '').toString().trim();
        // scheduled wymaga wybranego slotu (ISO z backendu); ASAP = pusty string
        const requestedTime = (timeMode === 'scheduled' && selectedSlot) ? selectedSlot : '';
        const values = {
            customerName:     (fd.get('customerName') || '').toString().trim(),
            customerPhone:    normalizePhonePl(fd.get('customerPhone') || ''),
            customerEmail:    (fd.get('customerEmail') || '').toString().trim(),
            deliveryAddress:  (fd.get('deliveryAddress') || '').toString().trim(),
            deliveryNotes:    (fd.get('deliveryNotes') || '').toString().trim(),
            requestedTime:    requestedTime,
            timeMode:         timeMode,
            paymentMethod:    (fd.get('paymentMethod') || 'cash_on_delivery').toString(),
            consent:          fd.get('consent') === 'on' || fd.get('consent') === 'true',
            smsConsent:       fd.get('smsConsent') === 'on',
            marketingConsent: fd.get('marketingConsent') === 'on',
        };

        // Scheduled bez wybranego slotu = błąd walidacji (nie wysyłaj do backendu)
        if (timeMode === 'scheduled' && !requestedTime) {
            renderErrorsInto(errEl, [{ field: 'requestedTime', msg: 'Wybierz godzinę realizacji lub przełącz na „Jak najszybciej".' }]);
            return;
        }

        if (!values.consent) {
            renderErrorsInto(errEl, [{ field: 'consent', msg: 'Zaznacz zgodę, aby złożyć zamówienie.' }]);
            return;
        }

        const errors = validateForm(values, orderType);
        if (errors.length) {
            renderErrorsInto(errEl, errors);
            return;
        }

        renderErrorsInto(errEl, []);
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Przetwarzanie…</span>';

        // Persist contact for next time
        try {
            localStorage.setItem(lsKey(tenantId, 'contact'), JSON.stringify({
                customerName: values.customerName,
                customerPhone: values.customerPhone,
                customerEmail: values.customerEmail,
                deliveryAddress: values.deliveryAddress,
                deliveryNotes: values.deliveryNotes,
                requestedTime: values.requestedTime,
            }));
        } catch (_) {}

        const baseCartPayload = {
            channel: state.channel,
            order_type: state.orderType,
            lines: cartLinesForApi(),
            promo_code: '',
        };

        if (orderType === 'delivery') {
            const zoneRes = await api.deliveryZones({
                address: values.deliveryAddress,
            });
            if (!zoneRes.success) {
                renderErrorsInto(errEl, [{ field: 'deliveryAddress', msg: zoneRes.message || 'Nie udało się sprawdzić strefy dostawy.' }]);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Spróbuj ponownie</span>';
                return;
            }
            if (zoneRes.data && zoneRes.data.in_zone === false) {
                renderErrorsInto(errEl, [{
                    field: 'deliveryAddress',
                    msg: zoneRes.message || 'Adres jest poza strefą dostawy. Wybierz odbiór albo popraw adres.',
                }]);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Spróbuj ponownie</span>';
                return;
            }
        }

        // Step 1 — init_checkout
        const initRes = await api.initCheckout({
            ...baseCartPayload,
            customer_phone: values.customerPhone,
        });
        if (!initRes.success || !initRes.data?.lock_token) {
            renderErrorsInto(errEl, [{ field: 'init', msg: initRes.message || 'Nie udało się przygotować zamówienia. Spróbuj ponownie.' }]);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Spróbuj ponownie</span>';
            return;
        }
        const lockToken = initRes.data.lock_token;

        // Step 2 — guest_checkout
        const checkoutRes = await api.guestCheckout({
            ...baseCartPayload,
            lock_token: lockToken,
            customer: {
                name:             values.customerName,
                phone:            values.customerPhone,
                email:            values.customerEmail || null,
                sms_consent:      values.smsConsent,
                marketing_consent: values.marketingConsent,
            },
            delivery: orderType === 'delivery' ? {
                address: values.deliveryAddress,
                notes:   values.deliveryNotes || '',
            } : { address: '', notes: '' },
            requested_time: values.requestedTime || '',
            payment_method: values.paymentMethod,
        });

        if (!checkoutRes.success || !checkoutRes.data?.orderNumber) {
            renderErrorsInto(errEl, [{ field: 'checkout', msg: checkoutRes.message || 'Nie udało się zapisać zamówienia. Spróbuj ponownie.' }]);
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Zamów jeszcze raz</span>';
            return;
        }

        const orderData = checkoutRes.data;

        // Persist tracking token + phone for Track Order page (Sesja 5.2)
        try {
            const historyRaw = localStorage.getItem(lsKey(tenantId, 'orders')) || '[]';
            const history = JSON.parse(historyRaw);
            history.unshift({
                orderNumber: orderData.orderNumber,
                trackingToken: orderData.trackingToken,
                phone: values.customerPhone,
                grandTotal: orderData.grandTotal,
                createdAt: new Date().toISOString(),
            });
            localStorage.setItem(lsKey(tenantId, 'orders'), JSON.stringify(history.slice(0, 10)));
            localStorage.setItem(lsKey(tenantId, 'last_order'), JSON.stringify({
                trackingToken: orderData.trackingToken,
                phone: values.customerPhone,
                orderNumber: orderData.orderNumber,
            }));
        } catch (_) {}

        // Clear cart
        state.cart = [];
        state.lastCalc = null;
        persistCart();
        refreshCartUi();

        if (typeof onSuccess === 'function') onSuccess(orderData);

        renderSuccessScreen(overlay, orderData, values, { close, tenantId });
    });
}

function renderSuccessScreen(overlay, orderData, values, { close, tenantId }) {
    const panel = overlay.querySelector('.checkout-panel');
    if (!panel) return;

    const trackBase = (globalThis.SliceHub && globalThis.SliceHub.appUrl)
        ? globalThis.SliceHub.appUrl('/modules/online/track.html')
        : '/slicehub/modules/online/track.html';
    const trackingUrl = orderData.trackingUrl
        || `${trackBase}?tenant=${tenantId}&token=${encodeURIComponent(orderData.trackingToken)}&phone=${encodeURIComponent(values.customerPhone)}`;

    panel.innerHTML = `
        <div class="checkout-success">
            <div class="checkout-success__badge" aria-hidden="true">
                <i class="fa-solid fa-check"></i>
            </div>
            <p class="checkout-eyebrow">Zamówienie przyjęte</p>
            <h2 class="checkout-title">Dziękujemy, ${escapeHtml(values.customerName)}!</h2>
            <p class="checkout-success__num">${escapeHtml(orderData.orderNumber)}</p>

            <dl class="checkout-success__facts">
                <div>
                    <dt>Status</dt>
                    <dd><span class="checkout-pill checkout-pill--blue">Otrzymane</span></dd>
                </div>
                <div>
                    <dt>Do zapłaty</dt>
                    <dd><strong>${escapeHtml(formatMoneyPl(orderData.grandTotal))}</strong></dd>
                </div>
                <div>
                    <dt>Płatność</dt>
                    <dd>${escapeHtml(prettyPaymentMethod(orderData.paymentMethod))}</dd>
                </div>
                ${orderData.loyaltyPointsEarned ? `
                <div>
                    <dt>Punkty lojalnościowe</dt>
                    <dd><strong>+${orderData.loyaltyPointsEarned} pkt</strong></dd>
                </div>
                ` : ''}
            </dl>

            <p class="checkout-success__hint">
                <i class="fa-solid fa-bell"></i>
                Zapiszemy potwierdzenie na tym urządzeniu — możesz zamknąć tę stronę.
                Śledź status zamówienia w czasie rzeczywistym ↓
            </p>

            <div class="checkout-actions">
                <button type="button" class="checkout-btn checkout-btn--ghost" id="checkout-close-success">Zamów więcej</button>
                <a class="checkout-btn checkout-btn--primary" href="${trackingUrl}">
                    <i class="fa-solid fa-location-crosshairs"></i>
                    <span>Śledź zamówienie</span>
                </a>
            </div>
        </div>
    `;

    panel.querySelector('#checkout-close-success')?.addEventListener('click', close);
}

function prettyPaymentMethod(m) {
    switch ((m || '').toLowerCase()) {
        case 'cash_on_delivery': return 'Gotówka przy dostawie';
        case 'card_on_delivery': return 'Karta u kuriera';
        case 'online_transfer':  return 'Przelew online';
        default: return m || '—';
    }
}

/**
 * Utility: ostatnie zamówienie (dla CTA „Śledź ostatnie” w nagłówku).
 */
export function readLastOrder(tenantId) {
    try {
        const raw = localStorage.getItem(lsKey(tenantId, 'last_order'));
        return raw ? JSON.parse(raw) : null;
    } catch (_) { return null; }
}
