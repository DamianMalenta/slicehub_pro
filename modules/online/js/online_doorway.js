/**
 * SliceHub Online · M4 — Scena Drzwi (hero entry)
 *
 * Pierwsze wrażenie sklepu: stylizowana ilustracja drzwi restauracji + nazwa,
 * status (open/closed/closing_soon), dane kontaktowe, wybór kanału i CTA
 * "Wejdź do menu". Dane pochodzą z `get_doorway` (api/online/engine.php).
 *
 * Wejście: `mountDoorway({ api, onEnter, initialChannel })`.
 * Wyjście: kontener #doorway jest płynnie ukrywany po wejściu; `onEnter`
 * otrzymuje wybrany kanał ('Delivery' | 'Takeaway' | 'POS') oraz preorder flag.
 *
 * Deep-link skip: jeśli URL zawiera ?skip=doors, funkcja `mountDoorway`
 * natychmiast wywołuje `onEnter` i nie montuje sceny.
 *
 * Konstytucja: zero frameworków — vanilla JS, SVG inline, Leaflet on-demand.
 */

const DAY_LABEL = {
    monday: 'Pn', tuesday: 'Wt', wednesday: 'Śr',
    thursday: 'Cz', friday: 'Pt', saturday: 'So', sunday: 'Nd',
};
const DAY_ORDER = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

const CHANNEL_MAP = { delivery: 'Delivery', takeaway: 'Takeaway', dine_in: 'POS' };

/**
 * Render SVG illustration of a pizzeria door.
 * `tod` = 'morning' | 'day' | 'evening' | 'night'
 * `brandName` is drawn on the sign above the door.
 * `brandColor` driven by CSS var --doorway-accent (already set by host).
 */
function renderDoorSvg(tod, brandName) {
    const isNightish = tod === 'evening' || tod === 'night';
    const warmInside = isNightish ? '#f4b64b' : '#ffd98b';
    const wallA  = { morning: '#4a2f28', day: '#5a3a2f', evening: '#2e1912', night: '#12111b' }[tod] || '#2e1912';
    const wallB  = { morning: '#2f1e1a', day: '#3a2620', evening: '#1b0e09', night: '#07060c' }[tod] || '#1b0e09';
    const trim   = isNightish ? '#1a0f08' : '#2a1a11';
    const wood   = isNightish ? '#5c3a22' : '#7a4f31';
    const safeName = String(brandName || 'SliceHub').slice(0, 22);

    // stars only for night
    const stars = tod === 'night'
        ? Array.from({ length: 14 }, () => {
            const x = Math.floor(20 + Math.random() * 260);
            const y = Math.floor(20 + Math.random() * 140);
            const r = (Math.random() * 1.2 + .4).toFixed(2);
            return `<circle cx="${x}" cy="${y}" r="${r}" fill="#fff" opacity="${(.4 + Math.random() * .5).toFixed(2)}"/>`;
        }).join('')
        : '';

    return `
<svg viewBox="0 0 300 480" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Drzwi restauracji">
  <defs>
    <linearGradient id="dwWall" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="${wallA}"/>
      <stop offset="1" stop-color="${wallB}"/>
    </linearGradient>
    <radialGradient id="dwGlow" cx="0.5" cy="0.45" r="0.7">
      <stop offset="0" stop-color="${warmInside}" stop-opacity="0.95"/>
      <stop offset="0.55" stop-color="${warmInside}" stop-opacity="0.35"/>
      <stop offset="1" stop-color="${warmInside}" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="dwDoor" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="${wood}"/>
      <stop offset="1" stop-color="#3b2414"/>
    </linearGradient>
    <filter id="dwSoft"><feGaussianBlur stdDeviation="1.2"/></filter>
  </defs>

  <!-- wall -->
  <rect x="0" y="0" width="300" height="480" fill="url(#dwWall)"/>
  ${stars}

  <!-- awning (daszek) -->
  <path d="M30 150 L270 150 L250 180 L50 180 Z" fill="#8c2f2a" opacity="0.95"/>
  <path d="M30 150 L270 150 L250 180 L50 180 Z" fill="url(#dwDoor)" opacity="0.15"/>
  <g opacity="0.85">
    ${Array.from({ length: 10 }, (_, i) => `<line x1="${50 + i * 22}" y1="150" x2="${50 + i * 22 - 3}" y2="180" stroke="#5a1815" stroke-width="1.2"/>`).join('')}
  </g>

  <!-- sign -->
  <g class="doorway__sign-glow">
    <rect x="70" y="95" width="160" height="46" rx="10" fill="#15110c" stroke="var(--doorway-accent,#E8B04B)" stroke-width="2"/>
    <text x="150" y="124" text-anchor="middle" font-family="Fraunces, serif" font-weight="700" font-size="20" fill="var(--doorway-accent,#E8B04B)">${escapeXml(safeName)}</text>
  </g>

  <!-- warm light halo -->
  <ellipse cx="150" cy="320" rx="140" ry="130" fill="url(#dwGlow)"/>

  <!-- frame -->
  <rect x="88" y="200" width="124" height="240" rx="4" fill="${trim}"/>
  <rect x="96" y="206" width="108" height="228" rx="3" fill="url(#dwDoor)"/>

  <!-- door split -->
  <line x1="150" y1="210" x2="150" y2="432" stroke="#1a0f08" stroke-width="1.5" opacity="0.85"/>

  <!-- panels -->
  <rect x="104" y="216" width="40" height="60" rx="2" fill="#000" opacity="0.15"/>
  <rect x="156" y="216" width="40" height="60" rx="2" fill="#000" opacity="0.15"/>
  <rect x="104" y="286" width="40" height="60" rx="2" fill="#000" opacity="0.15"/>
  <rect x="156" y="286" width="40" height="60" rx="2" fill="#000" opacity="0.15"/>

  <!-- window in the door (warm glow visible) -->
  <rect x="110" y="220" width="80" height="60" rx="3" fill="${warmInside}" opacity="0.78" filter="url(#dwSoft)"/>
  <line x1="150" y1="220" x2="150" y2="280" stroke="${trim}" stroke-width="2"/>
  <line x1="110" y1="250" x2="190" y2="250" stroke="${trim}" stroke-width="2"/>

  <!-- handles -->
  <circle cx="142" cy="332" r="3" fill="var(--doorway-accent,#E8B04B)"/>
  <circle cx="158" cy="332" r="3" fill="var(--doorway-accent,#E8B04B)"/>

  <!-- open / closed plaque -->
  <g transform="translate(178,310)">
    <rect x="-12" y="-9" width="24" height="18" rx="3" fill="#0c0906" stroke="var(--doorway-accent,#E8B04B)" stroke-width="1"/>
    <text x="0" y="3" text-anchor="middle" font-family="DM Sans, sans-serif" font-weight="700" font-size="8" fill="var(--doorway-accent,#E8B04B)">OPEN</text>
  </g>

  <!-- ground shadow -->
  <ellipse cx="150" cy="448" rx="120" ry="10" fill="#000" opacity="0.55"/>
</svg>
    `;
}

