/**
 * SLICEHUB DISPATCHER — Leaflet Map Module
 * Carto Dark tiles. Order markers + driver markers.
 */
const CoursesMap = (() => {
    let map = null;
    let orderMarkers = [];
    let driverMarkers = [];
    let orderGroup = null;
    let driverGroup = null;

    const DEFAULT_CENTER = [52.4064, 16.9252]; // Poznań
    const DEFAULT_ZOOM = 13;

    const TILES_URL = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
    const TILES_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>';

    function init() {
        const container = document.getElementById('map-container');
        const placeholder = document.getElementById('map-placeholder');
        if (placeholder) placeholder.remove();

        if (map) return;

        const mapDiv = document.createElement('div');
        mapDiv.id = 'leaflet-map';
        mapDiv.style.cssText = 'height:100%; width:100%;';
        container.appendChild(mapDiv);

        map = L.map('leaflet-map', { zoomControl: true, attributionControl: false }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
        L.tileLayer(TILES_URL, { attribution: TILES_ATTR, maxZoom: 19, subdomains: 'abcd' }).addTo(map);

        orderGroup = L.layerGroup().addTo(map);
        driverGroup = L.layerGroup().addTo(map);

        setTimeout(() => map.invalidateSize(), 200);
    }

    function orderIcon(status, paymentStatus, deliveryStatus) {
        let color = '#3b82f6';
        if (status === 'ready') color = '#22c55e';
        if (deliveryStatus === 'in_delivery') color = '#f97316';
        if (paymentStatus === 'to_pay' || paymentStatus === 'online_unpaid') color = '#ef4444';

        return L.divIcon({
            className: '',
            html: `<div style="width:28px;height:28px;border-radius:50%;background:${color};border:3px solid #0a0f1c;display:flex;align-items:center;justify-content:center;box-shadow:0 0 12px ${color}80">
                     <i class="fa-solid fa-pizza-slice" style="font-size:10px;color:#fff"></i>
                   </div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14],
        });
    }

    function driverIcon(status) {
        const color = status === 'busy' ? '#f97316' : '#22c55e';
        return L.divIcon({
            className: '',
            html: `<div style="width:32px;height:32px;border-radius:50%;background:${color};border:3px solid #0a0f1c;display:flex;align-items:center;justify-content:center;box-shadow:0 0 16px ${color}80">
                     <i class="fa-solid fa-motorcycle" style="font-size:12px;color:#fff"></i>
                   </div>`,
            iconSize: [32, 32],
            iconAnchor: [16, 16],
        });
    }

    function updateMarkers(orders, drivers) {
        if (!map) return;

        orderGroup.clearLayers();
        driverGroup.clearLayers();

        orders.forEach(o => {
            if (!o.delivery_address) return;

            let lat = parseFloat(o.lat);
            let lng = parseFloat(o.lng);
            if (!lat || !lng) {
                lat = DEFAULT_CENTER[0] + (Math.random() - 0.5) * 0.03;
                lng = DEFAULT_CENTER[1] + (Math.random() - 0.5) * 0.03;
            }

            const shortNum = (o.order_number || '').split('/').pop();
            const total = ((parseInt(o.grand_total, 10) || 0) / 100).toFixed(2);
            const marker = L.marker([lat, lng], { icon: orderIcon(o.status, o.payment_status, o.delivery_status) });
            marker.bindPopup(`
                <div style="font-family:Inter,sans-serif; font-size:12px; min-width:180px">
                    <strong>#${shortNum}</strong> — ${total} zł<br>
                    <span style="color:#94a3b8">${o.delivery_address}</span><br>
                    <span style="color:#94a3b8">${o.customer_phone || ''}</span><br>
                    <span style="font-weight:800; text-transform:uppercase; font-size:10px">${o.status}</span>
                </div>
            `, { className: 'dark-popup' });
            orderGroup.addLayer(marker);
        });

        drivers.forEach(d => {
            if (!d.loc_lat || !d.loc_lng) return;
            const lat = parseFloat(d.loc_lat);
            const lng = parseFloat(d.loc_lng);
            if (!lat || !lng) return;

            const name = d.first_name || d.name || 'Driver';
            const marker = L.marker([lat, lng], { icon: driverIcon(d.driver_status) });
            marker.bindPopup(`<div style="font-family:Inter,sans-serif"><strong>${name}</strong><br>${d.driver_status}</div>`);
            driverGroup.addLayer(marker);
        });
    }

    function isInitialized() { return map !== null; }

    return Object.freeze({ init, updateMarkers, isInitialized });
})();
