/**
 * Online Studio — STOREFRONT tab (Faza D · 2026-04-19)
 *
 * Jedno miejsce w Studio, gdzie manager ustawia WSZYSTKO co widzi klient
 * zanim wybierze danie:
 *   - Brand (tagline — nazwa tenanta zmienia się przez panel administracyjny)
 *   - Kontakt (adres, miasto, telefon, email)
 *   - Godziny otwarcia (monday..sunday, zamknięte lub HH:MM–HH:MM)
 *   - Kanały sprzedaży (delivery/takeaway/dine_in) + preorder
 *   - Mapa (lat/lng — Scena Drzwi pokaże modal z mapą)
 *
 * Dane = `sh_tenant_settings` (klucze `storefront_*`) + kolumna
 * `opening_hours_json`. Backend: api/online_studio/engine.php
 *   → storefront_settings_get  (payload bez parametrów)
 *   → storefront_settings_save (częściowy payload — akceptuje sekcje osobno)
 *
 * Ten tab NIE modyfikuje Surface ani assetów — to robi tab Surface / Assets.
 * Tu tylko dane biznesowe + marka.
 */

const DAYS = [
    { key: 'monday',    label: 'Poniedziałek' },
    { key: 'tuesday',   label: 'Wtorek' },
    { key: 'wednesday', label: 'Środa' },
    { key: 'thursday',  label: 'Czwartek' },
    { key: 'friday',    label: 'Piątek' },
    { key: 'saturday',  label: 'Sobota' },
    { key: 'sunday',    label: 'Niedziela' },
];

const CHANNELS = [
    { key: 'delivery', label: 'Delivery',  icon: 'fa-motorcycle',  desc: 'Dowóz do klienta' },
    { key: 'takeaway', label: 'Takeaway',  icon: 'fa-bag-shopping', desc: 'Odbiór osobisty' },
    { key: 'dine_in',  label: 'Dine-in',   icon: 'fa-utensils',    desc: 'Na miejscu (stolik)' },
];

/** Render HTML pojedynczego wiersza godzin otwarcia. */
function renderHoursRow(day, data) {
    const closed = !!data?.closed;
    const open   = data?.open  || '';
    const close  = data?.close || '';
    return `
        <div class="sf-hours-row" data-day="${day.key}">
            <div class="sf-hours-row__label">${day.label}</div>
            <label class="sf-hours-row__closed">
                <input type="checkbox" data-role="closed" ${closed ? 'checked' : ''}>
                <span>Zamknięte</span>
            </label>
            <input type="time" class="input" data-role="open"  value="${open}"  ${closed ? 'disabled' : ''}>
            <span class="sf-hours-row__sep">–</span>
            <input type="time" class="input" data-role="close" value="${close}" ${closed ? 'disabled' : ''}>
        </div>
    `;
}

