/**
 * SLICEHUB DISPATCHER — UI Rendering Module
 * Pure rendering functions. No state mutation, no API calls.
 * 3-Pillar State Machine aware.
 */
const CoursesUI = (() => {

    function formatGrosze(g) {
        return (parseInt(g, 10) / 100).toFixed(2);
    }

    function isPaid(ps) {
        return ['cash', 'card', 'online_paid'].includes(ps);
    }

    function slaClass(promisedTime) {
        if (!promisedTime) return 'sla-green';
        const diff = (new Date(promisedTime) - new Date()) / 60000;
        if (diff < 0) return 'sla-red';
        if (diff <= 5) return 'sla-yellow';
        return 'sla-green';
    }

    function slaText(promisedTime) {
        if (!promisedTime) return '--:--';
        const diff = Math.floor((new Date(promisedTime) - new Date()) / 60000);
        if (diff < 0) return `Spóźnione ${Math.abs(diff)}m`;
        return `Za ${diff} min`;
    }

    function payBadge(order) {
        const ps = order.payment_status;
        if (ps === 'online_paid') return `<span class="ord-badge pay-prepaid">OPŁACONE ONLINE</span>`;
        if (ps === 'cash')        return `<span class="ord-badge pay-paid">GOTÓWKA</span>`;
        if (ps === 'card')        return `<span class="ord-badge pay-card">KARTA</span>`;
        if (ps === 'online_unpaid') return `<span class="ord-badge pay-unpaid">DO ZAPŁATY</span>`;
        return `<span class="ord-badge pay-unpaid">DO ZAPŁATY</span>`;
    }

    const STATUS_LABELS = {
        pending: 'NOWE', preparing: 'W PRZYGOTOWANIU', ready: 'GOTOWE',
        completed: 'DOSTARCZONE', cancelled: 'ANULOWANE',
    };

    function renderDriversList(drivers, selectedDriverId) {
        const el = document.getElementById('drivers-list');
        const cnt = document.getElementById('drv-count');
        const online = drivers.filter(d => d.driver_status !== 'offline');
        cnt.textContent = online.length;

        if (online.length === 0) {
            el.innerHTML = '<div class="empty-state" style="padding:20px"><i class="fa-solid fa-user-slash" style="font-size:24px"></i><p style="margin-top:8px">Brak kierowców online</p></div>';
            return;
        }

        el.innerHTML = online.map(d => {
            const displayName = d.first_name || d.name || 'Kierowca';
            const isSelected = String(d.id) === String(selectedDriverId);
            const status = d.driver_status || 'offline';
            const cash = d.cash_collected_today ? formatGrosze(d.cash_collected_today) : '0.00';
            const initCash = d.initial_cash ? formatGrosze(d.initial_cash) : '0.00';

            return `
            <div class="drv-card ${isSelected ? 'drv-selected' : ''}" onclick="App.selectDriver('${d.id}')">
                <div class="drv-card-top">
                    <span class="drv-name"><i class="fa-solid fa-motorcycle" style="margin-right:4px; color:var(--accent-blue)"></i>${displayName}</span>
                    <span class="drv-status ${status}">${status === 'available' ? 'Dostępny' : status === 'busy' ? 'W trasie' : 'Offline'}</span>
                </div>
                <div class="drv-cash"><i class="fa-solid fa-wallet"></i> Start: ${initCash} zł | Zebrane: ${cash} zł</div>
                <div class="drv-actions" onclick="event.stopPropagation()">
                    <button class="drv-act-btn" onclick="App.openCashModal('${d.id}')"><i class="fa-solid fa-coins"></i> Gotówka</button>
                    <button class="drv-act-btn" onclick="App.openReconcileModal('${d.id}')"><i class="fa-solid fa-calculator"></i> Rozlicz</button>
                    ${status === 'busy' ? `<button class="drv-act-btn recall" onclick="App.openRecallModal('${d.id}', '${displayName.replace(/'/g, "\\'")}')"><i class="fa-solid fa-triangle-exclamation"></i> Zawróć</button>` : ''}
                </div>
            </div>`;
        }).join('');
    }

    function renderOrdersGrid(orders, selectedOrderIds) {
        const el = document.getElementById('orders-grid');
        const dispatchable = orders.filter(o =>
            ['pending','preparing','ready'].includes(o.status) &&
            (!o.delivery_status || o.delivery_status === 'unassigned')
        );

        if (dispatchable.length === 0) {
            el.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-check-double"></i><h3>Brak zamówień do wysłania</h3><p>Wszystkie zamówienia delivery są w trasie lub zakończone.</p></div>';
            return;
        }

        el.innerHTML = dispatchable.map(o => {
            const sla = slaClass(o.promised_time);
            const isReady = o.status === 'ready';
            const isSelected = selectedOrderIds.includes(o.id);
            const total = formatGrosze(o.grand_total);
            const lines = (o.lines || []).slice(0, 4).map(l =>
                `<div class="ord-line">${l.quantity}x ${l.snapshot_name}</div>`
            ).join('');

            const readyLockClass = isReady ? '' : 'ord-not-ready';
            const statusLabel = STATUS_LABELS[o.status] || o.status.toUpperCase();

            return `
            <div class="ord-card ${sla} ${isSelected ? 'selected' : ''} ${readyLockClass}" onclick="App.toggleOrder('${o.id}', ${isReady})">
                ${isSelected ? `<div class="ord-select-badge"><i class="fa-solid fa-check"></i></div>` : ''}
                ${!isReady ? `<div class="ord-lock-badge"><i class="fa-solid fa-clock"></i></div>` : ''}
                <div class="ord-card-top">
                    <span class="ord-num">#${(o.order_number || '').split('/').pop()}</span>
                    <span class="ord-sla">${slaText(o.promised_time)}</span>
                </div>
                <div class="ord-addr"><i class="fa-solid fa-location-dot"></i> ${o.delivery_address || 'Brak adresu'}</div>
                <div class="ord-phone"><i class="fa-solid fa-phone"></i> ${o.customer_phone || '—'} ${o.customer_name ? `• ${o.customer_name}` : ''}</div>
                <div class="ord-meta">
                    ${payBadge(o)}
                    <span class="ord-badge status-badge">${statusLabel}</span>
                    <span class="ord-badge type-badge">${o.source || 'POS'}</span>
                </div>
                <div class="ord-card-top" style="margin-top:8px">
                    <span class="ord-total">${total} zł</span>
                </div>
                ${lines ? `<div class="ord-items">${lines}</div>` : ''}
            </div>`;
        }).join('');
    }

    function renderCoursesGrid(orders, courses, drivers) {
        const el = document.getElementById('courses-grid');
        const active = orders.filter(o => (o.delivery_status === 'in_delivery' || o.delivery_status === 'queued') && o.course_id);

        const groups = {};
        active.forEach(o => {
            if (!groups[o.course_id]) {
                groups[o.course_id] = { driver_id: o.driver_id, items: [], cashToCollect: 0, cardToCollect: 0, paidTotal: 0, isQueued: false };
            }
            groups[o.course_id].items.push(o);
            if (o.delivery_status === 'queued') groups[o.course_id].isQueued = true;
            if (o.driver_id) groups[o.course_id].driver_id = o.driver_id;
            const price = parseInt(o.grand_total, 10) || 0;
            if (isPaid(o.payment_status)) {
                groups[o.course_id].paidTotal += price;
            } else if (o.payment_status === 'online_unpaid') {
                groups[o.course_id].cardToCollect += price;
            } else {
                groups[o.course_id].cashToCollect += price;
            }
        });

        const keys = Object.keys(groups);
        if (keys.length === 0) {
            el.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><i class="fa-solid fa-road-circle-check"></i><h3>Brak Aktywnych Kursów</h3><p>Przypisz zamówienia do kierowcy w zakładce Zamówienia.</p></div>';
            return;
        }

        el.innerHTML = keys.map(k => {
            const g = groups[k];
            const isUnassigned = !g.driver_id;
            const driver = isUnassigned ? null : drivers.find(d => String(d.id) === String(g.driver_id));
            const driverName = isUnassigned ? 'NIEPRZYPISANY' : (driver ? (driver.first_name || driver.name || 'Kierowca') : 'Nieznany');
            const initCash = driver && driver.initial_cash ? parseInt(driver.initial_cash, 10) : 0;
            const totalReturn = initCash + g.cashToCollect;

            g.items.sort((a, b) => {
                const na = parseInt((a.stop_number || 'L99').replace('L', ''));
                const nb = parseInt((b.stop_number || 'L99').replace('L', ''));
                return na - nb;
            });

            const stopsHtml = g.items.map(o => {
                let payClass = 'cash', payText = 'DO ZAPŁATY';
                if (isPaid(o.payment_status)) { payClass = 'paid'; payText = o.payment_status === 'online_paid' ? 'ONLINE' : o.payment_status.toUpperCase(); }
                else if (o.payment_status === 'online_unpaid') { payClass = 'card'; payText = 'ONLINE DO ZAPŁATY'; }

                return `
                <div class="stop-card">
                    <div class="stop-num">${o.stop_number || '—'}</div>
                    <div class="stop-info">
                        <div class="stop-order">#${(o.order_number || '').split('/').pop()} • ${o.delivery_address || ''}</div>
                        <div class="stop-addr"><i class="fa-solid fa-phone"></i> ${o.customer_phone || '—'}</div>
                    </div>
                    <div class="stop-right">
                        <div class="stop-amount">${formatGrosze(o.grand_total)} zł</div>
                        <span class="stop-pay ${payClass}">${payText}</span>
                    </div>
                </div>`;
            }).join('');

            const driverBadge = isUnassigned
                ? `<span class="course-driver unassigned" style="margin-left:10px;color:var(--accent-red);font-weight:900"><i class="fa-solid fa-circle-exclamation"></i> NIEPRZYPISANY</span>`
                : `<span class="course-driver" style="margin-left:10px"><i class="fa-solid fa-motorcycle"></i> ${driverName}</span>`;

            const assignBtn = isUnassigned
                ? `<button class="modal-btn primary" style="margin-top:8px;width:100%" onclick="App.openAssignDriverModal('${k}')"><i class="fa-solid fa-user-plus"></i> Przypisz Kierowcę</button>`
                : '';

            return `
            <div class="course-card ${isUnassigned ? 'course-unassigned' : ''}">
                <div class="course-header">
                    <div>
                        <span class="course-id">${k}</span>
                        ${driverBadge}
                    </div>
                    <span class="course-time">${g.items.length} przystanków</span>
                </div>
                ${!isUnassigned ? `<div class="course-wallet">
                    <div class="cw-cell"><div class="cw-label">Do pobrania (gotówka)</div><div class="cw-value cash">${formatGrosze(g.cashToCollect)} zł</div></div>
                    <div class="cw-cell"><div class="cw-label">Do pobrania (terminal)</div><div class="cw-value card">${formatGrosze(g.cardToCollect)} zł</div></div>
                    <div class="cw-cell"><div class="cw-label">Opłacone z góry</div><div class="cw-value paid">${formatGrosze(g.paidTotal)} zł</div></div>
                    <div class="cw-cell"><div class="cw-label">Gotówka do zdania</div><div class="cw-value total">${formatGrosze(totalReturn)} zł</div></div>
                </div>` : ''}
                <div class="course-stops">${stopsHtml}</div>
                ${assignBtn}
            </div>`;
        }).join('');
    }

    function toast(msg, type = 'info') {
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => t.remove(), 4000);
    }

    return Object.freeze({ renderDriversList, renderOrdersGrid, renderCoursesGrid, toast, formatGrosze });
})();
