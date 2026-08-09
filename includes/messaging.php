<?php
/**
 * includes/messaging.php
 *
 * Simple two-party messaging. One conversation per pair of users;
 * messages belong to a conversation. No attachments, no group threads —
 * matches what the site actually needs (a rider messaging a team captain,
 * a sponsor messaging the organisers, etc).
 */

require_once __DIR__ . '/database.php';

class Messaging {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Find a user to message by email — returns just enough to start a conversation. */
    public function findUserByEmail(string $email): ?array {
        $stmt = $this->db->prepare("
            SELECT id, display_name, account_type FROM users WHERE email = ? AND is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    private function getOrCreateConversation(int $userA, int $userB): int {
        // Store the pair in a consistent order so (A,B) and (B,A) map to one row.
        $p1 = min($userA, $userB);
        $p2 = max($userA, $userB);

        $stmt = $this->db->prepare("
            SELECT id FROM conversations WHERE participant1_id = ? AND participant2_id = ?
        ");
        $stmt->execute([$p1, $p2]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO conversations (participant1_id, participant2_id) VALUES (?, ?)
        ");
        $stmt->execute([$p1, $p2]);
        return (int) $this->db->lastInsertId();
    }

    public function sendMessage(int $senderId, int $recipientId, string $body): array {
        $body = trim($body);
        if ($body === '') {
            return ['success' => false, 'error' => 'Message cannot be empty.'];
        }
        if (strlen($body) > 5000) {
            return ['success' => false, 'error' => 'Message is too long.'];
        }
        if ($recipientId === $senderId) {
            return ['success' => false, 'error' => "You can't message yourself."];
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$recipientId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Recipient not found.'];
        }

        $conversationId = $this->getOrCreateConversation($senderId, $recipientId);

        $stmt = $this->db->prepare("
            INSERT INTO messages (conversation_id, sender_id, recipient_id, body) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conversationId, $senderId, $recipientId, $body]);

        $stmt = $this->db->prepare("
            UPDATE conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?
        ");
        $stmt->execute([$conversationId]);

        return ['success' => true, 'conversation_id' => $conversationId];
    }

    /** List conversations for the inbox view, newest first, with unread counts. */
    public function getConversations(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT
                c.id,
                CASE WHEN c.participant1_id = ? THEN c.participant2_id ELSE c.participant1_id END AS other_user_id,
                u.display_name AS other_display_name,
                u.account_type AS other_account_type,
                c.last_message_at,
                (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_body,
                (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND recipient_id = ? AND is_read = 0) AS unread_count
            FROM conversations c
            JOIN users u ON u.id = CASE WHEN c.participant1_id = ? THEN c.participant2_id ELSE c.participant1_id END
            WHERE (c.participant1_id = ? AND c.archived_by_p1 = 0)
               OR (c.participant2_id = ? AND c.archived_by_p2 = 0)
            ORDER BY c.last_message_at DESC
        ");
        $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
        return $stmt->fetchAll();
    }

    /** Full thread for one conversation, marking incoming messages as read. */
    public function getThread(int $userId, int $conversationId): array {
        $stmt = $this->db->prepare("
            SELECT participant1_id, participant2_id FROM conversations WHERE id = ?
        ");
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch();

        if (!$conv || ($conv['participant1_id'] != $userId && $conv['participant2_id'] != $userId)) {
            return ['success' => false, 'error' => 'Conversation not found.'];
        }

        $stmt = $this->db->prepare("
            UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);

        $stmt = $this->db->prepare("
            SELECT m.id, m.sender_id, m.body, m.created_at, u.display_name AS sender_name
            FROM messages m JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$conversationId]);

        return ['success' => true, 'messages' => $stmt->fetchAll()];
    }

    public function archiveConversation(int $userId, int $conversationId): array {
        $stmt = $this->db->prepare("SELECT participant1_id, participant2_id FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch();
        if (!$conv) {
            return ['success' => false, 'error' => 'Conversation not found.'];
        }

        $column = ((int) $conv['participant1_id'] === $userId) ? 'archived_by_p1' : 'archived_by_p2';
        if ((int) $conv['participant1_id'] !== $userId && (int) $conv['participant2_id'] !== $userId) {
            return ['success' => false, 'error' => 'Not part of this conversation.'];
        }

        $stmt = $this->db->prepare("UPDATE conversations SET $column = 1 WHERE id = ?");
        $stmt->execute([$conversationId]);
        return ['success' => true];
    }

    public function getUnreadCount(int $userId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM messages WHERE recipient_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int) $stmt->fetch()['c'];
    }
}
