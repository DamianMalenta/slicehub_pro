/**
 * FornoDeck — renderer kart A5 (panel + print.html)
 * Payload shape: _docs/ulotki/forno-wachlarz/content.json
 */
(function (global) {
  'use strict';

  const LIMITS = {
    tab_label: 14,
    title: 28,
    bullet: 36,
    bulletsMax: 5,
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function clip(s, n) {
    const t = String(s == null ? '' : s).trim();
    return t.length <= n ? t : t.slice(0, n - 1) + '…';
  }

  function accentClass(accent) {
    const a = String(accent || 'orange').toLowerCase();
    if (a === 'yellow' || a === 'teal' || a === 'orange') return a;
    return 'orange';
  }

  function bulletsHtml(list) {
    const items = Array.isArray(list) ? list.slice(0, LIMITS.bulletsMax) : [];
    if (!items.length) return '';
    return (
      '<ul class="deck-card__bullets">' +
      items
        .map(function (b) {
          return '<li>' + esc(clip(b, LIMITS.bullet)) + '</li>';
        })
        .join('') +
      '</ul>'
    );
  }

  function photoHtml(payload, title) {
    const src = (payload.hero_image || '').trim();
    if (src) {
      return (
        '<div class="deck-card__photo"><img src="' +
        esc(src) +
        '" alt="' +
        esc(title) +
        '"></div>'
      );
    }
    return (
      '<div class="deck-card__photo"><div class="deck-card__photo-placeholder" data-label="' +
      esc(clip(title || 'FORNO', 16)) +
      '"></div></div>'
    );
  }

  function priceBlock(payload) {
    const price = String(payload.price || '').trim();
    if (!price) return '';
    const note = payload.price_note
      ? '<span class="deck-card__price-note">' + esc(payload.price_note) + '</span>'
      : '';
    return (
      '<div class="deck-card__price-wrap">' +
      note +
      '<span class="deck-card__price">' +
      esc(price) +
      ' zł</span></div>'
    );
  }

  function shell(cardType, payload, innerHero, innerBody) {
    const accent = accentClass(payload.accent);
    const title = clip(payload.title || '', LIMITS.title);
    const tab = clip(payload.tab_label || '', LIMITS.tab_label);
    return (
      '<article class="deck-card deck-card--' +
      esc(cardType) +
      ' deck-card--accent-' +
      accent +
      '" data-card-type="' +
      esc(cardType) +
      '">' +
      '<div class="deck-card__screw" aria-hidden="true"></div>' +
      (tab ? '<div class="deck-card__tab">' + esc(tab) + '</div>' : '') +
      '<div class="deck-card__hero">' +
      '<h2 class="deck-card__title">' +
      esc(title) +
      '</h2>' +
      priceBlock(payload) +
      innerHero +
      '</div>' +
      '<hr class="deck-card__divider">' +
      '<div class="deck-card__body">' +
      innerBody +
      '</div></article>'
    );
  }

  function renderCover(payload) {
    const hero = photoHtml(payload, payload.title);
    const body =
      '<p class="deck-card__tagline">' +
      esc(payload.tagline || '') +
      '</p>' +
      '<p class="deck-card__cta-line">' +
      esc(payload.cta_line || '') +
      '</p>';
    return shell('cover', payload, hero, body);
  }

  function renderDuo(payload) {
    const left = payload.left || {};
    const right = payload.right || {};
    const hero = photoHtml(payload, payload.title);
    const body =
      '<div class="deck-card__cols">' +
      '<div><h3 class="deck-card__col-title">' +
      esc(left.name || '') +
      '</h3>' +
      bulletsHtml(left.bullets) +
      '</div><div><h3 class="deck-card__col-title">' +
      esc(right.name || '') +
      '</h3>' +
      bulletsHtml(right.bullets) +
      '</div></div>';
    return shell('hero_duo', payload, hero, body);
  }

  function renderSizes(payload) {
    const L = payload.size_left || {};
    const R = payload.size_right || {};
    const hero = photoHtml(payload, payload.title);
    const body =
      (payload.description
        ? '<p class="deck-card__desc">' + esc(payload.description) + '</p>'
        : '') +
      '<div class="deck-card__cols">' +
      '<div><h3 class="deck-card__col-title">' +
      esc(L.label || '30 cm') +
      '</h3><div class="deck-card__size-price">' +
      esc(L.price || '') +
      ' zł</div></div>' +
      '<div><h3 class="deck-card__col-title">' +
      esc(R.label || '37 cm') +
      '</h3><div class="deck-card__size-price">' +
      esc(R.price || '') +
      ' zł</div></div></div>';
    return shell('hero_sizes', payload, hero, body);
  }

  function renderList(payload) {
    const items = Array.isArray(payload.items) ? payload.items.slice(0, 6) : [];
    const hero = photoHtml(payload, payload.title);
    const body =
      '<div class="deck-card__list">' +
      items
        .map(function (it) {
          return (
            '<div class="deck-card__list-item"><span class="deck-card__list-name">' +
            esc(it.name || '') +
            '</span><span class="deck-card__list-meta"><span>' +
            esc(it.note || '') +
            '</span><strong>' +
            esc(it.price || '') +
            (it.price ? ' zł' : '') +
            '</strong></span></div>'
          );
        })
        .join('') +
      '</div>';
    return shell('hero_list', payload, hero, body);
  }

  function renderCta(payload) {
    const hero = photoHtml(payload, payload.title);
    const phone = (payload.phone || '').trim();
    const body =
      '<div class="deck-card__cta-block">' +
      (phone ? '<p class="deck-card__phone">' + esc(phone) + '</p>' : '') +
      (payload.address ? '<p>' + esc(payload.address) + '</p>' : '') +
      (payload.hours ? '<p>' + esc(payload.hours) + '</p>' : '') +
      '<p class="deck-card__cta-line">' +
      esc(payload.cta_line || '') +
      '</p></div>';
    return shell('cta', payload, hero, body);
  }

  function normalizeCard(raw) {
    const card = raw || {};
    const payload =
      typeof card.payload === 'object' && card.payload
        ? card.payload
        : typeof card.payload_json === 'string'
          ? JSON.parse(card.payload_json || '{}')
          : card.payload_json || card;
    return {
      id: card.id || null,
      card_key: card.card_key || payload.card_key || '',
      card_type: card.card_type || payload.card_type || 'hero_duo',
      sort_order: card.sort_order != null ? card.sort_order : 0,
      payload: payload,
    };
  }

  function renderCard(raw) {
    const card = normalizeCard(raw);
    const p = card.payload || {};
    switch (card.card_type) {
      case 'cover':
        return renderCover(p);
      case 'hero_duo':
        return renderDuo(p);
      case 'hero_sizes':
        return renderSizes(p);
      case 'hero_list':
        return renderList(p);
      case 'cta':
        return renderCta(p);
      default:
        return renderDuo(p);
    }
  }

  function renderDeck(cards, container) {
    const list = Array.isArray(cards) ? cards.slice() : [];
    list.sort(function (a, b) {
      return (a.sort_order || 0) - (b.sort_order || 0);
    });
    const html = list.map(renderCard).join('');
    if (container) container.innerHTML = html;
    return html;
  }

  function emptyPayload(type) {
    const base = {
      title: 'NOWA KARTA',
      tab_label: 'NOWA',
      price: '',
      price_note: '',
      accent: 'orange',
      hero_image: '',
      ascii_key: '',
    };
    switch (type) {
      case 'cover':
        return Object.assign(base, {
          title: 'FORNO PIZZA',
          tagline: '',
          cta_line: '',
          tab_label: 'START',
        });
      case 'hero_sizes':
        return Object.assign(base, {
          description: '',
          size_left: { label: '30 cm', price: '' },
          size_right: { label: '37 cm', price: '' },
        });
      case 'hero_list':
        return Object.assign(base, { items: [{ name: '', price: '', note: '' }] });
      case 'cta':
        return Object.assign(base, {
          title: 'ZAMÓW',
          phone: '',
          hours: '',
          address: '',
          cta_line: '',
          tab_label: 'KONTAKT',
        });
      case 'hero_duo':
      default:
        return Object.assign(base, {
          left: { name: 'Wariant A', bullets: [''] },
          right: { name: 'Wariant B', bullets: [''] },
        });
    }
  }

  global.FornoDeck = {
    LIMITS: LIMITS,
    esc: esc,
    clip: clip,
    normalizeCard: normalizeCard,
    renderCard: renderCard,
    renderDeck: renderDeck,
    emptyPayload: emptyPayload,
  };
})(typeof window !== 'undefined' ? window : globalThis);
