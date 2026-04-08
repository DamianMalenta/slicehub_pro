// ==========================================
// 🚚 SLICEHUB - MODUŁ FLOTY I KURSÓW (pos_fleet.js)
// ==========================================

function toggleOrderToRoute(id, ev) { 
    if(ev) ev.stopPropagation(); 
    const numericId = Number(id); 
    if(state.routeOrders.includes(numericId)) { state.routeOrders = state.routeOrders.filter(i => i !== numericId); } 
    else { state.routeOrders.push(numericId); } 
    if(typeof renderDrivers === 'function') renderDrivers(); 
    if(typeof renderOrders === 'function') renderOrders(); 
}

function toggleRouteMode(id) { 
    state.routeDriverId = state.routeDriverId === id ? null : id; 
    state.routeOrders = []; 
    if(state.routeDriverId) { showToast("Wybierz zamówienia z listy, aby zbudować kurs.", "info"); }
    renderDrivers(); renderOrders(); 
}

async function sendRoute() { 
    if(state.routeOrders.length === 0 || !state.routeDriverId) return; 
    
    const btnSend = document.getElementById('btn-send-route');
    const orgText = btnSend.innerHTML;
    btnSend.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Wysyłanie...';
    
    const d = await apiPost('assign_route', {driver_id: state.routeDriverId, order_ids: state.routeOrders}); 
    
    if(d.status === 'success') { 
        showToast("Wygenerowano i wysłano kurs do kierowcy!", "success"); 
        state.routeDriverId = null; 
        state.routeOrders = []; 
        
        // 🚨 TWARDE PRZEŁĄCZENIE FILTRA
        state.filterType = 'routes'; 
        if(typeof setFilter === 'function') {
            setFilter('routes'); 
        }
        
        await fetchOrders(); // Czekamy na nowe dane z bazy
        if(typeof renderActiveRoutes === 'function') renderActiveRoutes(); // Wymuszamy render Battlefielda
    } else { 
        showToast(d.error, "error"); 
        btnSend.innerHTML = orgText;
    }
}

function renderDrivers() {
    const list = document.getElementById('drivers-list'); 
    const btnSend = document.getElementById('btn-send-route');
    
    if(state.routeDriverId && state.routeOrders.length > 0) { 
        btnSend.classList.remove('hidden'); 
        btnSend.innerText = `Wyślij Kurs (${state.routeOrders.length})`; 
    } else { 
        btnSend.classList.add('hidden'); 
    }

    list.innerHTML = state.drivers.filter(d => d.status !== 'offline').map(d => {
        const active = state.orders.filter(o => o.driver_id == d.id && o.status === 'in_delivery');
        const returning = state.orders.filter(o => o.driver_id == d.id && o.status === 'delivered');
        const isSelected = state.routeDriverId === d.id;
        
        let courseTags = '';
        if (active.length > 0) {
            let uniqueCourses = [...new Set(active.map(o => o.course_id).filter(c => c))];
            if (uniqueCourses.length > 0) { 
                courseTags = uniqueCourses.map(c => `<span class="bg-blue-600 text-white px-1.5 py-0.5 rounded text-[8px] ml-1.5 inline-block border border-blue-500/50 shadow-sm font-black">${c}</span>`).join(''); 
            }
        }

        let s = "Dostępny"; let c = "text-green-500"; let b = "border-white/5 bg-black/40"; let dot = "bg-green-500";
        if(returning.length > 0 && active.length === 0) { s = "Wraca do bazy"; c = "text-yellow-400 animate-pulse"; b = "border-yellow-500/30 bg-yellow-900/10"; dot = "bg-yellow-500"; } 
        else if(active.length > 0) { s = `W trasie (${active.length})`; c = "text-red-500"; b = "border-red-500/30 bg-red-900/10"; dot = "bg-red-500"; }
        if (isSelected) { b = "border-blue-500 bg-blue-900/20 shadow-[0_0_15px_rgba(59,130,246,0.3)]"; }

        return `
        <div onclick="toggleRouteMode(${d.id})" class="glass p-3 rounded-xl flex items-center gap-3 border transition cursor-pointer ${b}">
            <div class="w-8 h-8 rounded-full bg-black/60 border border-white/10 flex items-center justify-center font-black relative ${isSelected ? 'text-blue-400' : 'text-slate-400'}">
                <i class="fa-solid fa-user-astronaut text-[10px]"></i>
                <span class="absolute -top-1 -right-1 w-3 h-3 ${dot} rounded-full border-[2px] border-[#0a0f1c]"></span>
            </div>
            <div class="flex-1">
                <p class="text-[10px] font-black uppercase text-white leading-tight flex items-center">${d.first_name} ${courseTags}</p>
                <p class="text-[8px] font-black uppercase tracking-widest ${c} mt-0.5">${s}</p>
            </div>
        </div>`;
    }).join('');
    if (!list.innerHTML) list.innerHTML = '<div class="text-center text-slate-600 text-[9px] font-black uppercase mt-6">Brak kierowców online</div>';
}

function renderWaiters() {
    const list = document.getElementById('waiters-list');
    list.innerHTML = state.waiters.map(w => `<div class="glass p-3 rounded-xl flex items-center gap-3 border border-white/5 bg-black/40"><div class="w-8 h-8 rounded-full bg-blue-900/30 flex items-center justify-center font-black text-blue-400"><i class="fa-solid fa-user-tie text-[10px]"></i></div><div><p class="text-[10px] font-black uppercase text-white leading-tight">${w.first_name}</p><p class="text-[8px] font-black uppercase tracking-widest text-green-500 mt-0.5">Na Sali</p></div></div>`).join('');
    if (!list.innerHTML) list.innerHTML = '<div class="text-center text-slate-600 text-[9px] font-black uppercase mt-6">Brak kelnerów</div>';
}