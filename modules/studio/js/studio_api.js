/**
 * Studio Menu — canonical API client (api_menu_studio.php).
 * Wszystkie moduły Studio powinny wołać przez apiStudio() / StudioApi.post().
 */
window.StudioApi = {
    endpoint() {
        if (window.SliceHub && window.SliceHub.apiUrl) {
            return window.SliceHub.apiUrl('/backoffice/api_menu_studio.php');
        }
        return '../../api/backoffice/api_menu_studio.php';
    },

    async post(action, payload = {}) {
        const body = { action, ...payload };
        return window.ApiClient.post(this.endpoint(), body);
    },

    /** Payload z polem action (legacy migrate helper). */
    async postPayload(body) {
        if (!body || typeof body !== 'object') {
            throw new Error('StudioApi.postPayload: invalid body');
        }
        const { action, ...rest } = body;
        if (!action) {
            throw new Error('StudioApi.postPayload: missing action');
        }
        return this.post(action, rest);
    },
};

/** Skrót używany w całym Studio. */
window.apiStudio = (action, payload = {}) => window.StudioApi.post(action, payload);
