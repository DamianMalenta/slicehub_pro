// ==========================================
// 🚚 SLICEHUB - BATTLEFIELD KURSÓW (pos_active_routes.js)
// ==========================================

function renderActiveRoutes() {
    const grid = document.getElementById('bf-grid');
    let routeGroups = {};
    
    // 1. Grupowanie zamówień w trasie (in_delivery)
    state.orders.forEach(o => {
        if (o.status === 'in_delivery' && o.course_id) {
            if (!routeGroups[o.course_id]) {
                routeGroups[o.course_id] = {
                    driver_id: o.driver_id,
                    course_id: o.course_id,
                    items: [],
                    cashToCollect: 0,
                    cardToCollect: 0,
                    paidTotal: 0
                };
            }
            routeGroups[o.course_id].items.push(o);
            
            let price = parseFloat(o.total_price) || 0;
            
            // BEZWZGLĘDNA LOGIKA PORTFELA KIEROWCY
            if (o.payment_status === 'paid') {
                routeGroups[o.course_id].paidTotal += price;
            } else {
                if (o.payment_method === 'card') {
                    routeGroups[o.course_id].cardToCollect += price;
                } else {
                    // Domyślnie wszystko nieopłacone co nie jest kartą to gotówka
                    routeGroups[o.course_id].cashToCollect += price;
                }
            }
        }
    });

    const activeRouteKeys = Object.keys(routeGroups);

    // Pusty stan
    if (activeRouteKeys.length === 0) {
        grid.innerHTML = `
        <div class="w-full mt-20 flex flex-col items-center justify-center text-slate-600">
            <i class="fa-solid fa-road-circle-check text-6xl mb-4 opacity-50"></i>
            <h3 class="text-xl font-black uppercase tracking-widest">Brak Aktywnych Kursów</h3>
            <p class="text-[10px] font-bold mt-2">Wszystkie zamówienia zostały rozliczone lub czekają na kuchni.</p>
        </div>`;
        return;
    }

    let html = '<div class="grid grid-cols-1 2xl:grid-cols-2 gap-5 w-full content-start pb-20">';

    activeRouteKeys.forEach(k => {
        const g = routeGroups[k];
        const driver = state.drivers.find(d => d.id == g.driver_id) || { first_name: 'Nieznany', initial_cash: 0 };
        const initialCash = parseFloat(driver.initial_cash || 0);
        const totalCashToReturn = initialCash + g.cashToCollect;

        // Sortowanie po przystankach L1, L2...
        g.items.sort((a, b) => {
            let numA = parseInt((a.stop_number || 'L99').replace('L', ''));
            let numB = parseInt((b.stop_number || 'L99').replace('L', ''));
            return numA - numB;
        });

        // Generowanie Bonów Przystanków
        const stopsHtml = g.items.map(o => {
            let payBadge = '';
            if (o.payment_status === 'paid') {
                payBadge = `<span class="bg-green-900/30 text-green-500 border border-green-500/30 px-2 py-0.5 rounded text-[8px] font-black">OPŁACONE (${(o.payment_method||'').toUpperCase()}) - NIE POBIERAJ</span>`;
            } else if (o.payment_method === 'card') {
                payBadge = `<span class="bg-blue-900/30 text-blue-400 border border-blue-500/30 px-2 py-0.5 rounded text-[8px] font-black">KARTA (WEŹ TERMINAL)</span>`;
            } else {
                payBadge = `<span class="bg-red-900/30 text-red-400 border border-red-500/30 px-2 py-0.5 rounded text-[8px] font-black">GOTÓWKA DO POBRANIA</span>`;
            }

            return `
            <div class="bg-black/60 border border-white/5 p-3 rounded-xl mb-2 flex justify-between items-center cursor-pointer hover:border-blue-500/50 transition relative overflow-hidden group shadow-sm" onclick="openUnifiedModal(${o.id})">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-slate-700 group-hover:bg-blue-500 transition"></div>
                <div class="flex items-center gap-4 pl-2">
                    <div class="w-9 h-9 rounded bg-[#0a0f1c] border border-white/10 flex items-center justify-center font-black text-white shadow-lg text-sm">${o.stop_number || '-'}</div>
                    <div>
                        <p class="text-[12px] font-black text-white leading-tight">#${o.order_number.split('/').pop()} <span class="text-slate-500 mx-1">•</span> ${o.address}</p>
                        <p class="text-[9px] text-slate-400 mt-1 font-bold"><i class="fa-solid fa-phone mr-1"></i> ${o.customer_phone || 'Brak telefonu'}</p>
                    </div>
                </div>
                <div class="text-right flex flex-col items-end shrink-0">
                    <span class="text-base font-black text-white italic">${o.total_price} zł</span>
                    <div class="mt-1">${payBadge}</div>
                </div>
            </div>`;
        }).join('');

        // Budowa Głównej Karty Kursu
        html += `
        <div class="glass rounded-[24px] border border-white/10 flex flex-col overflow-hidden bg-[#0a0f1c] shadow-[0_15px_40px_rgba(0,0,0,0.6)] relative">
            
            <div class="p-5 bg-black/40 border-b border-white/5 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-600 flex items-center justify-center font-black text-white shadow-[0_0_20px_rgba(59,130,246,0.5)] text-xl border-[2px] border-[#0a0f1c]">
                        ${g.course_id.replace('K', '')}
                    </div>
                    <div>
                        <h3 class="font-black text-base uppercase text-white tracking-wide"><i class="fa-solid fa-motorcycle text-blue-400 mr-2"></i> ${driver.first_name}</h3>
                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><i class="fa-solid fa-clock mr-1"></i> Wyjazd: ${g.items[0].promised_time ? g.items[0].promised_time.substring(11, 16) : '--:--'}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button onclick="alert('Moduł Wiadomości SMS uruchomimy w kolejnym etapie')" class="w-10 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-slate-400 hover:text-white hover:bg-blue-600 transition shadow-sm" title="Wiadomość do kierowcy"><i class="fa-regular fa-comment-dots"></i></button>
                    <button onclick="alert('Moduł Edycji Trasy uruchomimy w kolejnym etapie')" class="w-10 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-slate-400 hover:text-white hover:bg-yellow-600 transition shadow-sm" title="Edytuj Trasę"><i class="fa-solid fa-pen"></i></button>
                    <button onclick="alert('Zawracanie uruchomimy w kolejnym etapie')" class="w-10 h-10 rounded-xl bg-red-900/30 border border-red-500/30 flex items-center justify-center text-red-500 hover:text-white hover:bg-red-600 transition shadow-sm" title="Zawróć Kierowcę"><i class="fa-solid fa-rotate-left"></i></button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-white/5 border-b border-white/5">
                <div class="bg-[#0a0f1c] p-4 flex flex-col justify-center text-center">
                    <span class="text-[8px] font-black uppercase text-slate-500 tracking-widest mb-1.5">Gotówka (Start)</span>
                    <span class="text-sm font-black text-slate-300">${initialCash.toFixed(2)} zł</span>
                </div>
                <div class="bg-[#0a0f1c] p-4 flex flex-col justify-center text-center">
                    <span class="text-[8px] font-black uppercase text-slate-500 tracking-widest mb-1.5">Płatność Kartą</span>
                    <span class="text-sm font-black text-blue-400">${g.cardToCollect.toFixed(2)} zł</span>
                </div>
                <div class="bg-[#0a0f1c] p-4 flex flex-col justify-center text-center">
                    <span class="text-[8px] font-black uppercase text-slate-500 tracking-widest mb-1.5">Opłacone z Góry</span>
                    <span class="text-sm font-black text-green-500">${g.paidTotal.toFixed(2)} zł</span>
                </div>
                <div class="bg-red-900/10 p-4 flex flex-col justify-center text-center border-b-[3px] border-red-500 shadow-[inset_0_0_20px_rgba(239,68,68,0.15)] relative">
                    <span class="text-[8px] font-black uppercase text-red-400 tracking-widest mb-1.5 relative z-10">ZBIERZ GOTÓWKĘ</span>
                    <span class="text-base font-black text-red-500 italic relative z-10">+ ${g.cashToCollect.toFixed(2)} zł</span>
                </div>
            </div>

            <div class="bg-black/80 p-5 border-b border-white/5 flex justify-between items-center">
                <span class="text-[11px] font-black uppercase text-slate-400 tracking-widest"><i class="fa-solid fa-wallet mr-2 text-slate-500"></i> Gotówka do zdania po powrocie:</span>
                <span class="text-3xl font-black text-white italic drop-shadow-[0_0_10px_rgba(255,255,255,0.2)]">${totalCashToReturn.toFixed(2)} <span class="text-sm text-slate-500 not-italic">zł</span></span>
            </div>

            <div class="p-5 bg-black/20 flex-1 overflow-y-auto max-h-[400px] hide-scrollbar">
                <h4 class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-3 pl-1">Przystanki na trasie (${g.items.length})</h4>
                ${stopsHtml}
            </div>
        </div>`;
    });

    html += '</div>';
    grid.innerHTML = html;
}