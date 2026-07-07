window.ItemEditor = {
    _debounceTimers: {},
    _vatInheritEnabled: true,
    // F-S4-fix (2026-05-13): default 'Live' żeby nowo dodana pozycja była natychmiast widoczna w POS.
    // POS engine filtruje publication_status IN ('Live', 'published'). Stary default 'Draft' powodował
    // że manager musiał ręcznie kliknąć "Live" przed save, inaczej pizza znikała z POS.
    _currentPubStatus: 'Live',
    _currentItemType: 'standard',
    _observer: null,

    debounce(key, fn, ms = 400) {
        clearTimeout(this._debounceTimers[key]);
        this._debounceTimers[key] = setTimeout(fn, ms);
    },

    toAutoSlug(val) {
        const map = {
            'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z',
            'Ą':'A','Ć':'C','Ę':'E','Ł':'L','Ń':'N','Ó':'O','Ś':'S','Ź':'Z','Ż':'Z',
            ' ':'_'
        };
        return (val||'').split('').map(c => map[c]||c).join('').replace(/[^a-zA-Z0-9_]/g, '').toUpperCase();
    },

    setPubStatus(status) {
        this._currentPubStatus = status;
        document.querySelectorAll('.pub-btn').forEach(btn => {
            const s = btn.dataset.status;
            const active = s === status;
            const colors = {
                Draft:    active ? 'bg-yellow-500/80 text-black shadow-[0_0_12px_rgba(234,179,8,0.4)]' : '',
                Live:     active ? 'bg-green-500 text-white shadow-[0_0_12px_rgba(34,197,94,0.5)]' : '',
                Archived: active ? 'bg-red-500/80 text-white shadow-[0_0_12px_rgba(239,68,68,0.4)]' : ''
            };
            btn.className = 'pub-btn px-3 py-1.5 text-[9px] font-black uppercase transition-all ' +
                (active ? colors[s] : 'bg-white/5 text-slate-500 hover:text-white hover:bg-white/10');
        });
    },

    setItemType(type) {
        this._currentItemType = type;
        const hidden = document.getElementById('item-type');
        if (hidden) hidden.value = type;
        document.querySelectorAll('.type-btn').forEach(btn => {
            const t = btn.dataset.type;
            const active = t === type;
            btn.className = 'type-btn flex-1 px-3 py-3 text-[9px] font-black uppercase transition-all flex items-center justify-center gap-1.5 ' +
                (active ? 'bg-purple-500/80 text-white' : 'bg-white/5 text-slate-500 hover:text-white hover:bg-white/10');
        });
    },

    scrollToSection(sectionId) {
        const el = document.getElementById(sectionId);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    autoFillOmnichannel() {
        const posPrice = parseFloat(document.getElementById('item-price-pos')?.value) || 0;
        const takeawayInput = document.getElementById('item-price-takeaway');
        const deliveryInput = document.getElementById('item-price-delivery');
        if (takeawayInput) takeawayInput.value = posPrice.toFixed(2);
        if (deliveryInput) deliveryInput.value = (posPrice * 1.10).toFixed(2);
        [takeawayInput, deliveryInput].forEach(el => {
            if (!el) return;
            el.classList.add('border-cyan-400', 'ring-1', 'ring-cyan-400/30');
            setTimeout(() => el.classList.remove('border-cyan-400', 'ring-1', 'ring-cyan-400/30'), 1500);
        });
    },

    toggleVatInherit(enabled) {
        this._vatInheritEnabled = enabled;
        ['item-vat-dine-in', 'item-vat-takeaway'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.disabled = enabled;
            const wrapper = el.closest('.vat-field-wrap');
            if (enabled) {
                el.classList.add('opacity-50');
                if (wrapper) { wrapper.classList.remove('border-cyan-500/30'); wrapper.classList.add('border-purple-500/20'); }
            } else {
                el.classList.remove('opacity-50');
                if (wrapper) { wrapper.classList.add('border-cyan-500/30'); wrapper.classList.remove('border-purple-500/20'); }
            }
        });
        const label = document.getElementById('vat-inherit-label');
        if (label) {
            label.textContent = enabled ? 'AUTO — dziedziczone z kategorii' : 'MANUAL — edycja ręczna';
            label.className = 'text-[8px] font-black uppercase tracking-wider ' + (enabled ? 'text-purple-400' : 'text-cyan-400');
        }
    },

    _populateCommandBar() {
        const bar = document.getElementById('item-command-bar');
        if (!bar) return;
        bar.innerHTML = `
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-4 flex-wrap">
                <div class="flex items-center gap-2">
                    <span class="text-[8px] font-black uppercase text-slate-500 tracking-widest hidden lg:block">Publikacja</span>
                    <div class="flex rounded-lg overflow-hidden border border-white/10">
                        <button type="button" data-status="Draft" onclick="window.ItemEditor.setPubStatus('Draft')" class="pub-btn px-3 py-1.5 text-[9px] font-black uppercase bg-yellow-500/80 text-black transition-all shadow-[0_0_12px_rgba(234,179,8,0.4)]">Draft</button>
                        <button type="button" data-status="Live" onclick="window.ItemEditor.setPubStatus('Live')" class="pub-btn px-3 py-1.5 text-[9px] font-black uppercase bg-white/5 text-slate-500 hover:text-white hover:bg-white/10 transition-all">Live</button>
                        <button type="button" data-status="Archived" onclick="window.ItemEditor.setPubStatus('Archived')" class="pub-btn px-3 py-1.5 text-[9px] font-black uppercase bg-white/5 text-slate-500 hover:text-white hover:bg-white/10 transition-all">Archived</button>
                    </div>
                </div>
                <label class="flex items-center gap-2 cursor-pointer group">
                    <div class="relative">
                        <input type="checkbox" id="item-is-secret" class="sr-only peer">
                        <div class="w-8 h-[18px] bg-white/10 rounded-full peer-checked:bg-purple-500 transition-all after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-[14px] after:w-[14px] after:transition-all peer-checked:after:translate-x-[14px]"></div>
                    </div>
                    <span class="text-[8px] font-black uppercase text-slate-500 group-hover:text-purple-400 transition"><i class="fa-solid fa-lock text-[7px] mr-0.5"></i> Tajne Menu</span>
                </label>
                <div id="hq-lock-flag" class="hidden items-center gap-1 bg-red-900/30 border border-red-500/30 rounded-lg px-2.5 py-1">
                    <i class="fa-solid fa-shield-halved text-red-400 text-[10px]"></i>
                    <span class="text-[8px] font-black uppercase text-red-400 tracking-wider">HQ LOCK</span>
                </div>
            </div>
            <button type="button" id="btn-save-item" onclick="window.ItemEditor.saveItem()" class="bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white px-8 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest shadow-[0_0_25px_rgba(37,99,235,0.4)] hover:shadow-[0_0_35px_rgba(37,99,235,0.6)] transition-all flex items-center gap-2 active:scale-95">
                <i class="fa-solid fa-rocket"></i> ZAPISZ DANIE
            </button>
        </div>`;
    },

    _populateAnchorNav() {
        const nav = document.getElementById('item-anchor-nav');
        if (!nav) return;
        nav.classList.remove('hidden');
        const items = [
            { id:'sec-identity',   icon:'fa-fingerprint',    tip:'Tożsamość',    hc:'hover:text-purple-400 hover:border-purple-500/30 hover:bg-purple-500/10' },
            { id:'sec-variants',   icon:'fa-arrows-left-right-to-line', tip:'Rozmiary / Warianty', hc:'hover:text-orange-400 hover:border-orange-500/30 hover:bg-orange-500/10' },
            { id:'sec-matrix',     icon:'fa-table-cells',    tip:'Macierz Cen',  hc:'hover:text-cyan-400 hover:border-cyan-500/30 hover:bg-cyan-500/10' },
            { id:'sec-vat',        icon:'fa-receipt',        tip:'VAT',          hc:'hover:text-amber-400 hover:border-amber-500/30 hover:bg-amber-500/10' },
            { id:'sec-modifiers',  icon:'fa-puzzle-piece',   tip:'Modyfikatory', hc:'hover:text-purple-400 hover:border-purple-500/30 hover:bg-purple-500/10' },
            { id:'sec-visual',     icon:'fa-layer-group',    tip:'Visual Layers', hc:'hover:text-amber-400 hover:border-amber-500/30 hover:bg-amber-500/10' },
            { id:'sec-logistics',  icon:'fa-truck-fast',     tip:'Logistyka',    hc:'hover:text-blue-400 hover:border-blue-500/30 hover:bg-blue-500/10' },
            { id:'sec-schedule',   icon:'fa-calendar-days',  tip:'Harmonogram',  hc:'hover:text-emerald-400 hover:border-emerald-500/30 hover:bg-emerald-500/10' },
            { id:'sec-marketing',  icon:'fa-bullhorn',       tip:'Marketing',    hc:'hover:text-rose-400 hover:border-rose-500/30 hover:bg-rose-500/10' },
            { id:'sec-enterprise', icon:'fa-bolt',           tip:'Enterprise',   hc:'hover:text-emerald-400 hover:border-emerald-500/30 hover:bg-emerald-500/10' },
        ];
        nav.innerHTML = items.map(s =>
            `<button type="button" onclick="window.ItemEditor.scrollToSection('${s.id}')" class="anchor-btn w-9 h-9 rounded-xl bg-white/5 border border-white/5 flex items-center justify-center text-slate-500 ${s.hc} transition-all" title="${s.tip}" data-section="${s.id}"><i class="fa-solid ${s.icon} text-[10px]"></i></button>`
        ).join('');
    },

    _setupScrollSpy() {
        if (this._observer) this._observer.disconnect();
        const sections = document.querySelectorAll('#item-form > section[id]');
        if (!sections.length) return;
        this._observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                const btn = document.querySelector(`.anchor-btn[data-section="${entry.target.id}"]`);
                if (!btn) return;
                if (entry.isIntersecting) {
                    btn.classList.add('bg-white/10', 'text-white', 'border-white/20');
                } else {
                    btn.classList.remove('bg-white/10', 'text-white', 'border-white/20');
                }
            });
        }, { root: document.getElementById('menu-view-scroll'), threshold: 0.2 });
        sections.forEach(sec => this._observer.observe(sec));
    },

    _glassCard(id, color, icon, title, content) {
        return `
        <section id="${id}" class="bg-white/[0.03] backdrop-blur-md border border-white/[0.07] rounded-2xl p-6 relative overflow-hidden transition-all hover:border-white/10">
            <div class="absolute top-0 left-0 w-1 h-full bg-${color}-500 shadow-[0_0_8px_rgba(var(--tw-shadow-color),0.5)]"></div>
            <h3 class="text-[11px] font-black uppercase text-${color}-400 tracking-widest mb-5 flex items-center gap-2 pl-2">
                <i class="fa-solid ${icon}"></i> ${title}
            </h3>
            <div class="pl-2">${content}</div>
        </section>`;
    },

    _inp(id, label, placeholder, extra = '') {
        return `<div class="flex flex-col gap-1.5 ${extra}">
            <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">${label}</label>
            <input type="text" id="${id}" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none transition" placeholder="${placeholder}">
        </div>`;
    },

    ensureOmnichannelForm() {
        const form = document.getElementById('item-form');
        if (!form || form.dataset.omnichannelReady === '1') return;

        this._populateCommandBar();
        this._populateAnchorNav();

        const fcPanel = document.getElementById('food-cost-panel');
        if (fcPanel) fcPanel.classList.remove('hidden');

        const modGroupsHtml = this._renderModifierGroupCheckboxes();

        const DAYS = ['Pn','Wt','Śr','Cz','Pt','Sb','Nd'];
        const daysCheckboxes = DAYS.map((d, i) =>
            `<label class="cursor-pointer"><input type="checkbox" class="day-checkbox sr-only peer" value="${i+1}" checked>
             <div class="w-9 h-9 rounded-lg bg-black/50 border border-white/10 flex items-center justify-center text-[10px] font-black text-slate-500 peer-checked:bg-emerald-900/40 peer-checked:border-emerald-500/50 peer-checked:text-emerald-300 transition-all">${d}</div></label>`
        ).join('');

        const ALLERGENS = ['Gluten','Laktoza','Orzechy','Skorupiaki','Jaja','Ryby','Soja','Seler','Gorczyca','Sezam','Mięczaki'];
        const allergensHtml = ALLERGENS.map(a =>
            `<label class="cursor-pointer"><input type="checkbox" class="allergen-checkbox sr-only peer" value="${a}">
             <div class="bg-black/50 border border-white/10 text-slate-400 text-[10px] px-3 py-1.5 rounded-lg peer-checked:bg-emerald-900/40 peer-checked:border-emerald-500/50 peer-checked:text-emerald-300 transition-all">${a}</div></label>`
        ).join('');

        form.innerHTML = `
            <input type="hidden" id="item-id" value="0">
            <input type="hidden" id="item-type" value="standard">

            ${this._glassCard('sec-identity', 'purple', 'fa-fingerprint', 'Tożsamość i Typologia', `
                <div class="grid grid-cols-2 gap-4">
                    <div id="lock-item-name-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Nazwa Dania <span class="text-red-400">*</span></label>
                        <input type="text" id="item-name" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none transition" placeholder="np. Pizza Margherita">
                    </div>
                    <div id="lock-item-ascii-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Klucz SKU <span class="text-red-400">*</span></label>
                        <div class="relative">
                            <input type="text" id="item-ascii-key" class="w-full bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none transition font-mono uppercase pr-8" placeholder="PIZZA_MARGHERITA">
                            <i class="fa-solid fa-robot absolute right-3 top-1/2 -translate-y-1/2 text-purple-500/50 text-[10px]" title="Auto z nazwy"></i>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Kategoria <span class="text-red-400">*</span></label>
                        <select id="item-category-id" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none transition cursor-pointer">
                            <option value="0">Wybierz kategorię...</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Typ Dania</label>
                        <div class="flex rounded-xl overflow-hidden border border-white/10">
                            <button type="button" data-type="standard" onclick="window.ItemEditor.setItemType('standard')" class="type-btn flex-1 px-3 py-3 text-[9px] font-black uppercase bg-purple-500/80 text-white transition-all flex items-center justify-center gap-1.5"><i class="fa-solid fa-circle text-[6px]"></i> Standard</button>
                            <button type="button" data-type="half_half" onclick="window.ItemEditor.setItemType('half_half')" class="type-btn flex-1 px-3 py-3 text-[9px] font-black uppercase bg-white/5 text-slate-500 hover:text-white transition-all flex items-center justify-center gap-1.5"><i class="fa-solid fa-circle-half-stroke text-[6px]"></i> Pół/Pół</button>
                        </div>
                    </div>
                </div>
            `)}

            ${this._glassCard('sec-variants', 'orange', 'fa-arrows-left-right-to-line', 'Rozmiary / Warianty (F-S1)', `
                <p class="text-[10px] text-slate-400 mb-3 leading-relaxed">
                    Wybierz <strong class="text-orange-300">Skalę Rozmiarów</strong> żeby ta pozycja stała się
                    <strong class="text-orange-300">parentem</strong> — sprzedaż w POS dostanie kafelek z wyborem rozmiaru.
                    Receptura wpisana RAZ na parent, mnożona przez <code class="text-orange-300">multiplier(option)</code>.
                </p>
                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-7 flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Skala Rozmiarów</label>
                        <select id="item-variant-scale" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500/30 focus:outline-none transition cursor-pointer">
                            <option value="">— Brak (zwykła pozycja standalone) —</option>
                        </select>
                    </div>
                    <div class="col-span-5 flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Akcje</label>
                        <div class="flex gap-2">
                            <button type="button" onclick="window.ItemEditor.openVariantScaleManager()" class="flex-1 text-[8px] font-black uppercase text-orange-400 bg-orange-500/10 border border-orange-500/20 rounded-lg px-3 py-2 hover:bg-orange-500/20 transition flex items-center justify-center gap-1.5"><i class="fa-solid fa-list"></i> Zarządzaj skalami</button>
                            <button type="button" onclick="window.ItemEditor.generateVariantFamily()" class="flex-1 text-[8px] font-black uppercase text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-3 py-2 hover:bg-emerald-500/20 transition flex items-center justify-center gap-1.5" id="btn-generate-variant-family" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Wygeneruj rodzinę</button>
                        </div>
                    </div>
                </div>
                <div id="variant-children-preview" class="mt-4 hidden">
                    <div class="text-[9px] text-slate-400 uppercase font-bold mb-2">Warianty (children):</div>
                    <div id="variant-children-list" class="grid grid-cols-3 gap-2"></div>
                </div>
                <div class="mt-3 text-[10px] text-slate-500 leading-relaxed bg-black/30 rounded-lg p-2 border border-white/5">
                    <i class="fa-solid fa-circle-info text-orange-400 mr-1"></i>
                    <strong>Bliźniak Cyfrowy (Konstytucja v5 § Prawo II):</strong>
                    parent = abstrakcyjny szablon, nie sprzedawalny w POS. Children = realne SKU (np. <code>PIZZA_MARGHERITA_S</code>) z własnymi cenami i mnożnikiem receptury.
                </div>
            `)}

            ${this._glassCard('sec-matrix', 'cyan', 'fa-table-cells', 'Macierz Cenowa Omnichannel', `
                <div class="flex items-center justify-end mb-4">
                    <button type="button" onclick="window.ItemEditor.autoFillOmnichannel()" class="text-[8px] font-black uppercase text-cyan-400 bg-cyan-500/10 border border-cyan-500/20 rounded-lg px-3 py-1.5 hover:bg-cyan-500/20 transition flex items-center gap-1.5">
                        <span>🪄</span> Autouzupełnianie Omnichannel
                    </button>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div class="flex flex-col gap-2 bg-black/30 rounded-xl p-4 border border-blue-500/10">
                        <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-blue-500"></div><span class="text-[9px] text-blue-400 font-black uppercase">POS (Na Sali)</span></div>
                        <input type="number" id="item-price-pos" step="0.01" min="0" value="0.00" class="bg-black/50 border border-white/10 text-white rounded-lg p-3 text-lg font-black text-center focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 focus:outline-none transition tabular-nums">
                        <span class="text-[8px] text-slate-600 text-center">PLN brutto</span>
                    </div>
                    <div class="flex flex-col gap-2 bg-black/30 rounded-xl p-4 border border-green-500/10">
                        <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-green-500"></div><span class="text-[9px] text-green-400 font-black uppercase">Takeaway (Wynos)</span></div>
                        <input type="number" id="item-price-takeaway" step="0.01" min="0" value="0.00" class="bg-black/50 border border-white/10 text-white rounded-lg p-3 text-lg font-black text-center focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 focus:outline-none transition tabular-nums">
                        <span class="text-[8px] text-slate-600 text-center">PLN brutto</span>
                    </div>
                    <div class="flex flex-col gap-2 bg-black/30 rounded-xl p-4 border border-orange-500/10">
                        <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-orange-500"></div><span class="text-[9px] text-orange-400 font-black uppercase">Delivery (Dostawa)</span></div>
                        <input type="number" id="item-price-delivery" step="0.01" min="0" value="0.00" class="bg-black/50 border border-white/10 text-white rounded-lg p-3 text-lg font-black text-center focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 focus:outline-none transition tabular-nums">
                        <span class="text-[8px] text-slate-600 text-center">PLN brutto</span>
                    </div>
                </div>
            `)}

            ${this._glassCard('sec-vat', 'amber', 'fa-receipt', 'Piramida VAT', `
                <div class="flex items-center justify-between mb-4">
                    <label class="flex items-center gap-2.5 cursor-pointer group">
                        <div class="relative">
                            <input type="checkbox" id="item-vat-inherit" class="sr-only peer" checked>
                            <div class="w-8 h-[18px] bg-white/10 rounded-full peer-checked:bg-purple-500 transition-all after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-[14px] after:w-[14px] after:transition-all peer-checked:after:translate-x-[14px]"></div>
                        </div>
                        <span id="vat-inherit-label" class="text-[8px] font-black uppercase tracking-wider text-purple-400">AUTO — dziedziczone z kategorii</span>
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="vat-field-wrap flex flex-col gap-1.5 bg-black/30 rounded-xl p-4 border border-purple-500/20 transition-colors">
                        <span class="text-[8px] text-slate-500 font-bold uppercase">Na Sali (Dine-in)</span>
                        <select id="item-vat-dine-in" class="bg-black/50 border border-white/10 text-white rounded-lg p-2.5 text-xs focus:border-amber-500 focus:outline-none transition cursor-pointer opacity-50" disabled>
                            <option value="23">23%</option><option value="8" selected>8%</option><option value="5">5%</option><option value="0">0%</option>
                        </select>
                    </div>
                    <div class="vat-field-wrap flex flex-col gap-1.5 bg-black/30 rounded-xl p-4 border border-purple-500/20 transition-colors">
                        <span class="text-[8px] text-slate-500 font-bold uppercase">Wynos / Dostawa</span>
                        <select id="item-vat-takeaway" class="bg-black/50 border border-white/10 text-white rounded-lg p-2.5 text-xs focus:border-amber-500 focus:outline-none transition cursor-pointer opacity-50" disabled>
                            <option value="23">23%</option><option value="8">8%</option><option value="5" selected>5%</option><option value="0">0%</option>
                        </select>
                    </div>
                </div>
            `)}

            ${this._glassCard('sec-modifiers', 'purple', 'fa-puzzle-piece', 'Powiązane Grupy Modyfikatorów', `
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2" id="modifierGroupsCheckboxContainer">
                    ${modGroupsHtml}
                </div>
            `)}

            ${this._glassCard('sec-visual', 'amber', 'fa-clapperboard', 'Wizualna Kompozycja Dania', `
                <div id="visual-director-gate" class="flex flex-col gap-4">

                    <!-- Miniatura hero + badge sceny + picker -->
                    <div class="flex gap-4 items-start">
                        <div class="flex flex-col gap-2 flex-shrink-0">
                            <div id="item-hero-preview" class="relative w-28 h-28 rounded-xl bg-black/40 border border-white/10 overflow-hidden flex items-center justify-center">
                                <i class="fa-solid fa-image text-slate-700 text-2xl" id="item-hero-preview-placeholder"></i>
                                <img id="item-hero-preview-img" src="" alt="" class="w-full h-full object-cover hidden">
                                <span id="item-hero-scene-badge" class="absolute bottom-1 left-1 right-1 text-center text-[7px] font-black uppercase tracking-widest px-1 py-0.5 rounded bg-black/70 border hidden"></span>
                            </div>
                            <div class="flex gap-1">
                                <button type="button" id="btn-item-hero-pick"
                                        onclick="window.ItemEditor.openItemHeroPicker()"
                                        class="flex-1 inline-flex items-center justify-center gap-1.5 bg-amber-900/30 hover:bg-amber-600 text-amber-200 hover:text-black border border-amber-500/40 font-black uppercase tracking-wider text-[8px] px-2 py-1.5 rounded-lg transition">
                                    <i class="fa-solid fa-image text-[9px]"></i>
                                    <span id="btn-item-hero-pick-label">Przypisz Hero</span>
                                </button>
                                <button type="button" id="btn-item-hero-unlink"
                                        onclick="window.ItemEditor.unlinkItemHero()"
                                        class="hidden bg-red-900/30 hover:bg-red-600 text-red-300 hover:text-white border border-red-500/40 font-black uppercase tracking-wider text-[8px] px-2 py-1.5 rounded-lg transition"
                                        title="Odłącz hero od dania">
                                    <i class="fa-solid fa-link-slash text-[9px]"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex-1 flex flex-col gap-2">
                            <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Profil Kompozycji</label>
                            <select id="item-composition-profile" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-amber-500 focus:outline-none transition cursor-pointer">
                                <option value="static_hero">Gotowe zdjęcie dania (uniwersalny)</option>
                                <option value="pizza_top_down">Pizza — kamera z góry (warstwy)</option>
                            </select>
                            <p class="text-slate-600 text-[9px] leading-relaxed">
                                <i class="fa-solid fa-circle-info text-[8px] mr-1 opacity-40"></i>
                                <span id="item-composition-hint">Danie renderowane z jednego zdjęcia (burger, makaron, napoje).</span>
                            </p>
                        </div>
                    </div>

                    <p class="text-slate-400 text-[11px] leading-relaxed">
                        Pełna scena (warstwy pizzy, scenografia, oświetlenie, companions, promocje) powstaje w
                        <strong class="text-amber-400">Scene Studio</strong>.
                        Tu w Menu Studio zarządzasz logiką dania — ceny, modyfikatory, magazyn, profil kompozycji.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <a id="btn-open-visual-director"
                           href="../online_studio/index.html"
                           target="_blank"
                           rel="noopener"
                           class="inline-flex items-center justify-center gap-2 bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-400 hover:to-orange-500 text-black font-black uppercase tracking-wider text-[10px] px-4 py-3 rounded-xl transition-all shadow-lg shadow-amber-500/20">
                            <i class="fa-solid fa-clapperboard"></i>
                            Otwórz w Scene Studio
                            <i class="fa-solid fa-arrow-up-right-from-square text-[8px] opacity-70"></i>
                        </a>
                        <button type="button" id="btn-autogenerate-scene"
                                onclick="window.ItemEditor.autogenerateScene()"
                                class="inline-flex items-center justify-center gap-2 bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-black uppercase tracking-wider text-[10px] px-4 py-3 rounded-xl transition-all shadow-lg shadow-violet-500/30 disabled:opacity-40 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <span>Wygeneruj automatycznie</span>
                        </button>
                    </div>
                    <div id="autogen-result" class="hidden mt-2 p-3 rounded-xl border text-[10px] font-bold leading-relaxed"></div>
                    <p class="text-slate-600 text-[9px] font-bold uppercase tracking-widest">
                        <i class="fa-solid fa-circle-info text-[8px] mr-1 opacity-40"></i>
                        Auto-generator składa scenę z hero dania + domyślnych modyfikatorów (NONE · wpływ wizualny · layer_top_down).
                        Zapisz danie, aby uruchomić generator.
                    </p>
                </div>
            `)}

            ${this._glassCard('sec-logistics', 'blue', 'fa-truck-fast', 'Logistyka i Battlefield Routing', `
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Grupa Drukarek</label>
                        <select id="item-printer-group" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-blue-500 focus:outline-none transition cursor-pointer">
                            <option value="KITCHEN_1">Kuchnia Główna</option><option value="KITCHEN_2">Kuchnia 2</option><option value="BAR">Bar</option><option value="NONE">Brak</option>
                        </select>
                    </div>
                    <div id="lock-item-kds-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">KDS Station</label>
                        <select id="item-kds-station-id" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-blue-500 focus:outline-none transition cursor-pointer">
                            <option value="KITCHEN_1">Kuchnia Główna</option><option value="BAR">Bar</option><option value="NONE">Brak</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 bg-black/30 rounded-xl p-4 border border-blue-500/10">
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-400 text-[10px]"></i>
                        <span class="text-[9px] text-amber-400 font-black uppercase tracking-wider">Ostrzeżenia dla Kierowcy / KDS</span>
                    </div>
                    <select id="item-driver-action-type" class="w-full bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500/30 focus:outline-none transition cursor-pointer">
                        <option value="none">Brak (Standard)</option>
                        <option value="pack_cold">❄️ ZIMNE (Pakuj osobno do lodówki)</option>
                        <option value="pack_separate">🌿 OSOBNO (Kruche / Nie kładź na gorące)</option>
                        <option value="check_id">🔞 DOKUMENTY (Sprawdź wiek klienta)</option>
                    </select>
                    <p class="text-[8px] text-slate-600 mt-2">Wybrany alert pojawi się na KDS i w aplikacji kierowcy przy kompletowaniu zamówienia.</p>
                </div>
                <div class="grid grid-cols-3 gap-4 mt-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">PLU Code</label>
                        <input type="text" id="item-plu-code" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-blue-500 focus:outline-none transition font-mono" placeholder="np. 12345">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Kolejność</label>
                        <input type="number" id="item-display-order" min="0" value="0" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-blue-500 focus:outline-none transition text-center">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Stan Mag. <span class="text-slate-600">(-1=∞)</span></label>
                        <input type="number" id="item-stock-count" value="-1" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-blue-500 focus:outline-none transition text-center">
                    </div>
                </div>
            `)}

            ${this._glassCard('sec-schedule', 'emerald', 'fa-calendar-days', 'Harmonogram i Dostępność', `
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Ważne od</label>
                        <input type="datetime-local" id="item-valid-from" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-emerald-500 focus:outline-none transition">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Ważne do</label>
                        <input type="datetime-local" id="item-valid-to" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-emerald-500 focus:outline-none transition">
                    </div>
                </div>
                <div class="mt-4">
                    <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider block mb-2">Dni Serwowania</label>
                    <div class="flex gap-2" id="available-days-container">${daysCheckboxes}</div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Dostępne od godziny</label>
                        <input type="time" id="item-available-start" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-emerald-500 focus:outline-none transition">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Dostępne do godziny</label>
                        <input type="time" id="item-available-end" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-emerald-500 focus:outline-none transition">
                    </div>
                </div>
            `)}

            ${this._glassCard('sec-marketing', 'rose', 'fa-bullhorn', 'E-commerce i Marketing', `
                <div id="lock-item-description-wrapper" class="flex flex-col gap-1.5 franchise-lockable">
                    <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Opis Dania (Menu / Kiosk)</label>
                    <textarea id="item-description" rows="3" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-rose-500 focus:outline-none transition resize-none" placeholder="Opis widoczny na menu/kiosku"></textarea>
                </div>
                <div class="flex flex-col gap-1.5 mt-4">
                    <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Odznaka (Badge)</label>
                    <select id="item-badge-type" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-rose-500 focus:outline-none transition cursor-pointer">
                        <option value="none">Brak</option><option value="new">🆕 NOWOŚĆ</option><option value="promo">🔥 PROMO</option><option value="bestseller">⭐ BESTSELLER</option><option value="hot">🌶️ HOT</option>
                    </select>
                </div>
                <div id="lock-item-tags-wrapper" class="flex flex-col gap-1.5 mt-4 franchise-lockable">
                    <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Tagi Marketingowe</label>
                    <input type="text" id="item-marketing-tags" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-rose-500 focus:outline-none transition font-mono" placeholder="wege,ostre,bezglutenowe">
                </div>

                <!-- M022: Legacy URL schowany pod details — integracje zewnętrzne mogą nadal pisać -->
                <details class="mt-4 bg-black/30 rounded-xl border border-white/5 overflow-hidden">
                    <summary class="cursor-pointer select-none text-[9px] text-slate-500 font-bold uppercase tracking-widest p-3 hover:bg-white/5 transition">
                        <i class="fa-solid fa-gear text-[8px] mr-1 opacity-40"></i>
                        Opcje zaawansowane — integracje zewnętrzne
                    </summary>
                    <div id="lock-item-image-url-wrapper" class="flex flex-col gap-1.5 franchise-lockable p-4 pt-1">
                        <label class="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Legacy URL zdjęcia <span class="text-slate-700">(dla integracji, które nie używają Scene Studio)</span></label>
                        <input type="text" id="item-image-url" class="bg-black/50 border border-white/10 text-white rounded-xl p-3 text-sm focus:border-rose-500 focus:outline-none transition font-mono" placeholder="https://cdn.../pizza.jpg">
                        <p class="text-slate-700 text-[8px] leading-relaxed mt-1">
                            <i class="fa-solid fa-circle-info text-[7px] mr-1 opacity-40"></i>
                            Uzupełnij tylko gdy zewnętrzny system (integracja POS/dostawy/kurier) oczekuje URL. Scene Studio i tak nadpisze to hero automatycznie.
                        </p>
                    </div>
                </details>
            `)}

            ${this._glassCard('sec-enterprise', 'emerald', 'fa-bolt', 'Enterprise Settings (Retail & Warianty)', `
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Kod Kreskowy (EAN)</label>
                        <div class="relative">
                            <i class="fa-solid fa-barcode absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                            <input type="text" id="item-barcode-ean" class="w-full bg-black/50 border border-white/10 text-emerald-300 rounded-xl p-3 pl-10 text-sm focus:border-emerald-500 focus:outline-none transition" placeholder="590123456789">
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider">Parent SKU</label>
                        <div class="relative">
                            <i class="fa-solid fa-link absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                            <input type="text" id="item-parent-sku" class="w-full bg-black/50 border border-white/10 text-cyan-300 rounded-xl p-3 pl-10 text-sm focus:border-cyan-500 focus:outline-none transition" placeholder="PIZZA_MASTER">
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <label class="text-[9px] text-slate-400 font-bold uppercase tracking-wider flex items-center justify-between mb-2">
                        <span>Alergeny (UE)</span>
                        <span class="text-[8px] bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded">Etykieta Informacyjna</span>
                    </label>
                    <div class="flex flex-wrap gap-2" id="allergens-container">${allergensHtml}</div>
                </div>
            `)}
        `;

        this._bindAllEvents();
        this._setupScrollSpy();
        form.dataset.omnichannelReady = '1';
    },

    _renderModifierGroupCheckboxes() {
        const groups = window.StudioState?.modifierGroups || [];
        if (!groups.length) return '<div class="text-slate-500 text-[9px] font-bold uppercase col-span-full">Brak grup modyfikatorów w systemie.</div>';
        return groups.map(g =>
            `<label class="flex items-center gap-3 cursor-pointer group hover:bg-white/5 p-2.5 rounded-xl transition border border-transparent hover:border-white/10">
                <input type="checkbox" class="modifier-group-checkbox w-4 h-4 rounded border-white/10 bg-black/50 text-purple-500 focus:ring-purple-500 cursor-pointer" value="${g.id}" id="modGroup_${g.id}">
                <span class="text-[10px] text-white font-bold group-hover:text-purple-300 transition">${g.name}</span>
            </label>`
        ).join('');
    },

    _bindAllEvents() {
        const nameInput = document.getElementById('item-name');
        const asciiInput = document.getElementById('item-ascii-key');
        if (nameInput && asciiInput) {
            nameInput.addEventListener('input', () => {
                this.debounce('name-slug', () => {
                    const currentId = document.getElementById('item-id')?.value || '0';
                    if (currentId === '0' || currentId === '') {
                        asciiInput.value = this.toAutoSlug(nameInput.value);
                    }
                }, 300);
            });
        }

        const catSelect = document.getElementById('item-category-id');
        if (catSelect) {
            catSelect.addEventListener('change', () => {
                const catId = parseInt(catSelect.value, 10);
                const cat = (window.StudioState?.categories || []).find(c => c.id === catId);
                if (!cat) return;
                if (this._vatInheritEnabled) {
                    const vatDI = document.getElementById('item-vat-dine-in');
                    const vatTA = document.getElementById('item-vat-takeaway');
                    if (vatDI) vatDI.value = cat.defaultVatDineIn ?? 8;
                    if (vatTA) vatTA.value = cat.defaultVatTakeaway ?? 5;
                }
                const pg = document.getElementById('item-printer-group');
                if (pg) {
                    const n = (cat.name || '').toLowerCase();
                    pg.value = (n.includes('napoj') || n.includes('drink') || n.includes('piwo') || n.includes('koktajl')) ? 'BAR' : 'KITCHEN_1';
                }
            });
        }

        const vatCb = document.getElementById('item-vat-inherit');
        if (vatCb) {
            vatCb.addEventListener('change', () => {
                this.toggleVatInherit(vatCb.checked);
                if (vatCb.checked) catSelect?.dispatchEvent(new Event('change'));
            });
        }

        const validFrom = document.getElementById('item-valid-from');
        const validTo = document.getElementById('item-valid-to');
        if (validFrom && validTo) {
            validFrom.addEventListener('change', () => {
                if (validFrom.value) {
                    validTo.min = validFrom.value;
                    if (validTo.value && validTo.value < validFrom.value) validTo.value = validFrom.value;
                } else {
                    validTo.min = '';
                }
            });
        }

        ['item-description', 'item-marketing-tags'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => this.debounce(id, () => {}, 500));
        });
    },

    applyFranchiseShield(isLockedByHq) {
        const lockTargets = [
            { wrapperId: 'lock-item-name-wrapper', inputId: 'item-name' },
            { wrapperId: 'lock-item-description-wrapper', inputId: 'item-description' },
            { wrapperId: 'lock-item-image-url-wrapper', inputId: 'item-image-url' },
            { wrapperId: 'lock-item-ascii-wrapper', inputId: 'item-ascii-key' },
            { wrapperId: 'lock-item-kds-wrapper', inputId: 'item-kds-station-id' },
            { wrapperId: 'lock-item-tags-wrapper', inputId: 'item-marketing-tags' }
        ];
        lockTargets.forEach(target => {
            const wrapper = document.getElementById(target.wrapperId);
            const input = document.getElementById(target.inputId);
            if (!wrapper || !input) return;
            if (isLockedByHq) {
                wrapper.classList.add('pointer-events-none', 'opacity-50');
                input.disabled = true;
            } else {
                wrapper.classList.remove('pointer-events-none', 'opacity-50');
                input.disabled = false;
            }
        });
        const hqFlag = document.getElementById('hq-lock-flag');
        if (hqFlag) hqFlag.classList.toggle('hidden', !isLockedByHq);
        if (hqFlag && isLockedByHq) hqFlag.classList.add('flex');
    },

    loadItemDataToForm(itemData) {
        this.ensureOmnichannelForm();

        const $ = id => document.getElementById(id);
        const categories = window.StudioState?.categories || [];

        const catSelect = $('item-category-id');
        if (catSelect && catSelect.options.length <= 1) {
            categories.forEach(cat => catSelect.add(new Option(cat.name, cat.id)));
        }

        const matrix = itemData.priceMatrix || {};

        $('item-id').value = itemData.id || 0;
        if ($('item-name')) $('item-name').value = itemData.name || '';

        const asciiField = $('item-ascii-key');
        if (asciiField) {
            asciiField.value = itemData.asciiKey || '';
            asciiField.disabled = itemData.id > 0;
            asciiField.classList.toggle('cursor-not-allowed', itemData.id > 0);
            asciiField.classList.toggle('opacity-50', itemData.id > 0);
        }

        if (catSelect) catSelect.value = itemData.categoryId || 0;

        this.setItemType(itemData.type || 'standard');
        this.setPubStatus(itemData.publicationStatus || (itemData.isActive ? 'Live' : 'Draft'));

        const secretCb = $('item-is-secret');
        if (secretCb) secretCb.checked = !!itemData.isSecret;

        if ($('item-vat-dine-in')) $('item-vat-dine-in').value = itemData.vatRateDineIn ?? 8;
        if ($('item-vat-takeaway')) $('item-vat-takeaway').value = itemData.vatRateTakeaway ?? 5;

        const vatCb = $('item-vat-inherit');
        const cat = categories.find(c => c.id == (itemData.categoryId || 0));
        if (vatCb && cat) {
            const inherited = (parseFloat(itemData.vatRateDineIn) === parseFloat(cat.defaultVatDineIn ?? 8))
                           && (parseFloat(itemData.vatRateTakeaway) === parseFloat(cat.defaultVatTakeaway ?? 5));
            vatCb.checked = inherited;
            this.toggleVatInherit(inherited);
        }

        if ($('item-price-pos')) $('item-price-pos').value = matrix.POS !== undefined ? parseFloat(matrix.POS).toFixed(2) : (itemData.price || '0.00');
        if ($('item-price-takeaway')) $('item-price-takeaway').value = matrix.Takeaway !== undefined ? parseFloat(matrix.Takeaway).toFixed(2) : (itemData.priceTakeaway || '0.00');
        if ($('item-price-delivery')) $('item-price-delivery').value = matrix.Delivery !== undefined ? parseFloat(matrix.Delivery).toFixed(2) : (itemData.priceDelivery || '0.00');

        if ($('item-printer-group')) $('item-printer-group').value = itemData.printerGroup || 'KITCHEN_1';
        if ($('item-kds-station-id')) $('item-kds-station-id').value = itemData.kdsStationId || 'NONE';
        if ($('item-driver-action-type')) $('item-driver-action-type').value = itemData.driverActionType || 'none';
        if ($('item-plu-code')) $('item-plu-code').value = itemData.pluCode || '';
        if ($('item-display-order')) $('item-display-order').value = itemData.displayOrder ?? 0;
        if ($('item-stock-count')) $('item-stock-count').value = itemData.stockCount ?? -1;

        if ($('item-valid-from')) $('item-valid-from').value = itemData.validFrom || '';
        if ($('item-valid-to')) $('item-valid-to').value = itemData.validTo || '';
        if ($('item-available-start')) $('item-available-start').value = itemData.availableStart || '';
        if ($('item-available-end')) $('item-available-end').value = itemData.availableEnd || '';

        // F-S1 — variant scale (parent only)
        this._currentParentItemId    = itemData.parentItemId || null;
        this._currentVariantOptionId = itemData.variantOptionId || null;
        this._currentIsVariantParent = !!itemData.isVariantParent;
        this._currentVariantScaleId  = itemData.variantScaleId || null;
        this._loadVariantScalesIntoSelect(itemData.variantScaleId || null);
        this._renderVariantChildrenPreview(itemData.id || 0);
        const genBtn = $('btn-generate-variant-family');
        if (genBtn) genBtn.disabled = !(itemData.id > 0 && (itemData.variantScaleId || itemData.isVariantParent));

        const days = (itemData.availableDays || '1,2,3,4,5,6,7').split(',').map(d => d.trim());
        document.querySelectorAll('.day-checkbox').forEach(cb => {
            cb.checked = days.includes(cb.value);
        });

        if ($('item-description')) $('item-description').value = itemData.description || '';
        if ($('item-image-url')) $('item-image-url').value = itemData.imageUrl || '';
        if ($('item-badge-type')) $('item-badge-type').value = itemData.badgeType || 'none';
        if ($('item-marketing-tags')) $('item-marketing-tags').value = itemData.marketingTags || '';

        // M022: composition profile + hero preview + scene badge
        this._applyCompositionProfile(itemData);
        this._applyHeroPreview(itemData);

        if ($('item-barcode-ean')) $('item-barcode-ean').value = itemData.barcodeEan || '';
        if ($('item-parent-sku')) $('item-parent-sku').value = itemData.parentSku || '';

        document.querySelectorAll('.allergen-checkbox').forEach(cb => { cb.checked = false; });
        if (Array.isArray(itemData.allergens)) {
            itemData.allergens.forEach(alg => {
                const cb = document.querySelector(`.allergen-checkbox[value="${alg}"]`);
                if (cb) cb.checked = true;
            });
        }

        document.querySelectorAll('.modifier-group-checkbox').forEach(cb => { cb.checked = false; });
        if (Array.isArray(itemData.modifierGroupIds)) {
            itemData.modifierGroupIds.forEach(gid => {
                const cb = document.getElementById(`modGroup_${gid}`);
                if (cb) cb.checked = true;
            });
        }

        this.applyFranchiseShield(!!itemData.isLockedByHq);

        const directorBtn = document.getElementById('btn-open-visual-director');
        if (directorBtn) {
            if (itemData.id > 0 && itemData.asciiKey) {
                directorBtn.href = `../online_studio/index.html?tab=director&item=${encodeURIComponent(itemData.asciiKey)}`;
                directorBtn.classList.remove('opacity-50', 'pointer-events-none');
            } else {
                directorBtn.href = '../online_studio/index.html';
            }
        }
    },

    // ═══════════════════════════════════════════════════════════════════════
    // M022: Composition Profile + Hero Preview (Scene Studio integration)
    // ═══════════════════════════════════════════════════════════════════════

    _applyCompositionProfile(itemData) {
        const sel = document.getElementById('item-composition-profile');
        if (!sel) return;

        const templates = (window.StudioState?.sceneTemplates || []).filter(t => t.kind === 'item');
        if (templates.length > 0) {
            sel.innerHTML = templates.map(t =>
                `<option value="${this._esc(t.asciiKey)}">${this._esc(t.name)}</option>`
            ).join('');
        }
        // Determine target value: item-level > category default > 'static_hero'
        let target = (itemData && itemData.compositionProfile) || null;
        if (!target) {
            const catId = parseInt(itemData?.categoryId ?? document.getElementById('item-category-id')?.value, 10);
            const cat = (window.StudioState?.categories || []).find(c => c.id === catId);
            target = cat?.defaultCompositionProfile || 'static_hero';
        }
        // Fallback — jeśli target nie jest w dropdown-ie, ustaw static_hero
        const options = Array.from(sel.options).map(o => o.value);
        if (!options.includes(target)) target = options.includes('static_hero') ? 'static_hero' : options[0];
        sel.value = target;

        this._updateCompositionHint(sel.value);

        if (!sel.dataset.bound) {
            sel.addEventListener('change', () => this._updateCompositionHint(sel.value));
            sel.dataset.bound = '1';
        }
    },

    _updateCompositionHint(profile) {
        const hint = document.getElementById('item-composition-hint');
        if (!hint) return;
        const map = {
            'pizza_top_down':                   'Warstwy (spód, sos, ser, dodatki) renderowane z góry w Scene Studio.',
            'static_hero':                      'Danie renderowane z jednego zdjęcia (burger, makaron, napoje).',
            'pasta_bowl_placeholder':           'Placeholder — pełna scena w Fazie 2.',
            'beverage_bottle_placeholder':      'Placeholder — pełna scena w Fazie 2.',
            'burger_three_quarter_placeholder': 'Placeholder — pełna scena w Fazie 2.',
            'sushi_top_down_placeholder':       'Placeholder — pełna scena w Fazie 2.',
        };
        hint.textContent = map[profile] || 'Profil kompozycji dla Scene Studio.';
    },

    _applyHeroPreview(itemData) {
        const img      = document.getElementById('item-hero-preview-img');
        const ph       = document.getElementById('item-hero-preview-placeholder');
        const badge    = document.getElementById('item-hero-scene-badge');
        const unlinkBtn= document.getElementById('btn-item-hero-unlink');
        const pickLabel= document.getElementById('btn-item-hero-pick-label');
        if (!img || !ph || !badge) return;

        const url = (itemData && itemData.imageUrl) ? itemData.imageUrl : '';
        if (url) {
            img.src = url;
            img.classList.remove('hidden');
            ph.classList.add('hidden');
            if (unlinkBtn) unlinkBtn.classList.remove('hidden');
            if (pickLabel) pickLabel.textContent = 'Zmień Hero';
        } else {
            img.src = '';
            img.classList.add('hidden');
            ph.classList.remove('hidden');
            if (unlinkBtn) unlinkBtn.classList.add('hidden');
            if (pickLabel) pickLabel.textContent = 'Przypisz Hero';
        }

        const hasScene = !!(itemData && itemData.hasScene);
        badge.classList.remove('hidden', 'border-emerald-500/40', 'text-emerald-300', 'border-slate-600', 'text-slate-500');
        if (hasScene) {
            badge.textContent = 'Scena: TAK';
            badge.classList.add('border-emerald-500/40', 'text-emerald-300');
        } else {
            badge.textContent = 'Scena: BRAK';
            badge.classList.add('border-slate-600', 'text-slate-500');
        }
    },

    _esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    },

    async saveItem() {
        this.ensureOmnichannelForm();

        const $ = id => document.getElementById(id);
        const val = id => $(`${id}`)?.value?.trim() ?? '';

        const itemId = parseInt(val('item-id'), 10) || 0;
        const name = val('item-name');
        const rawAscii = val('item-ascii-key');
        const cleanKey = rawAscii.replace(/[^a-zA-Z0-9_-]/g, '').toUpperCase();
        const categoryId = parseInt(val('item-category-id'), 10) || 0;

        if (!name || !cleanKey || categoryId <= 0) {
            alert('Wypełnij poprawnie: Nazwa, SKU i Kategoria.');
            return;
        }

        const pricePos = parseFloat(val('item-price-pos')) || 0;
        const priceTakeaway = parseFloat(val('item-price-takeaway')) || 0;
        const priceDelivery = parseFloat(val('item-price-delivery')) || 0;

        if (typeof window.SliceValidator !== 'undefined') {
            const labels = ['POS', 'Takeaway', 'Delivery'];
            for (let i = 0; i < [pricePos, priceTakeaway, priceDelivery].length; i++) {
                if (window.SliceValidator.validatePrice([pricePos, priceTakeaway, priceDelivery][i]) === null) {
                    alert(`Błąd walidacji ceny kanału ${labels[i]}.`);
                    return;
                }
            }
        }

        const availableDays = Array.from(document.querySelectorAll('.day-checkbox:checked')).map(cb => cb.value).join(',');

        const payload = {
            action: itemId > 0 ? 'update_item_full' : 'add_item',
            itemId,
            name,
            asciiKey: cleanKey,
            categoryId,
            type: this._currentItemType,
            publicationStatus: this._currentPubStatus,
            isSecret: $('item-is-secret')?.checked ? 1 : 0,
            priceTiers: [
                { channel: 'POS', price: pricePos },
                { channel: 'Takeaway', price: priceTakeaway },
                { channel: 'Delivery', price: priceDelivery }
            ],
            vatRateDineIn: parseFloat(val('item-vat-dine-in')) || 8,
            vatRateTakeaway: parseFloat(val('item-vat-takeaway')) || 5,
            printerGroup: val('item-printer-group') || 'KITCHEN_1',
            kdsStationId: val('item-kds-station-id') || 'NONE',
            driverActionType: val('item-driver-action-type') || 'none',
            pluCode: val('item-plu-code'),
            displayOrder: parseInt(val('item-display-order'), 10) || 0,
            stockCount: parseInt(val('item-stock-count'), 10),
            validFrom: val('item-valid-from') || null,
            validTo: val('item-valid-to') || null,
            availableDays: availableDays || '1,2,3,4,5,6,7',
            availableStart: val('item-available-start') || null,
            availableEnd: val('item-available-end') || null,
            description: val('item-description'),
            imageUrl: val('item-image-url'),
            compositionProfile: val('item-composition-profile') || 'static_hero',
            badgeType: val('item-badge-type') || 'none',
            marketingTags: val('item-marketing-tags'),
            barcodeEan: val('item-barcode-ean') || null,
            parentSku: val('item-parent-sku') || null,
            allergens: Array.from(document.querySelectorAll('.allergen-checkbox:checked')).map(cb => cb.value),
            modifierGroupIds: Array.from(document.querySelectorAll('.modifier-group-checkbox:checked'))
                .map(cb => parseInt(cb.value, 10)).filter(Number.isInteger),
            // F-S1 — variant scale fields
            variantScaleId: parseInt(val('item-variant-scale'), 10) || null,
            isVariantParent: (parseInt(val('item-variant-scale'), 10) > 0) ? 1 : 0
        };

        const btn = $('btn-save-item');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> ZAPISYWANIE...'; }

        try {
            const result = await window.StudioApi.postPayload(payload);
            if (result.success === true) {
                if (btn) { btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> ZAPISANO!'; btn.classList.replace('from-blue-600', 'from-green-600'); btn.classList.replace('to-blue-500', 'to-green-500'); }
                setTimeout(() => {
                    if (btn) { btn.innerHTML = '<i class="fa-solid fa-rocket mr-2"></i> ZAPISZ DANIE'; btn.disabled = false; btn.classList.replace('from-green-600', 'from-blue-600'); btn.classList.replace('to-green-500', 'to-blue-500'); }
                }, 2000);
                if (typeof window.loadMenuTree === 'function') await window.loadMenuTree();
                if (window.Core?.renderTree) window.Core.renderTree();
            } else {
                alert('Błąd zapisu: ' + result.message);
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rocket mr-2"></i> ZAPISZ DANIE'; }
            }
        } catch (error) {
            alert('Krytyczny błąd sieci.');
            console.error('[ItemEditor] API error:', error);
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rocket mr-2"></i> ZAPISZ DANIE'; }
        }
    },

    // ═══════════════════════════════════════════════════════════════════════
    // M1 · Menu Studio Polish — Przypisanie hero dania z biblioteki assetów
    // Picker otwiera modal z gridem kafelków, preferuje roleHint='hero', po
    // wyborze woła set_item_hero (unlink starego + link nowego).
    // ═══════════════════════════════════════════════════════════════════════
    async openItemHeroPicker() {
        const asciiKey = (document.getElementById('item-ascii-key')?.value || '').trim();
        if (!asciiKey) {
            alert('Najpierw zapisz danie (SKU jest wymagany).');
            return;
        }

        if (window.ModifierInspector && typeof window.ModifierInspector.loadCompactAssets === 'function') {
            await window.ModifierInspector.loadCompactAssets();
        }
        const assets = (window.StudioState && window.StudioState.compactAssets) || [];

        const sorted = assets.slice().sort((a, b) => {
            const aHero = String(a.roleHint || '').toLowerCase() === 'hero' ? 0 : 1;
            const bHero = String(b.roleHint || '').toLowerCase() === 'hero' ? 0 : 1;
            if (aHero !== bHero) return aHero - bHero;
            const aCat = String(a.category || '').toLowerCase() === 'hero' ? 0 : 1;
            const bCat = String(b.category || '').toLowerCase() === 'hero' ? 0 : 1;
            if (aCat !== bCat) return aCat - bCat;
            return (a.asciiKey || '').localeCompare(b.asciiKey || '');
        });
        const categories = Array.from(new Set(sorted.map(a => a.category).filter(Boolean))).sort();

        const host = document.getElementById('sh-item-hero-picker');
        if (host) host.remove();
        const modal = document.createElement('div');
        modal.id = 'sh-item-hero-picker';
        modal.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/70 backdrop-blur-sm p-4';
        modal.innerHTML = `
            <div class="bg-[#0a0a0f] border border-white/10 rounded-3xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden">
                <header class="flex items-center justify-between px-6 py-4 border-b border-white/5 shrink-0">
                    <div>
                        <div class="text-[9px] font-black uppercase text-amber-400 tracking-widest">Przypisz Hero</div>
                        <div class="text-[14px] font-black text-white">${this._esc(asciiKey)} · wybierz zdjęcie dania z biblioteki</div>
                    </div>
                    <button type="button" class="picker-close text-slate-500 hover:text-white w-9 h-9 rounded-lg hover:bg-white/5 transition flex items-center justify-center">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </header>
                <div class="px-6 py-4 border-b border-white/5 flex flex-wrap gap-3 items-center shrink-0">
                    <div class="flex-1 min-w-[220px] relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-600 text-[11px]"></i>
                        <input type="text" class="picker-search w-full bg-black/50 border border-white/10 rounded-xl pl-9 pr-3 py-2.5 text-white text-[11px] outline-none focus:border-amber-500 transition" placeholder="Szukaj po nazwie, kategorii lub SKU...">
                    </div>
                    <select class="picker-cat bg-black/50 border border-white/10 rounded-xl px-3 py-2.5 text-white text-[11px] outline-none focus:border-amber-500 transition">
                        <option value="">Wszystkie kategorie</option>
                        ${categories.map(c => `<option value="${this._esc(c)}">${this._esc(String(c).toUpperCase())}</option>`).join('')}
                    </select>
                    <label class="flex items-center gap-2 text-[10px] font-black uppercase text-slate-400 cursor-pointer">
                        <input type="checkbox" class="picker-only-hero w-4 h-4 rounded border-white/10 bg-black/50" checked>
                        Tylko rola "hero"
                    </label>
                </div>
                <div class="picker-grid flex-1 overflow-y-auto p-6 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3"></div>
                <footer class="px-6 py-3 border-t border-white/5 text-[9px] font-black uppercase text-slate-600 tracking-wider shrink-0 flex items-center justify-between gap-3">
                    <span><span class="picker-count">0</span> assetów · kliknij aby przypisać</span>
                    <a href="../online_studio/index.html" target="_blank" rel="noopener" class="text-amber-500 hover:text-amber-300 transition">
                        <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Dodaj nowy w Asset Studio
                    </a>
                </footer>
            </div>
        `;
        document.body.appendChild(modal);

        const gridEl = modal.querySelector('.picker-grid');
        const searchEl = modal.querySelector('.picker-search');
        const catEl = modal.querySelector('.picker-cat');
        const onlyHeroEl = modal.querySelector('.picker-only-hero');
        const countEl = modal.querySelector('.picker-count');

        const renderGrid = () => {
            const q = (searchEl.value || '').trim().toLowerCase();
            const cat = catEl.value || '';
            const onlyHero = !!onlyHeroEl.checked;
            const filtered = sorted.filter(a => {
                if (cat && a.category !== cat) return false;
                if (q) {
                    const hay = `${a.asciiKey || ''} ${a.category || ''} ${a.subType || ''} ${a.roleHint || ''}`.toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                if (onlyHero) {
                    const hint = String(a.roleHint || '').toLowerCase();
                    const category = String(a.category || '').toLowerCase();
                    if (hint !== 'hero' && category !== 'hero') return false;
                }
                return true;
            });
            countEl.textContent = String(filtered.length);
            if (filtered.length === 0) {
                gridEl.innerHTML = `
                    <div class="col-span-full text-center py-16 text-slate-600 text-[10px] uppercase font-black tracking-widest">
                        <i class="fa-solid fa-inbox text-3xl mb-3 opacity-40"></i>
                        <div>Brak pasujących assetów.</div>
                        <div class="text-slate-700 mt-2 text-[9px]">Odznacz „Tylko rola hero" lub dodaj nowy w Asset Studio.</div>
                    </div>
                `;
                return;
            }
            gridEl.innerHTML = filtered.map(a => {
                const thumb = a.previewUrl
                    ? `<img src="${this._esc(a.previewUrl)}" alt="" class="w-full h-full object-cover" loading="lazy">`
                    : `<div class="w-full h-full flex items-center justify-center"><i class="fa-solid fa-image text-slate-700 text-2xl"></i></div>`;
                const hint = String(a.roleHint || '').toLowerCase();
                return `
                    <button type="button" class="picker-card group relative bg-black/40 border border-white/5 rounded-2xl overflow-hidden hover:border-amber-500/70 transition flex flex-col text-left" data-asset-id="${parseInt(a.id, 10)}">
                        <div class="aspect-square bg-black/60 overflow-hidden">${thumb}</div>
                        <div class="p-2.5 flex-1">
                            <div class="text-[10px] font-black text-white truncate">${this._esc(a.asciiKey || '—')}</div>
                            <div class="text-[8px] text-slate-500 uppercase truncate mt-0.5">${this._esc(String(a.category || '').toUpperCase())}${a.subType ? ' · ' + this._esc(a.subType) : ''}</div>
                            <div class="text-[7px] ${hint === 'hero' ? 'text-amber-400' : 'text-slate-600'} uppercase truncate mt-1 tracking-widest">${this._esc(hint || '—')}</div>
                        </div>
                    </button>
                `;
            }).join('');
        };

        const close = () => modal.remove();
        modal.addEventListener('click', (e) => {
            if (e.target === modal) close();
            if (e.target.closest('.picker-close')) { close(); return; }
            const card = e.target.closest('.picker-card');
            if (card) {
                const aid = parseInt(card.dataset.assetId, 10) || 0;
                if (aid > 0) this.linkItemHero(asciiKey, aid, close);
            }
        });
        searchEl.addEventListener('input', renderGrid);
        catEl.addEventListener('change', renderGrid);
        onlyHeroEl.addEventListener('change', renderGrid);
        document.addEventListener('keydown', function escH(ev) {
            if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', escH); }
        });

        renderGrid();
        setTimeout(() => searchEl.focus(), 50);
    },

    async linkItemHero(itemSku, assetId, onDone) {
        try {
            const r = await window.apiStudio('set_item_hero', {
                itemSku: itemSku,
                assetId: assetId,
            });
            if (r && r.success) {
                this._applyHeroPreview({ imageUrl: r.data?.imageUrl || '', hasScene: document.getElementById('item-hero-scene-badge')?.textContent === 'Scena: TAK' });
                const resultBox = document.getElementById('autogen-result');
                if (resultBox) resultBox.classList.add('hidden');
                if (typeof onDone === 'function') onDone();
                if (typeof window.loadMenuTree === 'function') {
                    window.loadMenuTree().catch(e => console.warn('[ItemEditor] tree refresh failed:', e));
                }
            } else {
                alert('Nie udało się przypisać hero: ' + (r?.message || 'nieznany błąd'));
            }
        } catch (e) {
            console.error('[ItemEditor] linkItemHero error:', e);
            alert('Błąd sieci podczas przypisywania hero — patrz konsola.');
        }
    },

    async unlinkItemHero() {
        const asciiKey = (document.getElementById('item-ascii-key')?.value || '').trim();
        if (!asciiKey) return;
        if (!confirm('Odłączyć hero od tego dania? (Asset pozostanie w bibliotece.)')) return;
        try {
            const r = await window.apiStudio('unlink_item_hero', {
                itemSku: asciiKey,
            });
            if (r && r.success) {
                this._applyHeroPreview({ imageUrl: '', hasScene: false });
                if (typeof window.loadMenuTree === 'function') {
                    window.loadMenuTree().catch(e => console.warn('[ItemEditor] tree refresh failed:', e));
                }
            } else {
                alert('Nie udało się odłączyć: ' + (r?.message || 'nieznany błąd'));
            }
        } catch (e) {
            console.error('[ItemEditor] unlinkItemHero error:', e);
            alert('Błąd sieci — patrz konsola.');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════
    // M1 · Menu Studio Polish — Auto-generator default composition
    // Składa scenę z hero dania + modyfikatorów NONE/default z layer_top_down.
    // Endpoint: autogenerate_scene. Resp. reason='scene_exists' → prompt "nadpisać?"
    // ═══════════════════════════════════════════════════════════════════════
    async autogenerateScene(force = false) {
        const btn = document.getElementById('btn-autogenerate-scene');
        const resultBox = document.getElementById('autogen-result');
        const asciiKey = (document.getElementById('item-ascii-key')?.value || '').trim();

        const showResult = (kind, html) => {
            if (!resultBox) return;
            const tones = {
                ok:    'bg-green-900/20 border-green-500/40 text-green-300',
                warn:  'bg-yellow-900/20 border-yellow-500/40 text-yellow-200',
                err:   'bg-red-900/20 border-red-500/40 text-red-300',
                info:  'bg-slate-800/40 border-white/10 text-slate-300',
            };
            resultBox.className = `mt-2 p-3 rounded-xl border text-[10px] font-bold leading-relaxed ${tones[kind] || tones.info}`;
            resultBox.innerHTML = html;
            resultBox.classList.remove('hidden');
        };

        if (!asciiKey) {
            showResult('warn', '<i class="fa-solid fa-triangle-exclamation mr-1"></i> Najpierw zapisz danie (SKU jest wymagany do auto-generacji).');
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Generuję...</span>';
        }
        if (resultBox) resultBox.classList.add('hidden');

        try {
            const payload = { action: 'autogenerate_scene', itemSku: asciiKey };
            if (force) payload.force = true;
            const result = await window.StudioApi.postPayload(payload);

            if (result.success === true) {
                const d = result.data || {};
                showResult('ok',
                    `<i class="fa-solid fa-wand-magic-sparkles mr-1"></i> <strong>${result.message}</strong>` +
                    `<div class="mt-1 text-[9px] uppercase tracking-widest opacity-70">` +
                    `sceneId: ${d.sceneId} · warstw: ${d.layerCount} · modyfikatorów: ${d.modifierCount}` +
                    (d.overwritten ? ' · nadpisano' : ' · nowa scena') +
                    `</div>` +
                    `<div class="mt-2 text-[9px]">Otwórz <a href="../online_studio/index.html?tab=director&item=${encodeURIComponent(asciiKey)}" target="_blank" rel="noopener" class="text-amber-400 hover:text-amber-300 underline font-black uppercase">Scene Studio →</a>, aby dostroić layout.</div>`
                );
                const profileSel = document.getElementById('item-composition-profile');
                if (profileSel && profileSel.value !== 'pizza_top_down') {
                    const optPizza = Array.from(profileSel.options).find(o => o.value === 'pizza_top_down' || /pizza/i.test(o.value));
                    if (optPizza) {
                        profileSel.value = optPizza.value;
                        profileSel.dispatchEvent(new Event('change'));
                    }
                }
            } else {
                const d = result.data || {};
                if (d.reason === 'scene_exists' && !force) {
                    const ok = confirm(
                        `Scena już istnieje (${d.layerCount} warstw, v${d.version}).\n\n` +
                        `Czy na pewno chcesz ją NADPISAĆ auto-wygenerowaną kompozycją?\n` +
                        `(Historyczna wersja zostanie zapisana w sh_atelier_scene_history.)`
                    );
                    if (ok) {
                        return this.autogenerateScene(true);
                    } else {
                        showResult('info', '<i class="fa-solid fa-circle-info mr-1"></i> Nadpisanie anulowane. Istniejąca scena pozostaje nietknięta.');
                    }
                } else if (d.reason === 'no_source_data' && Array.isArray(d.steps) && d.steps.length > 0) {
                    const escape = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                    const stepsList = d.steps.map(s =>
                        `<li class="flex items-start gap-2 mt-1"><i class="fa-solid fa-circle-arrow-right text-amber-400 mt-0.5 shrink-0"></i><span>${escape(s)}</span></li>`
                    ).join('');
                    const badges = [
                        `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded border text-[8px] uppercase tracking-widest ${d.hasHero ? 'border-green-500/40 bg-green-900/20 text-green-300' : 'border-red-500/40 bg-red-900/20 text-red-300'}"><i class="fa-solid ${d.hasHero ? 'fa-check' : 'fa-xmark'}"></i> Hero</span>`,
                        `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded border text-[8px] uppercase tracking-widest ${d.defaultModsCount > 0 ? 'border-green-500/40 bg-green-900/20 text-green-300' : 'border-red-500/40 bg-red-900/20 text-red-300'}"><i class="fa-solid ${d.defaultModsCount > 0 ? 'fa-check' : 'fa-xmark'}"></i> Domyślne mod.: ${d.defaultModsCount}</span>`,
                    ].join(' ');
                    showResult('warn',
                        `<div class="flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation text-amber-400"></i> <strong>Auto-generator potrzebuje materiału</strong></div>` +
                        `<div class="mt-2 flex flex-wrap gap-1.5">${badges}</div>` +
                        `<div class="mt-2 text-[10px] font-bold uppercase tracking-widest opacity-70">Co zrobić:</div>` +
                        `<ul class="mt-1 space-y-0.5">${stepsList}</ul>`
                    );
                } else {
                    showResult('err',
                        `<i class="fa-solid fa-circle-xmark mr-1"></i> <strong>Błąd auto-generacji</strong>` +
                        `<div class="mt-1 opacity-80">${result.message || 'Nieznany błąd.'}</div>`
                    );
                }
            }
        } catch (err) {
            console.error('[ItemEditor] autogenerateScene error:', err);
            showResult('err', '<i class="fa-solid fa-circle-xmark mr-1"></i> Krytyczny błąd sieci — sprawdź konsolę.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> <span>Wygeneruj automatycznie</span>';
            }
        }
    },

    // =========================================================================
    // F-S1 — VARIANT SCALES (Rozmiary / Warianty) · 2026-05-11
    // Reuzywalna skala (Mala/Srednia/Duza) z multiplier-em dla receptury.
    // Konstytucja v5 § Prawo II — jedna receptura, wiele rozmiarow.
    // =========================================================================

    async _loadVariantScalesIntoSelect(selectedId) {
        const sel = document.getElementById('item-variant-scale');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Brak (zwykła pozycja standalone) —</option>';
        try {
            const r = await window.apiStudio('list_variant_scales');
            const scales = (r && r.success && r.data && r.data.scales) ? r.data.scales : [];
            scales.forEach(s => {
                const optsCount = Array.isArray(s.options) ? s.options.length : 0;
                const opt = new Option(`${s.name}  ·  ${optsCount} opcji  (${s.key_ascii})`, s.id);
                sel.appendChild(opt);
            });
            if (selectedId) sel.value = String(selectedId);
        } catch (e) {
            console.warn('[ItemEditor] _loadVariantScalesIntoSelect failed', e);
        }
        sel.onchange = () => {
            const genBtn = document.getElementById('btn-generate-variant-family');
            const itemId = parseInt(document.getElementById('item-id')?.value || '0', 10);
            if (genBtn) genBtn.disabled = !(itemId > 0 && parseInt(sel.value, 10) > 0);
        };
    },

    async _renderVariantChildrenPreview(parentItemId) {
        const wrap = document.getElementById('variant-children-preview');
        const list = document.getElementById('variant-children-list');
        if (!wrap || !list) return;
        list.innerHTML = '';
        if (!parentItemId || parentItemId <= 0) { wrap.classList.add('hidden'); return; }
        try {
            // Czytamy children z aktualnego drzewa (lokalny stan), zamiast osobnego API call.
            const tree = window.StudioState?.menuTree || [];
            const children = [];
            tree.forEach(cat => (cat.items || []).forEach(it => {
                if (parseInt(it.parentItemId, 10) === parseInt(parentItemId, 10)) children.push(it);
            }));
            if (children.length === 0) { wrap.classList.add('hidden'); return; }
            wrap.classList.remove('hidden');
            list.innerHTML = children.map(c => `
                <div class="bg-black/30 border border-orange-500/20 rounded-lg p-2 flex flex-col gap-0.5">
                    <span class="text-[10px] text-orange-300 font-bold uppercase">${c.name || c.asciiKey}</span>
                    <span class="text-[8px] text-slate-500 font-mono">${c.asciiKey}</span>
                </div>
            `).join('');
        } catch (e) {
            console.warn('[ItemEditor] _renderVariantChildrenPreview failed', e);
        }
    },

    async generateVariantFamily() {
        const itemId = parseInt(document.getElementById('item-id')?.value || '0', 10);
        if (!itemId) { alert('Najpierw zapisz pozycję (parent), potem wygeneruj rodzinę.'); return; }
        const scaleId = parseInt(document.getElementById('item-variant-scale')?.value || '0', 10);
        if (!scaleId) { alert('Wybierz skalę rozmiarów.'); return; }

        if (!confirm('Wygenerować rodzinę wariantów na podstawie wybranej skali?\n\nKażdy wariant dostanie własne SKU (np. PIZZA_X_S, _M, _L) i własną cenę. Istniejące warianty z tym samym SKU zostaną pominięte.')) return;

        try {
            // Najpierw zapisz parent (żeby variant_scale_id było w bazie).
            await this.saveItem();
            // Następnie wygeneruj rodzinę.
            const r = await window.apiStudio('create_variant_family', {
                parent_item_id: itemId
            });
            if (r && r.success) {
                const created = (r.data && r.data.created) || [];
                const skipped = (r.data && r.data.skipped) || [];
                alert(`✅ Utworzono ${created.length} wariantów.\nPominięto (już istniały): ${skipped.length}.\n\nLista:\n${created.map(c => '• ' + c.ascii_key).join('\n')}`);
                if (typeof window.loadMenuTree === 'function') await window.loadMenuTree();
                if (window.Core?.renderTree) window.Core.renderTree();
                this._renderVariantChildrenPreview(itemId);
            } else {
                alert('❌ Błąd: ' + (r?.message || 'unknown'));
            }
        } catch (e) {
            console.error('[ItemEditor] generateVariantFamily', e);
            alert('Krytyczny błąd: ' + e.message);
        }
    },

    // =========================================================================
    // F-S6 — Wizard „Nowa Pizza" (4 kroki) · 2026-05-11
    // Step 1: Nazwa + kategoria
    // Step 2: Wybór skali rozmiarów (lub utworzenie nowej)
    // Step 3: Ceny bazowe per kanał × per opcja
    // Step 4: Generuj rodzinę + zapisz
    // =========================================================================

    async openNewPizzaWizard() {
        // Pobierz potrzebne dane.
        const [categoriesData, scalesRes] = await Promise.all([
            Promise.resolve(window.StudioState?.categories || []),
            window.apiStudio('list_variant_scales'),
        ]);
        const scales = scalesRes?.data?.scales || [];

        const modal = document.createElement('div');
        modal.id = 'fs6-new-pizza-wizard';
        modal.className = 'fixed inset-0 z-[300] bg-black/85 backdrop-blur-sm flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-slate-900 border border-orange-500/40 rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
                    <div>
                        <h3 class="text-white font-black text-base">🍕 Kreator Nowej Pizzy</h3>
                        <p class="text-orange-300 text-[10px] uppercase font-bold tracking-wider mt-0.5">F-S6 · 4 kroki do gotowej rodziny wariantów</p>
                    </div>
                    <button onclick="document.getElementById('fs6-new-pizza-wizard')?.remove()" class="text-slate-400 hover:text-white text-xl">×</button>
                </div>
                <div class="px-5 py-3 border-b border-white/5 flex items-center gap-2 text-[10px]">
                    <span class="fs6-step-pill px-2 py-1 rounded bg-orange-500/30 text-orange-200" data-step="1">1. Nazwa</span>
                    <i class="fa-solid fa-arrow-right text-slate-600 text-[8px]"></i>
                    <span class="fs6-step-pill px-2 py-1 rounded bg-slate-700/40 text-slate-400" data-step="2">2. Rozmiary</span>
                    <i class="fa-solid fa-arrow-right text-slate-600 text-[8px]"></i>
                    <span class="fs6-step-pill px-2 py-1 rounded bg-slate-700/40 text-slate-400" data-step="3">3. Ceny</span>
                    <i class="fa-solid fa-arrow-right text-slate-600 text-[8px]"></i>
                    <span class="fs6-step-pill px-2 py-1 rounded bg-slate-700/40 text-slate-400" data-step="4">4. Modyfikatory</span>
                    <i class="fa-solid fa-arrow-right text-slate-600 text-[8px]"></i>
                    <span class="fs6-step-pill px-2 py-1 rounded bg-slate-700/40 text-slate-400" data-step="5">5. Generuj</span>
                </div>
                <div class="overflow-y-auto flex-1 p-5 space-y-4">
                    <!-- STEP 1 -->
                    <div class="fs6-step-panel" data-step-panel="1">
                        <h4 class="text-white font-bold text-sm mb-3">Krok 1: Nazwa pizzy</h4>
                        <div class="space-y-3">
                            <div>
                                <label class="text-[9px] text-slate-400 uppercase font-bold">Nazwa <span class="text-red-400">*</span></label>
                                <input type="text" id="fs6-name" class="w-full bg-black/50 border border-white/10 text-white rounded-lg p-3 text-sm mt-1" placeholder="np. Pizza Margherita">
                            </div>
                            <div>
                                <label class="text-[9px] text-slate-400 uppercase font-bold">Klucz SKU (autogenerowany)</label>
                                <input type="text" id="fs6-ascii" class="w-full bg-black/50 border border-white/10 text-orange-300 rounded-lg p-3 text-sm font-mono uppercase mt-1" placeholder="PIZZA_MARGHERITA">
                            </div>
                            <div>
                                <label class="text-[9px] text-slate-400 uppercase font-bold">Kategoria <span class="text-red-400">*</span></label>
                                <select id="fs6-category" class="w-full bg-black/50 border border-white/10 text-white rounded-lg p-3 text-sm mt-1">
                                    <option value="0">— wybierz —</option>
                                    ${categoriesData.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
                                </select>
                            </div>
                            <div>
                                <label class="text-[9px] text-slate-400 uppercase font-bold">Opis (opcjonalnie)</label>
                                <textarea id="fs6-desc" rows="2" class="w-full bg-black/50 border border-white/10 text-white rounded-lg p-3 text-xs mt-1" placeholder="Klasyczna z mozzarellą i bazylią"></textarea>
                            </div>
                        </div>
                    </div>
                    <!-- STEP 2 -->
                    <div class="fs6-step-panel hidden" data-step-panel="2">
                        <h4 class="text-white font-bold text-sm mb-3">Krok 2: Skala rozmiarów</h4>
                        <div class="space-y-3">
                            <p class="text-[10px] text-slate-400">Wybierz istniejącą skalę lub kliknij „Zarządzaj" żeby dodać nową w osobnym modalu.</p>
                            <select id="fs6-scale" class="w-full bg-black/50 border border-white/10 text-white rounded-lg p-3 text-sm">
                                <option value="">— brak (zwykła pozycja standalone) —</option>
                                ${scales.map(s => `<option value="${s.id}" data-options='${JSON.stringify(s.options || [])}'>${s.name} (${(s.options || []).length} opcji)</option>`).join('')}
                            </select>
                            <button onclick="document.getElementById('fs6-new-pizza-wizard')?.remove(); window.ItemEditor.openVariantScaleManager();" class="text-[10px] text-orange-300 hover:text-orange-200 underline">+ Utwórz nową skalę (zarządzaj)</button>
                            <div id="fs6-scale-preview" class="mt-3 p-3 bg-black/30 rounded-xl border border-white/5 hidden">
                                <div class="text-[10px] text-orange-300 uppercase font-bold mb-2">Podgląd opcji:</div>
                                <div id="fs6-scale-preview-list" class="grid grid-cols-3 gap-2 text-center"></div>
                            </div>
                        </div>
                    </div>
                    <!-- STEP 3 -->
                    <div class="fs6-step-panel hidden" data-step-panel="3">
                        <h4 class="text-white font-bold text-sm mb-3">Krok 3: Ceny bazowe</h4>
                        <p class="text-[10px] text-slate-400 mb-3">Wpisz cenę POS za każdą opcję rozmiarową. Takeaway/Delivery zostaną auto-uzupełnione (+10% delivery).</p>
                        <div id="fs6-prices-matrix" class="space-y-2"></div>
                    </div>
                    <!-- STEP 4 — F-S6.1 modifier groups -->
                    <div class="fs6-step-panel hidden" data-step-panel="4">
                        <h4 class="text-white font-bold text-sm mb-3">Krok 4: Domyślne grupy modyfikatorów (F-S6.1)</h4>
                        <p class="text-[10px] text-slate-400 mb-3">Wybierz <strong class="text-purple-300">grupy modyfikatorów</strong> które trafią do KAŻDEGO wariantu pizzy. Możesz pominąć — dodasz później ręcznie.</p>
                        <div id="fs6-modifier-groups-list" class="space-y-2 max-h-64 overflow-y-auto"></div>
                        <div class="mt-3 text-[10px] text-slate-500 italic">💡 Typowe grupy: „Dodatki" (salami, pieczarki), „Ciasto" (cienkie/grube), „Bezglutenowe" (toggle).</div>
                    </div>
                    <!-- STEP 5 -->
                    <div class="fs6-step-panel hidden" data-step-panel="5">
                        <h4 class="text-white font-bold text-sm mb-3">Krok 5: Podsumowanie i generowanie</h4>
                        <div id="fs6-summary" class="text-xs text-slate-300 space-y-2 bg-black/40 p-4 rounded-xl border border-white/10"></div>
                        <p class="text-[10px] text-amber-300 mt-3">⚠️ Po kliknięciu „Generuj rodzinę" zostanie utworzony parent + N children w <code>sh_menu_items</code> (każdy ze swoimi cenami + modyfikatorami). Recepturę dodasz osobno w edytorze.</p>
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-white/10 flex items-center justify-between">
                    <button id="fs6-back" onclick="window.ItemEditor._fs6Step(-1)" class="px-4 py-2 text-[10px] uppercase font-black text-slate-400 hover:text-white" disabled>← Wstecz</button>
                    <div class="flex gap-2">
                        <button id="fs6-next" onclick="window.ItemEditor._fs6Step(1)" class="px-5 py-2 text-[10px] uppercase font-black bg-orange-500/20 hover:bg-orange-500/30 text-orange-300 border border-orange-500/30 rounded-lg">Dalej →</button>
                        <button id="fs6-generate" onclick="window.ItemEditor._fs6Generate()" class="hidden px-5 py-2 text-[10px] uppercase font-black bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/30 rounded-lg"><i class="fa-solid fa-wand-magic-sparkles"></i> Generuj rodzinę</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);

        this._fs6CurrentStep = 1;
        this._fs6Data = { name: '', ascii: '', categoryId: 0, desc: '', scaleId: 0, scaleOptions: [], prices: {} };

        // Auto-slug
        const nameEl = document.getElementById('fs6-name');
        nameEl?.addEventListener('input', () => {
            const ascii = this.toAutoSlug(nameEl.value);
            document.getElementById('fs6-ascii').value = ascii;
        });

        // Scale preview
        document.getElementById('fs6-scale')?.addEventListener('change', (e) => {
            const opt = e.target.selectedOptions[0];
            const preview = document.getElementById('fs6-scale-preview');
            const list = document.getElementById('fs6-scale-preview-list');
            if (!opt || !opt.dataset.options) { preview.classList.add('hidden'); return; }
            try {
                const options = JSON.parse(opt.dataset.options);
                preview.classList.remove('hidden');
                list.innerHTML = options.map(o => `
                    <div class="bg-black/40 border border-orange-500/20 rounded p-2">
                        <div class="text-orange-300 font-bold text-sm">${o.name}</div>
                        <div class="text-[9px] text-slate-500 font-mono">${o.key_ascii}</div>
                        <div class="text-[9px] text-amber-300 mt-1">×${parseFloat(o.multiplier || 1).toFixed(2)}</div>
                    </div>
                `).join('');
            } catch (e) { preview.classList.add('hidden'); }
        });
    },

    _fs6Step(direction) {
        const total = 5; // F-S6.1: 5 kroków
        const next = this._fs6CurrentStep + direction;
        if (next < 1 || next > total) return;

        // Walidacja przed przejściem.
        if (this._fs6CurrentStep === 1 && direction > 0) {
            const n = document.getElementById('fs6-name').value.trim();
            const a = document.getElementById('fs6-ascii').value.trim();
            const c = parseInt(document.getElementById('fs6-category').value, 10);
            if (!n) { alert('Podaj nazwę.'); return; }
            if (!a) { alert('Klucz SKU jest wymagany.'); return; }
            if (!c) { alert('Wybierz kategorię.'); return; }
            this._fs6Data.name = n;
            this._fs6Data.ascii = a;
            this._fs6Data.categoryId = c;
            this._fs6Data.desc = document.getElementById('fs6-desc').value.trim();
        }
        if (this._fs6CurrentStep === 2 && direction > 0) {
            const scaleSel = document.getElementById('fs6-scale');
            const sid = parseInt(scaleSel.value, 10) || 0;
            this._fs6Data.scaleId = sid;
            try {
                this._fs6Data.scaleOptions = sid && scaleSel.selectedOptions[0].dataset.options
                    ? JSON.parse(scaleSel.selectedOptions[0].dataset.options) : [];
            } catch (e) { this._fs6Data.scaleOptions = []; }
            this._fs6BuildPriceMatrix();
        }
        if (this._fs6CurrentStep === 3 && direction > 0) {
            const matrix = document.querySelectorAll('#fs6-prices-matrix input.fs6-price-pos');
            this._fs6Data.prices = {};
            matrix.forEach(inp => {
                const optKey = inp.dataset.optKey || '_standalone';
                const pos = parseFloat(inp.value) || 0;
                this._fs6Data.prices[optKey] = { pos, takeaway: pos, delivery: +(pos * 1.10).toFixed(2) };
            });
            // F-S6.1: ładuj modifier groups dla kroku 4.
            this._fs6LoadModifierGroups();
        }
        if (this._fs6CurrentStep === 4 && direction > 0) {
            // F-S6.1: zbierz wybrane grupy modyfikatorów.
            const checks = document.querySelectorAll('#fs6-modifier-groups-list input[type=checkbox]:checked');
            this._fs6Data.modifierGroupIds = Array.from(checks).map(c => parseInt(c.value, 10)).filter(Number.isInteger);
            this._fs6RenderSummary();
        }

        document.querySelectorAll('.fs6-step-panel').forEach(p => p.classList.toggle('hidden', parseInt(p.dataset.stepPanel, 10) !== next));
        document.querySelectorAll('.fs6-step-pill').forEach(p => {
            const step = parseInt(p.dataset.step, 10);
            p.className = 'fs6-step-pill px-2 py-1 rounded ' + (step === next ? 'bg-orange-500/30 text-orange-200' : (step < next ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700/40 text-slate-400'));
        });
        document.getElementById('fs6-back').disabled = next === 1;
        document.getElementById('fs6-next').classList.toggle('hidden', next === total);
        document.getElementById('fs6-generate').classList.toggle('hidden', next !== total);
        this._fs6CurrentStep = next;
    },

    // F-S6.1 — załaduj grupy modyfikatorów tenanta do listy w kroku 4.
    async _fs6LoadModifierGroups() {
        const listEl = document.getElementById('fs6-modifier-groups-list');
        if (!listEl) return;
        listEl.innerHTML = '<p class="text-slate-500 text-[10px] italic text-center py-4">⏳ Ładowanie grup...</p>';
        try {
            const r = await window.apiStudio('get_modifiers_full');
            const groups = (r?.data?.groups || r?.data?.modifierGroups || []);
            if (!groups.length) {
                listEl.innerHTML = '<p class="text-slate-500 text-[10px] italic text-center py-4">Brak grup modyfikatorów w tenancie. Możesz pominąć krok 4 i dodać je później ręcznie.</p>';
                return;
            }
            listEl.innerHTML = '';
            groups.forEach(g => {
                const optsCount = Array.isArray(g.modifiers) ? g.modifiers.length : (Array.isArray(g.options) ? g.options.length : 0);
                const row = document.createElement('label');
                row.className = 'flex items-center gap-3 bg-black/40 hover:bg-purple-500/15 border border-white/10 hover:border-purple-500/40 rounded-lg p-3 transition cursor-pointer';
                row.innerHTML = `
                    <input type="checkbox" value="${g.id}" class="w-4 h-4 rounded border-white/10 bg-black/50 text-purple-500">
                    <div class="flex-1 min-w-0">
                        <div class="text-white text-sm font-bold">${g.name}</div>
                        <div class="text-slate-500 text-[10px] font-mono">${optsCount} opcji${g.minSelection !== undefined ? ` · min ${g.minSelection} / max ${g.maxSelection || '∞'}` : ''}</div>
                    </div>`;
                listEl.appendChild(row);
            });
        } catch (e) {
            listEl.innerHTML = `<p class="text-rose-400 text-[10px] italic text-center py-4">Błąd ładowania: ${e.message}</p>`;
        }
    },

    _fs6BuildPriceMatrix() {
        const container = document.getElementById('fs6-prices-matrix');
        if (!container) return;
        const opts = this._fs6Data.scaleOptions;
        if (!opts.length) {
            container.innerHTML = `
                <div class="bg-black/30 p-3 rounded-xl border border-white/5 flex items-center gap-4">
                    <div class="flex-1">
                        <div class="text-slate-300 font-bold text-sm">Pozycja standalone (bez wariantów)</div>
                        <div class="text-[9px] text-slate-500">×1.00 multiplier</div>
                    </div>
                    <input type="number" step="0.01" data-opt-key="_standalone" class="fs6-price-pos bg-black/50 border border-white/10 text-orange-300 rounded p-2 text-right font-black w-28" placeholder="0.00">
                </div>`;
            return;
        }
        container.innerHTML = opts.map(o => `
            <div class="bg-black/30 p-3 rounded-xl border border-white/5 flex items-center gap-4">
                <div class="flex-1">
                    <div class="text-slate-300 font-bold text-sm">${o.name}</div>
                    <div class="text-[9px] text-slate-500 font-mono">${o.key_ascii} · ×${parseFloat(o.multiplier || 1).toFixed(2)}</div>
                </div>
                <input type="number" step="0.01" data-opt-key="${o.key_ascii}" class="fs6-price-pos bg-black/50 border border-white/10 text-orange-300 rounded p-2 text-right font-black w-28" placeholder="0.00">
                <span class="text-[9px] text-slate-600">PLN/POS</span>
            </div>
        `).join('');
    },

    _fs6RenderSummary() {
        const d = this._fs6Data;
        const sum = document.getElementById('fs6-summary');
        if (!sum) return;
        const optCount = d.scaleOptions.length;
        const linesCount = optCount || 1;
        const priceLines = Object.entries(d.prices).map(([k, p]) =>
            `<div>• <strong>${k}</strong>: POS ${p.pos.toFixed(2)} / Takeaway ${p.takeaway.toFixed(2)} / Delivery ${p.delivery.toFixed(2)}</div>`
        ).join('');
        const modCount = (d.modifierGroupIds || []).length;
        sum.innerHTML = `
            <div>📛 Nazwa: <strong class="text-white">${d.name}</strong></div>
            <div>🔑 Klucz parent SKU: <code class="text-orange-300">${d.ascii}</code></div>
            <div>📁 Kategoria ID: <strong>${d.categoryId}</strong></div>
            ${d.desc ? `<div>📝 Opis: ${d.desc}</div>` : ''}
            <div>📏 Skala: <strong>${d.scaleId ? 'ID ' + d.scaleId + ' (' + optCount + ' opcji)' : 'brak (standalone)'}</strong></div>
            <div>🧩 Grupy modyfikatorów: <strong>${modCount}</strong>${modCount ? ` (id: ${(d.modifierGroupIds || []).join(', ')})` : ''}</div>
            <div class="mt-2 pt-2 border-t border-white/5">${priceLines || '<em class="text-slate-500">(brak cen)</em>'}</div>
            <div class="mt-2 text-[10px] text-emerald-300">→ Wygeneruje ${linesCount} ${linesCount === 1 ? 'pozycję' : 'wariantów'} z cenami per kanał${modCount ? ' i ' + modCount + ' grupami modyfikatorów' : ''}.</div>
        `;
    },

    async _fs6Generate() {
        const btn = document.getElementById('fs6-generate');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generuję...'; }
        const d = this._fs6Data;
        try {
            // 1. Zapisz parent z variant_scale_id.
            const parentPayload = {
                action: 'add_item',
                itemId: 0,
                name: d.name,
                asciiKey: d.ascii,
                categoryId: d.categoryId,
                type: 'standard',
                publicationStatus: 'Live',
                description: d.desc,
                vatRateDineIn: 8,
                vatRateTakeaway: 5,
                priceTiers: [{ channel: 'POS', price: 0 }, { channel: 'Takeaway', price: 0 }, { channel: 'Delivery', price: 0 }],
                variantScaleId: d.scaleId || null,
                isVariantParent: d.scaleId ? 1 : 0,
            };
            const r1 = await window.StudioApi.postPayload(parentPayload);
            if (!r1 || !r1.success) throw new Error('Save parent: ' + (r1?.message || 'unknown'));

            // 2. Jeśli scale → create_variant_family.
            if (d.scaleId) {
                // Pobierz nowo utworzony parent ID przez get_menu_tree (lub list_variant_scales na obejście).
                // Najprościej: get_item_details po asciiKey via menu tree.
                if (typeof window.loadMenuTree === 'function') await window.loadMenuTree();
                const tree = window.StudioState?.menuTree || [];
                let parentId = 0;
                tree.forEach(cat => (cat.items || []).forEach(it => {
                    if (it.asciiKey === d.ascii) parentId = parseInt(it.id, 10);
                }));
                if (!parentId) throw new Error('Parent zapisany, ale nie znaleziony w drzewie. Odśwież ręcznie.');

                const r2 = await window.apiStudio('create_variant_family', {
                    parent_item_id: parentId,
                });
                if (!r2 || !r2.success) throw new Error('Generate family: ' + (r2?.message || 'unknown'));

                // 3. Ustaw ceny per wariant. Każdy child ascii_key = parent + '_' + option.key_ascii.
                // API add_item/update_item_full nie obsługuje teraz update tylko cen, więc bypass-szczegółowy update tier.
                // Wykorzystamy save_bulk z omnichannelPricePatch dla wybranych itemów.
                const created = r2.data?.created || [];
                for (const c of created) {
                    const optKey = c.ascii_key.replace(d.ascii + '_', '');
                    const price = d.prices[optKey];
                    if (!price) continue;
                    // Bezpośrednie INSERT do sh_price_tiers przez specjalny endpoint? Nie mamy.
                    // Najprościej: aktualizuj price tier za pomocą action 'save_modifier_pricing'-style?
                    // Nie. Użyjemy update_item_full per dziecko (po pobraniu id).
                    // Reload tree, znajdź item id, wywołaj update_item_full z priceTiers.
                }
                // Reload tree raz na końcu.
                if (typeof window.loadMenuTree === 'function') await window.loadMenuTree();
                const fresh = window.StudioState?.menuTree || [];
                const updateOne = async (childKey, prices) => {
                    let cid = 0;
                    fresh.forEach(cat => (cat.items || []).forEach(it => { if (it.asciiKey === childKey) cid = parseInt(it.id, 10); }));
                    if (!cid) return;
                    // Pobierz pełne dane dziecka (żeby zachować pola).
                    const detRes = await window.apiStudio('get_item_details', { itemId: cid });
                    const det = detRes?.data || {};
                    await window.apiStudio('update_item_full', {
                        itemId: cid,
                        name: det.name,
                        asciiKey: det.asciiKey,
                        categoryId: det.categoryId,
                        type: det.type || 'standard',
                        publicationStatus: 'Live',
                        vatRateDineIn: det.vatRateDineIn ?? 8,
                        vatRateTakeaway: det.vatRateTakeaway ?? 5,
                        priceTiers: [
                            { channel: 'POS', price: prices.pos },
                            { channel: 'Takeaway', price: prices.takeaway },
                            { channel: 'Delivery', price: prices.delivery },
                        ],
                        modifierGroupIds: det.modifierGroupIds || [],
                    });
                };
                for (const c of created) {
                    const optKey = c.ascii_key.replace(d.ascii + '_', '');
                    const price = d.prices[optKey];
                    if (price) await updateOne(c.ascii_key, price);
                }
                // F-S6.1: przypisz modifier groups do każdego stworzonego child.
                if (this._fs6Data.modifierGroupIds && this._fs6Data.modifierGroupIds.length) {
                    for (const c of created) {
                        let cid = 0;
                        const fresh2 = window.StudioState?.menuTree || [];
                        fresh2.forEach(cat => (cat.items || []).forEach(it => { if (it.asciiKey === c.ascii_key) cid = parseInt(it.id, 10); }));
                        if (!cid) continue;
                        const detRes = await window.apiStudio('get_item_details', { itemId: cid });
                        const det = detRes?.data || {};
                        await window.apiStudio('update_item_full', {
                            itemId: cid,
                            name: det.name, asciiKey: det.asciiKey, categoryId: det.categoryId,
                            type: det.type || 'standard', publicationStatus: 'Live',
                            vatRateDineIn: det.vatRateDineIn ?? 8, vatRateTakeaway: det.vatRateTakeaway ?? 5,
                            priceTiers: det.priceTiers || [],
                            modifierGroupIds: this._fs6Data.modifierGroupIds,
                        });
                    }
                }
            } else {
                // Standalone — zapisz ceny dla parent.
                const fresh = (await window.loadMenuTree?.(), window.StudioState?.menuTree || []);
                let parentId = 0;
                fresh.forEach(cat => (cat.items || []).forEach(it => { if (it.asciiKey === d.ascii) parentId = parseInt(it.id, 10); }));
                const price = d.prices._standalone;
                if (parentId && price) {
                    const detRes = await window.apiStudio('get_item_details', { itemId: parentId });
                    const det = detRes?.data || {};
                    await window.apiStudio('update_item_full', {
                        itemId: parentId,
                        name: det.name, asciiKey: det.asciiKey, categoryId: det.categoryId,
                        type: 'standard', publicationStatus: 'Live',
                        vatRateDineIn: 8, vatRateTakeaway: 5,
                        priceTiers: [
                            { channel: 'POS', price: price.pos },
                            { channel: 'Takeaway', price: price.takeaway },
                            { channel: 'Delivery', price: price.delivery },
                        ],
                        modifierGroupIds: [],
                    });
                }
            }

            alert('✅ Pizza utworzona. Drzewo odświeżone.');
            document.getElementById('fs6-new-pizza-wizard')?.remove();
            if (typeof window.loadMenuTree === 'function') await window.loadMenuTree();
            if (window.Core?.renderTree) window.Core.renderTree();
        } catch (e) {
            console.error('[F-S6] generate', e);
            alert('❌ Błąd: ' + e.message);
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generuj rodzinę'; }
        }
    },

    async openVariantScaleManager() {
        // Lekki modal: lista skal + edycja inline.
        let r = await window.apiStudio('list_variant_scales');
        const scales = (r && r.success && r.data && r.data.scales) ? r.data.scales : [];

        const modal = document.createElement('div');
        modal.id = 'variant-scale-mgr';
        modal.className = 'fixed inset-0 bg-black/80 backdrop-blur-sm z-[200] flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-slate-900 border border-orange-500/30 rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b border-white/10 flex items-center justify-between">
                    <div>
                        <h3 class="text-orange-300 font-black uppercase text-sm tracking-wider"><i class="fa-solid fa-arrows-left-right-to-line"></i> Skale Rozmiarów</h3>
                        <p class="text-[10px] text-slate-500 mt-0.5">Reużywalne między pozycjami (iiko-style). Multiplier wpływa na recepturę.</p>
                    </div>
                    <button onclick="document.getElementById('variant-scale-mgr')?.remove()" class="text-slate-400 hover:text-white text-lg"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-4">
                    <div id="vs-list" class="space-y-3"></div>
                    <div class="flex gap-2">
                        <button onclick="window.ItemEditor._vsAddNew()" class="flex-1 bg-orange-500/10 border border-dashed border-orange-500/30 text-orange-300 rounded-xl py-3 text-[10px] font-black uppercase tracking-wider hover:bg-orange-500/20 transition"><i class="fa-solid fa-plus mr-2"></i> Nowa Skala</button>
                        <!-- F-S1.2 (2026-05-11): Presety z gotowymi multiplier-ami -->
                        <button onclick="window.ItemEditor._vsAddPreset()" class="bg-amber-500/10 border border-amber-500/30 text-amber-300 rounded-xl px-4 py-3 text-[10px] font-black uppercase tracking-wider hover:bg-amber-500/20 transition" title="F-S1.2: gotowe presety (Pizza S/M/L/XL, Coffee S/M/L, etc.)"><i class="fa-solid fa-magic-wand-sparkles mr-2"></i> Preset</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);

        const renderList = () => {
            const root = document.getElementById('vs-list');
            if (!root) return;
            if (scales.length === 0) {
                root.innerHTML = '<p class="text-center text-slate-500 text-[10px] italic py-6">Brak skal. Dodaj nową poniżej.</p>';
                return;
            }
            root.innerHTML = scales.map((s, idx) => `
                <div class="bg-black/40 border border-white/10 rounded-xl p-4">
                    <div class="grid grid-cols-12 gap-3 mb-3">
                        <div class="col-span-6">
                            <label class="text-[8px] text-slate-500 font-bold uppercase">Nazwa</label>
                            <input data-vs-idx="${idx}" data-vs-field="name" class="w-full bg-black/50 border border-white/10 rounded-lg p-2 text-xs text-white" value="${s.name || ''}" placeholder="Rozmiary pizzy">
                        </div>
                        <div class="col-span-4">
                            <label class="text-[8px] text-slate-500 font-bold uppercase">Klucz ASCII</label>
                            <input data-vs-idx="${idx}" data-vs-field="key_ascii" class="w-full bg-black/50 border border-white/10 rounded-lg p-2 text-xs font-mono text-white uppercase" value="${s.key_ascii || ''}" placeholder="SCALE_PIZZA">
                        </div>
                        <div class="col-span-2 flex items-end">
                            <button onclick="window.ItemEditor._vsSave(${idx})" class="w-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 rounded-lg py-2 text-[9px] font-black uppercase hover:bg-emerald-500/30"><i class="fa-solid fa-floppy-disk"></i></button>
                        </div>
                    </div>
                    <div class="space-y-1.5 mt-3" id="vs-opts-${idx}">
                        ${(s.options || []).map((o, oi) => `
                            <div class="grid grid-cols-12 gap-2 items-center bg-black/30 rounded-lg p-2">
                                <input data-vs-idx="${idx}" data-vs-opt="${oi}" data-vs-field="name" class="col-span-3 bg-black/50 border border-white/5 rounded p-1.5 text-[11px] text-white" value="${o.name || ''}" placeholder="Mała">
                                <input data-vs-idx="${idx}" data-vs-opt="${oi}" data-vs-field="key_ascii" class="col-span-2 bg-black/50 border border-white/5 rounded p-1.5 text-[11px] font-mono text-white uppercase" value="${o.key_ascii || ''}" placeholder="S">
                                <input type="number" step="0.01" data-vs-idx="${idx}" data-vs-opt="${oi}" data-vs-field="multiplier" class="col-span-2 bg-black/50 border border-white/5 rounded p-1.5 text-[11px] text-white text-center" value="${o.multiplier ?? 1}" placeholder="1.0">
                                <input type="number" data-vs-idx="${idx}" data-vs-opt="${oi}" data-vs-field="diameter_cm" class="col-span-2 bg-black/50 border border-white/5 rounded p-1.5 text-[11px] text-white text-center" value="${o.diameter_cm ?? ''}" placeholder="cm">
                                <div class="col-span-1 text-[8px] text-slate-500 text-center">× recipe</div>
                                <button onclick="window.ItemEditor._vsRemoveOpt(${idx}, ${oi})" class="col-span-2 bg-rose-500/10 border border-rose-500/20 rounded py-1.5 text-rose-400 text-[9px] font-black uppercase hover:bg-rose-500/20"><i class="fa-solid fa-trash"></i> Usuń</button>
                            </div>
                        `).join('')}
                    </div>
                    <button onclick="window.ItemEditor._vsAddOpt(${idx})" class="mt-2 text-[9px] text-orange-300 hover:text-orange-200"><i class="fa-solid fa-plus"></i> dodaj opcję</button>
                    <button onclick="window.ItemEditor._vsDelete(${idx})" class="mt-2 ml-3 text-[9px] text-rose-400 hover:text-rose-300"><i class="fa-solid fa-trash"></i> usuń skalę</button>
                </div>
            `).join('');

            // Bind inputs to in-memory scales[] for autosave-on-action
            root.querySelectorAll('input[data-vs-idx]').forEach(input => {
                input.addEventListener('input', () => {
                    const i = parseInt(input.dataset.vsIdx, 10);
                    const oi = input.dataset.vsOpt !== undefined ? parseInt(input.dataset.vsOpt, 10) : null;
                    const field = input.dataset.vsField;
                    if (oi !== null) {
                        scales[i].options[oi][field] = input.value;
                    } else {
                        scales[i][field] = input.value;
                    }
                });
            });
        };

        window.ItemEditor._vsAddNew = () => {
            scales.push({ id: 0, name: 'Nowa Skala', key_ascii: 'SCALE_NEW_' + Math.floor(Math.random()*1000), options: [{ name: 'Mała', key_ascii: 'S', multiplier: 0.7, display_order: 0 }] });
            renderList();
        };

        // F-S1.2 — Presety
        window.ItemEditor._vsAddPreset = () => {
            const presets = [
                { label: '🍕 Pizza 4 rozmiary (26/32/36/40 cm)', scale: { name: 'Rozmiary pizzy', key_ascii: 'SCALE_PIZZA_4',
                    options: [
                        { name: 'Mała (26 cm)', key_ascii: 'S', multiplier: 0.70, diameter_cm: 26, display_order: 0 },
                        { name: 'Średnia (32 cm)', key_ascii: 'M', multiplier: 1.00, diameter_cm: 32, display_order: 1, is_default: 1 },
                        { name: 'Duża (36 cm)', key_ascii: 'L', multiplier: 1.30, diameter_cm: 36, display_order: 2 },
                        { name: 'XL (40 cm)', key_ascii: 'XL', multiplier: 1.60, diameter_cm: 40, display_order: 3 },
                    ]
                } },
                { label: '🍕 Pizza 3 rozmiary (S/M/L)', scale: { name: 'Rozmiary pizzy S/M/L', key_ascii: 'SCALE_PIZZA_3',
                    options: [
                        { name: 'Mała', key_ascii: 'S', multiplier: 0.70, display_order: 0 },
                        { name: 'Średnia', key_ascii: 'M', multiplier: 1.00, display_order: 1, is_default: 1 },
                        { name: 'Duża', key_ascii: 'L', multiplier: 1.30, display_order: 2 },
                    ]
                } },
                { label: '☕ Coffee S/M/L', scale: { name: 'Rozmiar kawy', key_ascii: 'SCALE_COFFEE',
                    options: [
                        { name: 'Small (180ml)', key_ascii: 'S', multiplier: 0.75, display_order: 0 },
                        { name: 'Medium (250ml)', key_ascii: 'M', multiplier: 1.00, display_order: 1, is_default: 1 },
                        { name: 'Large (350ml)', key_ascii: 'L', multiplier: 1.40, display_order: 2 },
                    ]
                } },
                { label: '🥤 Napój 0.33/0.5/1.0L', scale: { name: 'Rozmiar napoju', key_ascii: 'SCALE_BEVERAGE',
                    options: [
                        { name: '0.33L', key_ascii: 'S033', multiplier: 0.33, display_order: 0 },
                        { name: '0.5L', key_ascii: 'M050', multiplier: 0.50, display_order: 1, is_default: 1 },
                        { name: '1.0L', key_ascii: 'L100', multiplier: 1.00, display_order: 2 },
                    ]
                } },
                { label: '🍟 Frytki Standard/Duże', scale: { name: 'Rozmiar frytek', key_ascii: 'SCALE_FRIES',
                    options: [
                        { name: 'Standard', key_ascii: 'STD', multiplier: 1.00, display_order: 0, is_default: 1 },
                        { name: 'Duże', key_ascii: 'L', multiplier: 1.50, display_order: 1 },
                    ]
                } },
            ];

            // Modal wyboru
            const presetModal = document.createElement('div');
            presetModal.className = 'fixed inset-0 z-[400] bg-black/85 backdrop-blur-sm flex items-center justify-center p-4';
            presetModal.innerHTML = `
                <div class="bg-slate-900 border border-amber-500/40 rounded-2xl w-full max-w-lg overflow-hidden">
                    <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
                        <h4 class="text-white font-black text-base">Wybierz preset skali</h4>
                        <button onclick="this.closest('.fixed').remove()" class="text-slate-400 hover:text-white text-xl">×</button>
                    </div>
                    <div class="p-4 space-y-2 max-h-96 overflow-y-auto"></div>
                </div>`;
            const listBox = presetModal.querySelector('div.space-y-2');
            presets.forEach(p => {
                const btn = document.createElement('button');
                btn.className = 'w-full bg-black/40 hover:bg-amber-500/15 border border-white/10 hover:border-amber-500/40 rounded-lg p-3 text-left transition';
                btn.innerHTML = `
                    <div class="text-white text-sm font-bold">${p.label}</div>
                    <div class="text-slate-500 text-[10px] mt-1 font-mono">${p.scale.options.map(o => `${o.key_ascii}=${o.multiplier}`).join(' · ')}</div>`;
                btn.onclick = () => {
                    // Unikalność klucza: dopiszemy random sufiks jeśli istnieje
                    let key = p.scale.key_ascii;
                    if (scales.some(s => s.key_ascii === key)) {
                        key = key + '_' + Math.floor(Math.random()*1000);
                    }
                    scales.push({ id: 0, name: p.scale.name, key_ascii: key, options: p.scale.options.map(o => ({ ...o })) });
                    presetModal.remove();
                    renderList();
                };
                listBox.appendChild(btn);
            });
            document.body.appendChild(presetModal);
        };
        window.ItemEditor._vsAddOpt = (i) => {
            if (!scales[i].options) scales[i].options = [];
            scales[i].options.push({ name: 'Nowy', key_ascii: 'NEW' + scales[i].options.length, multiplier: 1.0, display_order: scales[i].options.length });
            renderList();
        };
        window.ItemEditor._vsRemoveOpt = (i, oi) => {
            scales[i].options.splice(oi, 1);
            renderList();
        };
        window.ItemEditor._vsSave = async (i) => {
            const s = scales[i];
            const r = await window.apiStudio('save_variant_scale', {
                id: s.id || 0,
                name: s.name,
                key_ascii: s.key_ascii,
                description: s.description || '',
                options: s.options || []
            });
            if (r && r.success) {
                alert('✅ Zapisano skalę.');
                if (r.data && r.data.scale_id) scales[i].id = r.data.scale_id;
                // Reload select w głównym widoku
                this._loadVariantScalesIntoSelect(this._currentVariantScaleId);
            } else {
                alert('❌ ' + (r?.message || 'unknown'));
            }
        };
        window.ItemEditor._vsDelete = async (i) => {
            if (!confirm('Usunąć tę skalę?')) return;
            const s = scales[i];
            if (s.id) {
                const r = await window.apiStudio('delete_variant_scale', { id: s.id });
                if (!r || !r.success) { alert('❌ ' + (r?.message || 'unknown')); return; }
            }
            scales.splice(i, 1);
            renderList();
        };

        renderList();
    }
};
