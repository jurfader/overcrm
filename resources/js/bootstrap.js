import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

// Fix Mixed Content: wymuś HTTPS gdy strona jest na HTTPS
window.axios.interceptors.request.use((config) => {
    if (typeof window !== 'undefined' && window.location.protocol === 'https:' && typeof config.url === 'string' && config.url.startsWith('http://')) {
        config.url = config.url.replace('http://', 'https://');
    }
    const csrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];
    if (csrf && !config.headers['X-XSRF-TOKEN']) {
        config.headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf);
    }
    return config;
});
