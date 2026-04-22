window.ItemEditor = {
    _debounceTimers: {},
    _vatInheritEnabled: true,
    _currentPubStatus: 'Draft',
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
                .map(cb => parseInt(cb.value, 10)).filter(Number.isInteger)
        };

        const btn = $('btn-save-item');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> ZAPISYWANIE...'; }

        try {
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);
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
            const r = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', {
                action: 'set_item_hero',
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
            const r = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', {
                action: 'unlink_item_hero',
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
            const result = await window.ApiClient.post('../../api/backoffice/api_menu_studio.php', payload);

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
    }
};
