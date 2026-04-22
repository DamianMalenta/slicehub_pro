/**
 * SliceHub — warstwa klienta magazynu V2 (jedna ścieżka do api/warehouse/*.php).
 * Wymaga wcześniejszego załadowania ../../core/js/api_client.js
 */
(function () {
    'use strict';

    const BASE = '../../api/warehouse/';
    const DEFAULT_WAREHOUSE_ID = 'MAIN';

    function stockList(warehouseId) {
        return window.ApiClient.get(BASE + 'stock_list.php', {
            warehouse_id: warehouseId || DEFAULT_WAREHOUSE_ID,
        });
    }

    function avcoDict(warehouseId) {
        return window.ApiClient.get(BASE + 'avco_dict.php', {
            warehouse_id: warehouseId || DEFAULT_WAREHOUSE_ID,
        });
    }

    function postReceipt(payload) {
        return window.ApiClient.post(BASE + 'receipt.php', payload);
    }

    function postInternalRw(payload) {
        const body = Object.assign(
            { warehouse_id: DEFAULT_WAREHOUSE_ID },
            payload
        );
        return window.ApiClient.post(BASE + 'internal_rw.php', body);
    }

    function postAddItem(payload) {
        return window.ApiClient.post(BASE + 'add_item.php', payload);
    }

    function postInventory(payload) {
        return window.ApiClient.post(BASE + 'inventory.php', payload);
    }

    function postTransfer(payload) {
        return window.ApiClient.post(BASE + 'transfer.php', payload);
    }

    function postCorrection(payload) {
        return window.ApiClient.post(BASE + 'correction.php', payload);
    }

    function getMappingList() {
        return window.ApiClient.get(BASE + 'mapping.php', {});
    }

    function saveMapping(externalName, internalSku) {
        return window.ApiClient.post(BASE + 'mapping.php', {
            external_name: externalName,
            internal_sku:  internalSku,
        });
    }

    function deleteMapping(id) {
        return window.ApiClient.post(BASE + 'mapping.php', { delete_id: id });
    }

    function getWarehouseList() {
        return window.ApiClient.get(BASE + 'warehouse_list.php', {});
    }

    function postBatchRw(payload) {
        return window.ApiClient.post(BASE + 'batch_rw.php', payload);
    }

    function getDocumentsList(params) {
        return window.ApiClient.get(BASE + 'documents_list.php', params || {});
    }

    function postApproval(payload) {
        return window.ApiClient.post(BASE + 'approve.php', payload);
    }

    window.WarehouseApi = Object.freeze({
        BASE,
        DEFAULT_WAREHOUSE_ID,
        stockList,
        avcoDict,
        postReceipt,
        postInternalRw,
        postAddItem,
        postInventory,
        postTransfer,
        postCorrection,
        getMappingList,
        saveMapping,
        deleteMapping,
        getWarehouseList,
        postBatchRw,
        getDocumentsList,
        postApproval,
    });
})();
