/**
 * Panel Wachlarz — CRUD + live preview
 */
(async () => {
  'use strict';

  const meta = document.querySelector('meta[name="sh-tenant-id"]');
  const tenantId = meta ? parseInt(meta.content, 10) : 1;

  function apiUrl(path) {
    if (window.SliceHub && window.SliceHub.apiUrl) return window.SliceHub.apiUrl(path);
    return '../../../api' + (String(path || '').startsWith('/') ? path : '/' + path);
  }

  function token() {
    return (
      localStorage.getItem('sh_token') ||
      sessionStorage.getItem('sh_token') ||
      localStorage.getItem('token') ||
      ''
    );
  }

  async function api(body) {
    const res = await fetch(apiUrl('/marketing/deck_engine.php'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(token() ? { Authorization: 'Bearer ' + token() } : {}),
      },
      body: JSON.stringify(body),
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'API error');
    return j.data;
  }

  function toast(msg, ok = true) {
    const el = document.createElement('div');
    el.className = 'toast toast--' + (ok ? 'ok' : 'err');
    el.textContent = msg;
    document.getElementById('toast-root').appendChild(el);
    setTimeout(() => el.remove(), 3200);
  }

  const state = {
    decks: [],
    deckId: 0,
    cards: [],
    selectedId: null,
  };

  const $ = (id) => document.getElementById(id);

  function showBoxes(type) {
    ['cover', 'duo', 'sizes', 'list', 'cta'].forEach((k) => {
      const el = $('box-' + k);
      if (!el) return;
      const map = {
        cover: 'cover',
        hero_duo: 'duo',
        hero_sizes: 'sizes',
        hero_list: 'list',
        cta: 'cta',
      };
      el.style.display = map[type] === k ? 'block' : 'none';
    });
  }

  function selectedCard() {
    return state.cards.find((c) => String(c.id) === String(state.selectedId)) || null;
  }

  function renderList() {
    const root = $('card-list');
    root.innerHTML = '';
    state.cards
      .slice()
      .sort((a, b) => a.sort_order - b.sort_order)
      .forEach((c, idx) => {
        const p = c.payload || {};
        const row = document.createElement('div');
        row.className = 'card-item' + (String(c.id) === String(state.selectedId) ? ' active' : '');
        row.innerHTML =
          '<div class="card-item__body"><div class="card-item__title">' +
          FornoDeck.esc(p.title || c.card_key) +
          '</div><div class="card-item__meta">' +
          FornoDeck.esc(c.card_type) +
          ' · ' +
          FornoDeck.esc(p.tab_label || '') +
          '</div></div>' +
          '<button type="button" class="btn btn--ghost btn--sm" data-up title="Wyżej">↑</button>' +
          '<button type="button" class="btn btn--ghost btn--sm" data-down title="Niżej">↓</button>';
        row.querySelector('.card-item__body').onclick = () => selectCard(c.id);
        row.querySelector('[data-up]').onclick = (e) => {
          e.stopPropagation();
          moveCard(idx, -1);
        };
        row.querySelector('[data-down]').onclick = (e) => {
          e.stopPropagation();
          moveCard(idx, 1);
        };
        root.appendChild(row);
      });
  }

  function renderPreview() {
    const card = selectedCard();
    const box = $('preview');
    if (!card) {
      box.innerHTML = '<p class="hint">Brak podglądu</p>';
      return;
    }
    box.innerHTML = FornoDeck.renderCard(card);
  }

  function fillForm(card) {
    const empty = $('editor-empty');
    const form = $('editor-form');
    if (!card) {
      empty.style.display = 'block';
      form.style.display = 'none';
      return;
    }
    empty.style.display = 'none';
    form.style.display = 'block';
    const p = card.payload || {};
    $('f-type').value = card.card_type || 'hero_duo';
    $('f-accent').value = p.accent || 'orange';
    $('f-title').value = p.title || '';
    $('f-tab').value = p.tab_label || '';
    $('f-price').value = p.price || '';
    $('f-price-note').value = p.price_note || '';
    $('f-hero').value = p.hero_image || '';
    $('f-ascii').value = p.ascii_key || '';
    $('f-tagline').value = p.tagline || '';
    $('f-cta-line').value = p.cta_line || '';
    $('f-left-name').value = (p.left && p.left.name) || '';
    $('f-left-bullets').value = ((p.left && p.left.bullets) || []).join('\n');
    $('f-right-name').value = (p.right && p.right.name) || '';
    $('f-right-bullets').value = ((p.right && p.right.bullets) || []).join('\n');
    $('f-desc').value = p.description || '';
    $('f-sl-label').value = (p.size_left && p.size_left.label) || '30 cm';
    $('f-sl-price').value = (p.size_left && p.size_left.price) || '';
    $('f-sr-label').value = (p.size_right && p.size_right.label) || '37 cm';
    $('f-sr-price').value = (p.size_right && p.size_right.price) || '';
    $('f-items').value = ((p.items || [])
      .map((it) => [it.name || '', it.price || '', it.note || ''].join(' | '))
      .join('\n'));
    $('f-phone').value = p.phone || '';
    $('f-address').value = p.address || '';
    $('f-hours').value = p.hours || '';
    $('f-cta2').value = p.cta_line || '';
    showBoxes(card.card_type);
  }

  function readPayloadFromForm(type) {
    const base = {
      title: $('f-title').value.trim(),
      tab_label: $('f-tab').value.trim(),
      price: $('f-price').value.trim(),
      price_note: $('f-price-note').value.trim(),
      accent: $('f-accent').value,
      hero_image: $('f-hero').value.trim(),
      ascii_key: $('f-ascii').value.trim(),
    };
    if (type === 'cover') {
      return Object.assign(base, {
        tagline: $('f-tagline').value.trim(),
        cta_line: $('f-cta-line').value.trim(),
      });
    }
    if (type === 'hero_duo') {
      return Object.assign(base, {
        left: {
          name: $('f-left-name').value.trim(),
          bullets: $('f-left-bullets').value
            .split('\n')
            .map((s) => s.trim())
            .filter(Boolean)
            .slice(0, 5),
        },
        right: {
          name: $('f-right-name').value.trim(),
          bullets: $('f-right-bullets').value
            .split('\n')
            .map((s) => s.trim())
            .filter(Boolean)
            .slice(0, 5),
        },
      });
    }
    if (type === 'hero_sizes') {
      return Object.assign(base, {
        description: $('f-desc').value.trim(),
        size_left: {
          label: $('f-sl-label').value.trim() || '30 cm',
          price: $('f-sl-price').value.trim(),
        },
        size_right: {
          label: $('f-sr-label').value.trim() || '37 cm',
          price: $('f-sr-price').value.trim(),
        },
      });
    }
    if (type === 'hero_list') {
      const items = $('f-items')
        .value.split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .slice(0, 6)
        .map((line) => {
          const parts = line.split('|').map((s) => s.trim());
          return { name: parts[0] || '', price: parts[1] || '', note: parts[2] || '' };
        });
      return Object.assign(base, { items });
    }
    if (type === 'cta') {
      return Object.assign(base, {
        phone: $('f-phone').value.trim(),
        address: $('f-address').value.trim(),
        hours: $('f-hours').value.trim(),
        cta_line: $('f-cta2').value.trim(),
      });
    }
    return base;
  }

  function selectCard(id) {
    state.selectedId = id;
    renderList();
    fillForm(selectedCard());
    renderPreview();
  }

  async function loadDecks() {
    const data = await api({ action: 'deck_list' });
    state.decks = data.decks || [];
    const sel = $('deck-select');
    sel.innerHTML = '';
    if (!state.decks.length) {
      sel.innerHTML = '<option value="">— brak decków —</option>';
      state.deckId = 0;
      state.cards = [];
      renderList();
      fillForm(null);
      renderPreview();
      return;
    }
    state.decks.forEach((d) => {
      const o = document.createElement('option');
      o.value = d.id;
      o.textContent = d.name + ' (' + d.card_count + ')';
      sel.appendChild(o);
    });
    if (!state.deckId || !state.decks.find((d) => String(d.id) === String(state.deckId))) {
      state.deckId = state.decks[0].id;
    }
    sel.value = String(state.deckId);
    await loadDeck(state.deckId);
  }

  async function loadDeck(id) {
    state.deckId = id;
    const data = await api({ action: 'deck_get', deck_id: id });
    state.cards = data.cards || [];
    $('btn-print').href = 'print.html?deck_id=' + id;
    if (state.cards.length) {
      if (!state.cards.find((c) => String(c.id) === String(state.selectedId))) {
        state.selectedId = state.cards[0].id;
      }
    } else {
      state.selectedId = null;
    }
    renderList();
    fillForm(selectedCard());
    renderPreview();
  }

  async function moveCard(index, dir) {
    const sorted = state.cards.slice().sort((a, b) => a.sort_order - b.sort_order);
    const j = index + dir;
    if (j < 0 || j >= sorted.length) return;
    const tmp = sorted[index];
    sorted[index] = sorted[j];
    sorted[j] = tmp;
    const order = sorted.map((c) => c.id);
    try {
      const data = await api({ action: 'card_reorder', deck_id: state.deckId, order });
      state.cards = data.cards;
      renderList();
      renderPreview();
    } catch (e) {
      toast(e.message, false);
    }
  }

  $('deck-select').onchange = async () => {
    try {
      await loadDeck(parseInt($('deck-select').value, 10));
    } catch (e) {
      toast(e.message, false);
    }
  };

  $('f-type').onchange = () => {
    showBoxes($('f-type').value);
    // live preview from form
    const card = selectedCard();
    if (!card) return;
    card.card_type = $('f-type').value;
    card.payload = readPayloadFromForm(card.card_type);
    renderPreview();
  };

  ['f-title', 'f-tab', 'f-price', 'f-price-note', 'f-accent', 'f-hero', 'f-tagline', 'f-cta-line',
    'f-left-name', 'f-left-bullets', 'f-right-name', 'f-right-bullets', 'f-desc',
    'f-sl-label', 'f-sl-price', 'f-sr-label', 'f-sr-price', 'f-items',
    'f-phone', 'f-address', 'f-hours', 'f-cta2', 'f-ascii'].forEach((id) => {
    const el = $(id);
    if (!el) return;
    el.addEventListener('input', () => {
      const card = selectedCard();
      if (!card) return;
      card.card_type = $('f-type').value;
      card.payload = readPayloadFromForm(card.card_type);
      renderPreview();
    });
  });

  $('editor-form').onsubmit = async (e) => {
    e.preventDefault();
    const card = selectedCard();
    if (!card || !state.deckId) return;
    const card_type = $('f-type').value;
    const payload = readPayloadFromForm(card_type);
    try {
      const data = await api({
        action: 'card_upsert',
        deck_id: state.deckId,
        card_id: card.id,
        card_key: card.card_key,
        card_type,
        sort_order: card.sort_order,
        payload,
      });
      state.cards = data.cards;
      toast('Zapisano');
      renderList();
      fillForm(selectedCard());
      renderPreview();
    } catch (err) {
      toast(err.message, false);
    }
  };

  $('btn-del-card').onclick = async () => {
    const card = selectedCard();
    if (!card || !confirm('Usunąć kartę „' + ((card.payload && card.payload.title) || card.card_key) + '”?')) return;
    try {
      const data = await api({
        action: 'card_delete',
        deck_id: state.deckId,
        card_id: card.id,
      });
      state.cards = data.cards;
      state.selectedId = state.cards[0] ? state.cards[0].id : null;
      toast('Usunięto');
      renderList();
      fillForm(selectedCard());
      renderPreview();
    } catch (e) {
      toast(e.message, false);
    }
  };

  $('btn-add-card').onclick = async () => {
    if (!state.deckId) {
      toast('Najpierw utwórz / zaseeduj deck', false);
      return;
    }
    const type = 'hero_duo';
    try {
      const data = await api({
        action: 'card_upsert',
        deck_id: state.deckId,
        card_type: type,
        payload: FornoDeck.emptyPayload(type),
      });
      state.cards = data.cards;
      state.selectedId = data.card_id;
      toast('Dodano kartę');
      renderList();
      fillForm(selectedCard());
      renderPreview();
    } catch (e) {
      toast(e.message, false);
    }
  };

  $('btn-new-deck').onclick = async () => {
    const name = prompt('Nazwa wachlarza', 'Nowy wachlarz');
    if (!name) return;
    try {
      const data = await api({ action: 'deck_create', name });
      state.deckId = data.deck.id;
      toast('Utworzono deck');
      await loadDecks();
    } catch (e) {
      toast(e.message, false);
    }
  };

  $('btn-seed').onclick = async () => {
    const force = state.decks.some((d) => d.ascii_key === 'FORNO_WACHLARZ_V1')
      ? confirm('Deck Forno już jest. Nadpisać karty?')
      : false;
    try {
      const data = await api({ action: 'deck_seed_forno', force: !!force });
      state.deckId = data.deck.id;
      toast(data.seeded ? 'Zaseedowano Forno' : 'Deck już istniał');
      await loadDecks();
    } catch (e) {
      toast(e.message, false);
    }
  };

  // boot
  try {
    await loadDecks();
    if (!state.decks.length) {
      // auto-seed empty tenant once
      const data = await api({ action: 'deck_seed_forno', force: false });
      state.deckId = data.deck.id;
      await loadDecks();
      toast('Wgrano startowy wachlarz Forno');
    }
  } catch (e) {
    toast(e.message + ' — zaloguj się (JWT) jako manager/owner', false);
  }
})();
