<?php
/**
 * includes/directory.php
 *
 * Two jobs:
 *  1. Let a logged-in team/sponsor/supporter/partner account upload a logo.
 *  2. Return the public, approved list of each type for the homepage to render.
 *
 * New listings default to NOT public (is_public = 0) — see schema.sql.
 * Nothing appears on the homepage until you flip that flag. There's no
 * admin UI for approving yet (see README-DIRECTORY.md) — that's a
 * deliberate gap, not an oversight, so nothing goes live unreviewed.
 */

require_once __DIR__ . '/database.php';

class Directory {
    private PDO $db;
    private string $uploadDir;

    const ALLOWED_TYPES = ['team', 'sponsor', 'supporter', 'partner'];
    const MAX_BYTES = 2 * 1024 * 1024; // 2MB
    const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->uploadDir = __DIR__ . '/../uploads/logos/';
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Uploads a logo for the given user. For 'team' accounts, this updates
     * the team the user captains, not the user row itself.
     */
    public function uploadLogo(int $userId, string $accountType, array $file): array {
        if (!in_array($accountType, self::ALLOWED_TYPES, true)) {
            return ['success' => false, 'error' => 'This account type does not have a public listing.'];
        }

        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload failed — please try again.'];
        }

        if ($file['size'] > self::MAX_BYTES) {
            return ['success' => false, 'error' => 'Logo must be under 2MB.'];
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return ['success' => false, 'error' => 'Logo must be a JPG, PNG, or WEBP image.'];
        }
        $ext = self::ALLOWED_MIME[$mime];

        $filename = $accountType . '-' . $userId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = $this->uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'error' => 'Could not save the uploaded file.'];
        }

        $relativePath = 'uploads/logos/' . $filename;

        if ($accountType === 'team') {
            $stmt = $this->db->prepare("
                SELECT id, logo_path FROM teams WHERE captain_user_id = ?
            ");
            $stmt->execute([$userId]);
            $team = $stmt->fetch();
            if (!$team) {
                @unlink($destination);
                return ['success' => false, 'error' => 'No team found for this account.'];
            }
            $this->deleteOldFile($team['logo_path']);
            $stmt = $this->db->prepare("UPDATE teams SET logo_path = ? WHERE id = ?");
            $stmt->execute([$relativePath, $team['id']]);
        } else {
            $stmt = $this->db->prepare("SELECT logo_path FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch();
            $this->deleteOldFile($existing['logo_path'] ?? null);
            $stmt = $this->db->prepare("UPDATE users SET logo_path = ? WHERE id = ?");
            $stmt->execute([$relativePath, $userId]);
        }

        return ['success' => true, 'logo_path' => $relativePath];
    }

    private function deleteOldFile(?string $relativePath): void {
        if (!$relativePath) return;
        $full = __DIR__ . '/../' . $relativePath;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /** Public, no-auth-required lists for the homepage. Only approved (is_public=1) entries. */
    public function getPublicListing(string $type): array {
        switch ($type) {
            case 'team':
                $stmt = $this->db->query("
                    SELECT id, name AS display_name, slug, logo_path
                    FROM teams WHERE is_public = 1
                    ORDER BY created_at ASC
                ");
                break;
            case 'sponsor':
                $stmt = $this->db->query("
                    SELECT u.id, u.display_name, u.logo_path
                    FROM users u JOIN sponsor_profiles sp ON sp.user_id = u.id
                    WHERE u.account_type = 'sponsor' AND u.is_public = 1 AND u.is_active = 1
                    ORDER BY u.created_at ASC
                ");
                break;
            case 'supporter':
                $stmt = $this->db->query("
                    SELECT u.id, u.display_name, u.logo_path
                    FROM users u JOIN supporter_profiles sup ON sup.user_id = u.id
                    WHERE u.account_type = 'supporter' AND u.is_public = 1 AND u.is_active = 1
                    ORDER BY u.created_at ASC
                ");
                break;
            case 'partner':
                $stmt = $this->db->query("
                    SELECT u.id, u.display_name, u.logo_path
                    FROM users u JOIN partner_profiles pp ON pp.user_id = u.id
                    WHERE u.account_type = 'partner' AND u.is_public = 1 AND u.is_active = 1
                    ORDER BY u.created_at ASC
                ");
                break;
            default:
                return [];
        }
        return $stmt->fetchAll();
    }

    public function getAllPublicListings(): array {
        return [
            'teams' => $this->getPublicListing('team'),
            'sponsors' => $this->getPublicListing('sponsor'),
            'supporters' => $this->getPublicListing('supporter'),
            'partners' => $this->getPublicListing('partner'),
        ];
    }
}
