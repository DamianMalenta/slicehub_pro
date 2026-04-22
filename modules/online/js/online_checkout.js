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
                    <label class="checkout-label">
                        <span>Preferowana godzina odbioru <small>(opcjonalnie)</small></span>
                        <input type="text" name="requestedTime" value="${escapeHtml(saved.requestedTime || '')}" placeholder="np. 19:30" maxlength="5">
                    </label>
                </fieldset>
                `}

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

    const form = overlay.querySelector('#checkout-form');
    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const errEl = overlay.querySelector('#checkout-err');
        const submitBtn = overlay.querySelector('#checkout-submit');

        const fd = new FormData(form);
        const values = {
            customerName:     (fd.get('customerName') || '').toString().trim(),
            customerPhone:    normalizePhonePl(fd.get('customerPhone') || ''),
            customerEmail:    (fd.get('customerEmail') || '').toString().trim(),
            deliveryAddress:  (fd.get('deliveryAddress') || '').toString().trim(),
            deliveryNotes:    (fd.get('deliveryNotes') || '').toString().trim(),
            requestedTime:    (fd.get('requestedTime') || '').toString().trim(),
            paymentMethod:    (fd.get('paymentMethod') || 'cash_on_delivery').toString(),
            consent:          fd.get('consent') === 'on' || fd.get('consent') === 'true',
            smsConsent:       fd.get('smsConsent') === 'on',
            marketingConsent: fd.get('marketingConsent') === 'on',
        };

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

    const trackingUrl = orderData.trackingUrl
        || `/slicehub/modules/online/track.html?tenant=${tenantId}&token=${encodeURIComponent(orderData.trackingToken)}&phone=${encodeURIComponent(values.customerPhone)}`;

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