export function mountStorefront(root, Studio, Api) {
    let state = {
        loaded: false,
        data: null,
        dirty: false,
    };

    root.innerHTML = `
        <div class="sf-wrap">
            <div class="sf-status" id="sf-status">
                <i class="fa-solid fa-circle-info"></i>
                <span>Ładowanie ustawień…</span>
            </div>

            <section class="card sf-section">
                <div class="card__head">
                    <div>
                        <div class="card__title"><i class="fa-solid fa-store"></i> Marka</div>
                        <div class="card__sub">Nazwa lokalu i krótki podtytuł, który zobaczy klient na Scenie Drzwi.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label class="label">Nazwa lokalu (tenant)</label>
                        <input type="text" class="input" id="sf-brand-name" disabled readonly>
                        <div class="muted" style="margin-top:4px;font-size:11px">Zmieniane z poziomu administracji (sh_tenant.name).</div>
                    </div>
                    <div>
                        <label class="label">Tagline (slogan)</label>
                        <input type="text" class="input" id="sf-brand-tagline" maxlength="180"
                               placeholder="np. Neapolitańska pizza z pieca na drewno">
                    </div>
                </div>
            </section>

            <section class="card sf-section">
                <div class="card__head">
                    <div>
                        <div class="card__title"><i class="fa-solid fa-address-card"></i> Kontakt</div>
                        <div class="card__sub">Dane pokazywane w stopce storefrontu + Scena Drzwi (mapa modal).</div>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label class="label">Adres</label>
                        <input type="text" class="input" id="sf-contact-address" maxlength="255"
                               placeholder="ul. Mazowiecka 14/2">
                    </div>
                    <div>
                        <label class="label">Miasto</label>
                        <input type="text" class="input" id="sf-contact-city" maxlength="120"
                               placeholder="Warszawa">
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label class="label">Telefon</label>
                        <input type="tel" class="input" id="sf-contact-phone" maxlength="32"
                               placeholder="+48 600 100 200">
                    </div>
                    <div>
                        <label class="label">Email</label>
                        <input type="email" class="input" id="sf-contact-email" maxlength="180"
                               placeholder="kontakt@twoja-pizza.pl">
                    </div>
                </div>
            </section>

            <section class="card sf-section">
                <div class="card__head">
                    <div>
                        <div class="card__title"><i class="fa-solid fa-clock"></i> Godziny otwarcia</div>
                        <div class="card__sub">Ustawia status "Otwarte/Zamknięte" na Scenie Drzwi i widoczność kanałów.</div>
                    </div>
                    <button class="btn btn--ghost" id="sf-hours-preset" title="Skopiuj poniedziałkowe godziny na pozostałe dni">
                        <i class="fa-solid fa-copy"></i> Kopiuj Pn → Pt
                    </button>
                </div>
                <div class="sf-hours" id="sf-hours">
                    ${DAYS.map(d => renderHoursRow(d, null)).join('')}
                </div>
            </section>

            <section class="card sf-section">
                <div class="card__head">
                    <div>
                        <div class="card__title"><i class="fa-solid fa-route"></i> Kanały sprzedaży</div>
                        <div class="card__sub">Klient wybiera na Checkout. Przynajmniej 1 aktywny.</div>
                    </div>
                </div>
                <div class="sf-channels" id="sf-channels">
                    ${CHANNELS.map(ch => `
                        <label class="sf-channel" data-ch="${ch.key}">
                            <input type="checkbox" data-ch="${ch.key}">
                            <div class="sf-channel__icon"><i class="fa-solid ${ch.icon}"></i></div>
                            <div class="sf-channel__body">
                                <div class="sf-channel__label">${ch.label}</div>
                                <div class="sf-channel__desc">${ch.desc}</div>
                            </div>
                        </label>
                    `).join('')}
                </div>
                <div class="form-row" style="margin-top:14px">
                    <label class="sf-preorder">
                        <input type="checkbox" id="sf-preorder-enabled">
                        <div>
                            <div class="sf-preorder__label">Preorder (zamówienia z wyprzedzeniem)</div>
                            <div class="sf-preorder__desc">Klient może złożyć zamówienie na później. Wymaga aktywnych godzin otwarcia.</div>
                        </div>
                    </label>
                    <div>
                        <label class="label">Minimalny czas realizacji (minuty)</label>
                        <input type="number" class="input" id="sf-preorder-lead" min="0" max="720" step="5" value="30">
                        <div class="muted" style="margin-top:4px;font-size:11px">0–720 minut. Używane do ETA + sugestii slotu preorder.</div>
                    </div>
                </div>
            </section>

            <section class="card sf-section">
                <div class="card__head">
                    <div>
                        <div class="card__title"><i class="fa-solid fa-map-location-dot"></i> Mapa</div>
                        <div class="card__sub">Współrzędne lokalu (GPS). Scena Drzwi pokaże modal z mapą OSM.</div>
                    </div>
                    <button class="btn btn--ghost" id="sf-map-open" title="Zobacz obecną pozycję na OpenStreetMap">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Podgląd
                    </button>
                </div>
                <div class="form-row">
                    <div>
                        <label class="label">Szerokość geograficzna (lat)</label>
                        <input type="number" class="input" id="sf-map-lat" step="0.000001" min="-90" max="90"
                               placeholder="52.229676">
                    </div>
                    <div>
                        <label class="label">Długość geograficzna (lng)</label>
                        <input type="number" class="input" id="sf-map-lng" step="0.000001" min="-180" max="180"
                               placeholder="21.012229">
                    </div>
                </div>
                <div class="muted" style="font-size:11.5px;line-height:1.5">
                    <i class="fa-solid fa-lightbulb"></i>
                    Wskazówka: wejdź na <a href="https://www.openstreetmap.org" target="_blank" rel="noreferrer">OpenStreetMap</a>,
                    kliknij prawym na swojej restauracji → "Pokaż adres" — skopiuj lat/lon z URL.
                </div>
            </section>

            <div class="sf-actions">
                <button class="btn btn--ghost" id="sf-reset" title="Przeładuj z serwera">
                    <i class="fa-solid fa-rotate"></i> Odrzuć zmiany
                </button>
                <button class="btn btn--accent" id="sf-save">
                    <i class="fa-solid fa-floppy-disk"></i> Zapisz ustawienia
                </button>
            </div>
        </div>
    `;

    // ── helpers ────────────────────────────────────────────────────
    const $ = (sel) => root.querySelector(sel);
    const $$ = (sel) => root.querySelectorAll(sel);

    function setDirty(dirty) {
        state.dirty = dirty;
        Studio.markDirty(dirty);
        $('#sf-save').classList.toggle('btn--pulse', dirty);
    }

    function setStatus(msg, type = 'info') {
        const $s = $('#sf-status');
        $s.className = `sf-status sf-status--${type}`;
        const icon = { ok: 'fa-circle-check', err: 'fa-circle-exclamation', info: 'fa-circle-info', warn: 'fa-triangle-exclamation' }[type] || 'fa-circle-info';
        $s.innerHTML = `<i class="fa-solid ${icon}"></i><span>${msg}</span>`;
    }

    /** Zrzuć aktualne dane z UI do obiektu w kontrakcie backendu. */
    function harvest() {
        const payload = {
            brand: {
                tagline: $('#sf-brand-tagline').value.trim(),
            },
            contact: {
                address: $('#sf-contact-address').value.trim(),
                city:    $('#sf-contact-city').value.trim(),
                phone:   $('#sf-contact-phone').value.trim(),
                email:   $('#sf-contact-email').value.trim(),
            },
            map: {
                lat: $('#sf-map-lat').value === '' ? null : parseFloat($('#sf-map-lat').value),
                lng: $('#sf-map-lng').value === '' ? null : parseFloat($('#sf-map-lng').value),
            },
            channels: {
                active: [...$$('#sf-channels input[type="checkbox"]')]
                    .filter(c => c.checked)
                    .map(c => c.dataset.ch),
                preorderEnabled: $('#sf-preorder-enabled').checked,
                preorderLeadMin: parseInt($('#sf-preorder-lead').value, 10) || 0,
            },
            openingHours: {},
        };

        // Godziny
        $$('.sf-hours-row').forEach(row => {
            const day    = row.dataset.day;
            const closed = row.querySelector('[data-role="closed"]').checked;
            if (closed) {
                payload.openingHours[day] = { closed: true };
            } else {
                const open  = row.querySelector('[data-role="open"]').value;
                const close = row.querySelector('[data-role="close"]').value;
                payload.openingHours[day] = { open, close };
            }
        });

        return payload;
    }

    /** Wrzuć dane z serwera do formularza. */
    function hydrate(data) {
        state.data = data;
        $('#sf-brand-name').value       = data?.brand?.name || '';
        $('#sf-brand-tagline').value    = data?.brand?.tagline || '';
        $('#sf-contact-address').value  = data?.contact?.address || '';
        $('#sf-contact-city').value     = data?.contact?.city || '';
        $('#sf-contact-phone').value    = data?.contact?.phone || '';
        $('#sf-contact-email').value    = data?.contact?.email || '';
        $('#sf-map-lat').value          = (data?.map?.lat ?? '') === null ? '' : (data?.map?.lat ?? '');
        $('#sf-map-lng').value          = (data?.map?.lng ?? '') === null ? '' : (data?.map?.lng ?? '');

        const active = new Set(data?.channels?.active || ['delivery', 'takeaway']);
        $$('#sf-channels input[type="checkbox"]').forEach(c => {
            c.checked = active.has(c.dataset.ch);
        });
        $('#sf-preorder-enabled').checked = !!data?.channels?.preorderEnabled;
        $('#sf-preorder-lead').value      = data?.channels?.preorderLeadMin ?? 30;

        // Godziny — backend może zwrócić {} (nigdy nie ustawiono)
        const oh = data?.openingHours || {};
        $('#sf-hours').innerHTML = DAYS.map(d => renderHoursRow(d, oh[d.key])).join('');

        // Aktualizacja statusu
        if (!data?.contact?.address && !data?.contact?.phone) {
            setStatus('Uzupełnij dane kontaktowe — bez nich Scena Drzwi jest niekompletna.', 'warn');
        } else {
            setStatus(`Ustawienia załadowane · ${state.data?.brand?.name || 'tenant'}`, 'ok');
        }
    }

    async function load() {
        setStatus('Ładowanie ustawień…', 'info');
        const r = await Api.storefrontSettingsGet();
        if (!r.success) {
            setStatus(r.message || 'Nie udało się załadować ustawień.', 'err');
            return;
        }
        hydrate(r.data);
        state.loaded = true;
        setDirty(false);
    }

    async function save() {
        if (!state.dirty) {
            Studio.toast('Brak zmian do zapisania.', 'info');
            return;
        }
        const payload = harvest();

        // Walidacja kliencka: min 1 kanał
        if (!payload.channels.active.length) {
            Studio.toast('Wymagany przynajmniej 1 aktywny kanał sprzedaży.', 'err');
            return;
        }

        $('#sf-save').disabled = true;
        const r = await Api.storefrontSettingsSave(payload);
        $('#sf-save').disabled = false;

        if (!r.success) {
            const msg = r.data?.errors?.length ? r.data.errors.join(' · ') : (r.message || 'Błąd zapisu.');
            setStatus(msg, 'err');
            Studio.toast(msg, 'err', 5000);
            return;
        }
        setStatus('Ustawienia zapisane ✓', 'ok');
        Studio.toast('Zapisano ustawienia storefrontu.', 'ok');
        setDirty(false);
        // Reload żeby mieć kanoniczne wartości (tenant name etc.)
        await load();
    }

    // ── zdarzenia ──────────────────────────────────────────────────
    root.addEventListener('input', (e) => {
        if (e.target.closest('.sf-wrap')) setDirty(true);
    });
    root.addEventListener('change', (e) => {
        if (e.target.closest('.sf-wrap')) setDirty(true);
        // Toggle closed → disable time inputs
        if (e.target.matches('[data-role="closed"]')) {
            const row = e.target.closest('.sf-hours-row');
            const closed = e.target.checked;
            row.querySelectorAll('input[type="time"]').forEach(inp => inp.disabled = closed);
        }
    });

    $('#sf-save').onclick = save;
    $('#sf-reset').onclick = async () => {
        if (state.dirty && !confirm('Odrzucić niezapisane zmiany?')) return;
        await load();
    };

    // Kopiuj Pn → Pt (piątek włącznie)
    $('#sf-hours-preset').onclick = () => {
        const monday = $('.sf-hours-row[data-day="monday"]');
        const closed = monday.querySelector('[data-role="closed"]').checked;
        const open   = monday.querySelector('[data-role="open"]').value;
        const close  = monday.querySelector('[data-role="close"]').value;
        ['tuesday','wednesday','thursday','friday'].forEach(day => {
            const row = $(`.sf-hours-row[data-day="${day}"]`);
            const chk = row.querySelector('[data-role="closed"]');
            chk.checked = closed;
            const openInp  = row.querySelector('[data-role="open"]');
            const closeInp = row.querySelector('[data-role="close"]');
            openInp.value  = open;
            closeInp.value = close;
            openInp.disabled  = closed;
            closeInp.disabled = closed;
        });
        setDirty(true);
        Studio.toast('Skopiowano godziny poniedziałku na wt–pt.', 'ok', 2000);
    };

    $('#sf-map-open').onclick = () => {
        const lat = $('#sf-map-lat').value;
        const lng = $('#sf-map-lng').value;
        if (!lat || !lng) {
            Studio.toast('Podaj najpierw lat i lng.', 'warn');
            return;
        }
        // OSM permalink (no API key needed)
        const url = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=17/${lat}/${lng}`;
        window.open(url, '_blank', 'noreferrer');
    };

    return {
        onEnter: () => {
            if (!state.loaded) load();
        },
        // onLeave — globalny Studio._dirty + switchTab już zapyta o niezapisane zmiany.
        // Nie duplikujemy confirmu tutaj.
    };
}
