/**
 * assets/js/messaging.js
 * Requires account.js loaded first (uses the shared ttttAccount instance).
 */

class TTTTMessaging {
    async findUserByEmail(email) {
        return ttttAccount.request(`find-user&email=${encodeURIComponent(email)}`, { method: 'GET' });
    }

    async getConversations() {
        return ttttAccount.request('conversations', { method: 'GET' });
    }

    async getThread(conversationId) {
        return ttttAccount.request(`thread&conversation_id=${conversationId}`, { method: 'GET' });
    }

    async sendMessage(recipientId, body) {
        return ttttAccount.request('send-message', {
            method: 'POST',
            body: JSON.stringify({ recipient_id: recipientId, body }),
        });
    }

    async archiveConversation(conversationId) {
        return ttttAccount.request('archive-conversation', {
            method: 'POST',
            body: JSON.stringify({ conversation_id: conversationId }),
        });
    }

    async getUnreadCount() {
        return ttttAccount.request('unread-count', { method: 'GET' });
    }
}

const ttttMessaging = new TTTTMessaging();