function escapeXml(s) {
    return String(s).replace(/[<>&"']/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' }[c]));
}

function fmtHoursLabel(hours) {
    if (!hours) return '';
    if (hours.today_open && hours.today_close) {
        return `Dziś ${hours.today_open}–${hours.today_close}`;
    }
    return 'Godziny niedostępne';
}

function hoursToWeekList(week) {
    if (!week || typeof week !== 'object') return [];
    return DAY_ORDER.map((k) => {
        const d = week[k];
        if (d && d.open && d.close) {
            return { day: DAY_LABEL[k], open: d.open, close: d.close, closed: false };
        }
        return { day: DAY_LABEL[k], open: null, close: null, closed: true };
    });
}

/**
 * Lazy Leaflet loader — avoids shipping 40kB when doorway isn't opened.
 */
async function ensureLeaflet() {
    if (window.L) return window.L;
    await new Promise((resolve, reject) => {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(css);

        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
    return window.L;
}

function renderMap(container, coords, label) {
    return ensureLeaflet().then((L) => {
        container.innerHTML = '';
        const map = L.map(container, { zoomControl: false, attributionControl: false })
            .setView([coords.lat, coords.lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
        }).addTo(map);
        L.marker([coords.lat, coords.lng]).addTo(map)
            .bindPopup(label || 'Tutaj jesteśmy').openPopup();
        setTimeout(() => map.invalidateSize(), 50);
        return map;
    });
}

function setupMapModal(doorway, contact, brandName) {
    const modal = document.getElementById('doorway-map-modal');
    if (!modal) return;
    const canvas = document.getElementById('doorway-map-canvas');
    const addrEl = document.getElementById('doorway-map-address');
    const navEl  = document.getElementById('doorway-map-nav');
    const close  = document.getElementById('doorway-map-close');
    const openers = [
        document.getElementById('doorway-map-open'),
        document.getElementById('doorway-hours'),
    ].filter(Boolean);

    const fullAddr = [contact.address, contact.city].filter(Boolean).join(', ') || 'Brak adresu';
    addrEl.textContent = fullAddr;

    if (contact.lat != null && contact.lng != null) {
        navEl.href = `https://www.google.com/maps/dir/?api=1&destination=${contact.lat},${contact.lng}`;
    } else if (fullAddr) {
        navEl.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(fullAddr)}`;
    }

    const show = () => {
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        if (contact.lat != null && contact.lng != null) {
            renderMap(canvas, { lat: contact.lat, lng: contact.lng }, brandName).catch(() => {
                canvas.innerHTML = `<div style="padding:24px;color:#fff;opacity:.75;font-size:13px">Nie udało się załadować mapy.</div>`;
            });
        } else {
            canvas.innerHTML = `<div style="padding:24px;color:#fff;opacity:.75;font-size:13px">Mapa niedostępna — brak współrzędnych w ustawieniach sklepu.</div>`;
        }
    };
    const hide = () => {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    };
    openers.forEach(btn => btn.addEventListener('click', (e) => { e.preventDefault(); show(); }));
    close?.addEventListener('click', hide);
    modal.addEventListener('click', (e) => { if (e.target === modal) hide(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) hide(); });
}

function setChannelButtons(doorway, channels, initialChannel) {
    const btns = doorway.querySelectorAll('.doorway__channel');
    btns.forEach((b) => {
        const key = b.dataset.channel;
        if (channels && channels[key] === false) {
            b.hidden = true;
        } else {
            b.hidden = false;
        }
    });
    // Initial active
    const wantKey = initialChannel === 'Takeaway' ? 'takeaway' :
                    initialChannel === 'POS' ? 'dine_in' : 'delivery';
    btns.forEach((b) => b.classList.toggle('is-active', b.dataset.channel === wantKey));
}

function getActiveChannel(doorway) {
    const active = doorway.querySelector('.doorway__channel.is-active');
    return CHANNEL_MAP[active?.dataset.channel || 'delivery'] || 'Delivery';
}

/**
 * Public API — montuje Drzwi i zwraca promise rozwiązywany po wejściu klienta.
 */
export async function mountDoorway({ api, onEnter, initialChannel = 'Delivery' } = {}) {
    const doorway = document.getElementById('doorway');
    const body    = document.body;

    // ?skip=doors — omija Scenę Drzwi (np. deep-link z SMSa/maila/tracking).
    const params = new URLSearchParams(window.location.search);
    if (params.get('skip') === 'doors') {
        doorway?.remove();
        body.classList.add('is-door-entered');
        onEnter?.({ channel: initialChannel, preOrder: false });
        return;
    }

    if (!doorway) {
        onEnter?.({ channel: initialChannel, preOrder: false });
        return;
    }

    // Odświeżenie z API.
    let data = null;
    try {
        const res = await api.getDoorway(initialChannel);
        if (res?.success && res.data) data = res.data;
    } catch (_) { /* fallback poniżej */ }

    const fallback = {
        tenant: { name: 'SliceHub', tagline: '', brandColor: '#E8B04B' },
        contact: { address: '', city: '', phone: '', email: '', lat: null, lng: null },
        hours: { today_open: null, today_close: null, week: {} },
        status: { code: 'open', label: 'Otwarte', next_open_at: null },
        channels: { delivery: true, takeaway: true, dine_in: false },
        timeOfDay: 'day',
        preOrderEnabled: true,
    };
    data = Object.assign({}, fallback, data || {});

    // Branding — kolor akcentu jako CSS var
    if (data.tenant?.brandColor) {
        doorway.style.setProperty('--doorway-accent', data.tenant.brandColor);
    }
    doorway.dataset.timeOfDay = data.timeOfDay || 'day';

    // Illustration
    const illuHost = document.getElementById('doorway-illustration');
    if (illuHost) illuHost.innerHTML = renderDoorSvg(data.timeOfDay, data.tenant?.name);

    // Brand text
    const brandEl = document.getElementById('doorway-brand');
    if (brandEl) brandEl.textContent = data.tenant?.name || 'SliceHub';
    const taglineEl = document.getElementById('doorway-tagline');
    if (taglineEl) taglineEl.textContent = data.tenant?.tagline || 'Świeżo pieczona pizza';

    // Status chip
    const statusEl = document.getElementById('doorway-status');
    if (statusEl) {
        statusEl.dataset.state = data.status?.code || 'open';
        statusEl.querySelector('.doorway__status-label').textContent = data.status?.label || '—';
    }

    // Info chips
    const hoursLabel = document.getElementById('doorway-hours-label');
    if (hoursLabel) hoursLabel.textContent = fmtHoursLabel(data.hours);

    const addrLabel = document.getElementById('doorway-address-label');
    if (addrLabel) {
        const addr = [data.contact?.address, data.contact?.city].filter(Boolean).join(', ');
        addrLabel.textContent = addr || 'Zobacz na mapie';
    }
    const phoneEl      = document.getElementById('doorway-phone');
    const phoneLabelEl = document.getElementById('doorway-phone-label');
    if (phoneLabelEl) phoneLabelEl.textContent = data.contact?.phone || 'Bez telefonu';
    if (phoneEl) {
        if (data.contact?.phone) {
            phoneEl.href = 'tel:' + String(data.contact.phone).replace(/\s+/g, '');
        } else {
            phoneEl.setAttribute('aria-disabled', 'true');
            phoneEl.addEventListener('click', (e) => e.preventDefault(), { once: false });
        }
    }

    setChannelButtons(doorway, data.channels, initialChannel);

    // CTA state
    const enterBtn   = document.getElementById('doorway-enter');
    const enterSub   = document.getElementById('doorway-enter-sub');
    const preorderBtn = document.getElementById('doorway-preorder');
    const statusCode = data.status?.code || 'open';

    if (enterSub) {
        if (statusCode === 'open') {
            enterSub.textContent = 'Otwarte teraz';
        } else if (statusCode === 'closing_soon') {
            enterSub.textContent = 'Zamykamy wkrótce';
        } else {
            enterSub.textContent = data.status?.label || 'Zamknięte';
        }
    }

    if (statusCode === 'closed' && !data.preOrderEnabled) {
        enterBtn.disabled = true;
    }
    if (statusCode === 'closed' && data.preOrderEnabled && preorderBtn) {
        preorderBtn.classList.remove('hidden');
    }

    // Channel switch
    doorway.querySelectorAll('.doorway__channel').forEach((b) => {
        b.addEventListener('click', () => {
            doorway.querySelectorAll('.doorway__channel').forEach((x) => x.classList.remove('is-active'));
            b.classList.add('is-active');
        });
    });

    // Static/accessibility toggle
    const staticBtn = document.getElementById('doorway-static');
    staticBtn?.addEventListener('click', () => {
        doorway.classList.toggle('is-static');
    });

    // Map modal
    setupMapModal(doorway, data.contact || {}, data.tenant?.name || 'SliceHub');

    // Hours modal — reuse map modal title to show weekly schedule
    const hoursBtn = document.getElementById('doorway-hours');
    hoursBtn?.addEventListener('click', () => {
        const modal = document.getElementById('doorway-map-modal');
        const title = document.getElementById('doorway-map-title');
        const canvas = document.getElementById('doorway-map-canvas');
        const footAddr = document.getElementById('doorway-map-address');
        if (title) title.textContent = 'Godziny otwarcia';
        if (footAddr) footAddr.textContent = data.status?.label || '';
        if (canvas) {
            const rows = hoursToWeekList(data.hours?.week || {});
            canvas.innerHTML = `
                <table style="width:100%;color:#fff;border-collapse:collapse;font-size:14px">
                    ${rows.map((r) => `
                        <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
                            <td style="padding:10px 16px;opacity:.7;width:36%">${r.day}</td>
                            <td style="padding:10px 16px;text-align:right;">
                                ${r.closed ? '<span style="opacity:.5">zamknięte</span>' :
                                  `<strong style="color:var(--doorway-accent,#E8B04B)">${r.open}–${r.close}</strong>`}
                            </td>
                        </tr>
                    `).join('')}
                </table>`;
        }
        modal?.classList.remove('hidden');
        modal?.setAttribute('aria-hidden', 'false');
    });

    // Ready
    doorway.classList.remove('doorway--loading');

    // Enter action
    const finishEnter = ({ preOrder }) => {
        doorway.classList.add('is-leaving');
        setTimeout(() => {
            body.classList.add('is-door-entered');
            const channel = getActiveChannel(doorway);
            onEnter?.({ channel, preOrder: !!preOrder });
        }, 450);
    };

    enterBtn?.addEventListener('click', () => {
        if (enterBtn.disabled) return;
        finishEnter({ preOrder: false });
    });
    preorderBtn?.addEventListener('click', () => finishEnter({ preOrder: true }));

    // Keyboard: Enter shortcut
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !body.classList.contains('is-door-entered') && !doorway.classList.contains('is-leaving')) {
            if (!enterBtn.disabled) finishEnter({ preOrder: false });
        }
    }, { once: false });
}

export default { mountDoorway };
