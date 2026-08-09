/**
 * assets/js/account.js
 *
 * Talks to api/index.php. Auth lives in an httpOnly cookie the browser
 * sends automatically — this file never touches localStorage for the
 * token, only credentials:'include' on every fetch.
 */

class TTTTAccount {
    constructor() {
        this.apiBase = '/api/index.php';
        this.user = null;
    }

    async request(endpoint, options = {}) {
        const res = await fetch(`${this.apiBase}?endpoint=${endpoint}`, {
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            ...options,
        });
        return res.json();
    }

    async register(data) {
        const result = await this.request('register', { method: 'POST', body: JSON.stringify(data) });
        if (result.success) await this.checkAuth();
        return result;
    }

    async login(email, password) {
        const result = await this.request('login', { method: 'POST', body: JSON.stringify({ email, password }) });
        if (result.success) await this.checkAuth();
        return result;
    }

    async logout() {
        await this.request('logout', { method: 'POST' });
        this.user = null;
        window.location.href = '../index.html';
    }

    async checkAuth() {
        const result = await this.request('profile', { method: 'GET' });
        this.user = result.success ? result.user : null;
        return this.user;
    }

    isAuthenticated() {
        return !!this.user;
    }
}

const ttttAccount = new TTTTAccount();
