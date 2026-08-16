<?php
/**
 * includes/auth.php
 * Registration, login, session tokens. (See prior versions' comments
 * for full security rationale — bcrypt, hand-rolled JWT-shaped token,
 * httpOnly cookie, rate-limited login, generic error messages.)
 */

require_once __DIR__ . '/database.php';

class Auth {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        if (strlen(JWT_SECRET) < 32 || JWT_SECRET === 'REPLACE_WITH_A_REAL_RANDOM_64_CHAR_HEX_STRING') {
            throw new RuntimeException('JWT_SECRET is not configured. See config.php.');
        }
    }

    private function checkRateLimit(string $ip, string $action = 'login'): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as attempts FROM rate_limits
            WHERE ip_address = ? AND action = ? AND created_at > datetime('now', ?)
        ");
        $stmt->execute([$ip, $action, '-' . RATE_LIMIT_LOGIN_WINDOW . ' seconds']);
        if ((int) $stmt->fetch()['attempts'] >= RATE_LIMIT_LOGIN_MAX) return false;
        $stmt = $this->db->prepare("INSERT INTO rate_limits (ip_address, action) VALUES (?, ?)");
        $stmt->execute([$ip, $action]);
        return true;
    }

    public function register(array $data): array {
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please enter a valid email address.'];
        }
        $password = $data['password'] ?? '';
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $accountType = $data['account_type'] ?? '';
        $validTypes = ['rider', 'team', 'sponsor', 'supporter', 'partner', 'volunteer', 'media', 'advertiser', 'host'];
        if (!in_array($accountType, $validTypes, true)) {
            return ['success' => false, 'error' => 'Invalid account type.'];
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Registration failed. Please check your details and try again.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $verificationToken = bin2hex(random_bytes(32));

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO users (email, password_hash, account_type, display_name, location, verification_token)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['email'], $hash, $accountType,
                trim($data['display_name'] ?? $data['email']),
                $data['location'] ?? null, $verificationToken,
            ]);
            $userId = (int) $this->db->lastInsertId();
            $this->createProfile($userId, $accountType, $data);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('Registration error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Registration failed. Please try again.'];
        }

        $token = $this->generateToken($userId);
        $this->setAuthCookie($token);
        return ['success' => true, 'user_id' => $userId, 'account_type' => $accountType];
    }

    private function createProfile(int $userId, string $type, array $data): void {
        switch ($type) {
            case 'rider':
                $teamId = !empty($data['team_id']) ? (int) $data['team_id'] : null;
                if ($teamId !== null) {
                    $check = $this->db->prepare("SELECT id FROM teams WHERE id = ?");
                    $check->execute([$teamId]);
                    if (!$check->fetch()) $teamId = null;
                }
                $stmt = $this->db->prepare("INSERT INTO rider_profiles (user_id, category, gender, team_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $data['category'] ?? 'open', $data['gender'] ?? null, $teamId]);
                break;
            case 'team':
                $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $data['team_name'] ?? 'team-' . $userId), '-'));
                $stmt = $this->db->prepare("INSERT INTO teams (name, slug, captain_user_id) VALUES (?, ?, ?)");
                $stmt->execute([$data['team_name'] ?? 'Unnamed Team', $slug, $userId]);
                break;
            case 'volunteer':
                $stmt = $this->db->prepare("INSERT INTO volunteer_profiles (user_id, primary_role) VALUES (?, ?)");
                $stmt->execute([$userId, $data['primary_role'] ?? 'general']);
                break;
            case 'sponsor':
                $stmt = $this->db->prepare("INSERT INTO sponsor_profiles (user_id, company_name, package_type) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $data['company_name'] ?? '', $data['package_type'] ?? null]);
                break;
            case 'supporter':
                $stmt = $this->db->prepare("INSERT INTO supporter_profiles (user_id, tier) VALUES (?, ?)");
                $stmt->execute([$userId, $data['tier'] ?? 'rhodolite']);
                break;
            case 'partner':
                $stmt = $this->db->prepare("INSERT INTO partner_profiles (user_id, business_name, business_type) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $data['business_name'] ?? '', $data['business_type'] ?? null]);
                break;
            case 'media':
                $stmt = $this->db->prepare("INSERT INTO media_profiles (user_id, brand_name, platform) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $data['brand_name'] ?? '', $data['platform'] ?? null]);
                break;
            case 'advertiser':
                $stmt = $this->db->prepare("INSERT INTO advertiser_profiles (user_id, company_name) VALUES (?, ?)");
                $stmt->execute([$userId, $data['company_name'] ?? '']);
                break;
            case 'host':
                $stmt = $this->db->prepare("INSERT INTO host_profiles (user_id, mpesa_payout_number, notes) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $data['mpesa_payout_number'] ?? '', $data['notes'] ?? '']);
                break;
        }
    }

    public function login(string $email, string $password, string $ip): array {
        if (!$this->checkRateLimit($ip, 'login')) {
            return ['success' => false, 'error' => 'Too many login attempts. Please try again in 15 minutes.'];
        }
        $stmt = $this->db->prepare("SELECT id, password_hash, account_type, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) { usleep(300000); return ['success' => false, 'error' => 'Invalid credentials.']; }
        if (!$user['is_active']) return ['success' => false, 'error' => 'This account is deactivated.'];
        if (!password_verify($password, $user['password_hash'])) { usleep(300000); return ['success' => false, 'error' => 'Invalid credentials.']; }

        $stmt = $this->db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);
        $token = $this->generateToken((int) $user['id']);
        $this->setAuthCookie($token);
        return ['success' => true, 'user_id' => $user['id'], 'account_type' => $user['account_type']];
    }

    public function logout(): array {
        setcookie('auth_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => APP_ENV === 'production', 'httponly' => true, 'samesite' => 'Strict']);
        return ['success' => true];
    }

    private function setAuthCookie(string $token): void {
        setcookie('auth_token', $token, ['expires' => time() + TOKEN_EXPIRY_SECONDS, 'path' => '/', 'secure' => APP_ENV === 'production', 'httponly' => true, 'samesite' => 'Strict']);
    }

    public function generateToken(int $userId): string {
        $header = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->b64(json_encode(['user_id' => $userId, 'iat' => time(), 'exp' => time() + TOKEN_EXPIRY_SECONDS]));
        $signature = $this->b64(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
        return "$header.$payload.$signature";
    }

    public function validateToken(?string $token): ?int {
        if (!$token) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$header, $payload, $signature] = $parts;
        $expected = $this->b64(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
        if (!hash_equals($expected, $signature)) return null;
        $data = json_decode($this->unb64($payload), true);
        if (!$data || ($data['exp'] ?? 0) < time()) return null;
        return (int) $data['user_id'];
    }

    private function b64(string $data): string { return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data)); }
    private function unb64(string $data): string { return base64_decode(str_replace(['-', '_'], ['+', '/'], $data)); }

    public function getUserById(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, email, account_type, display_name, location, logo_path, is_public,
                   is_active, email_verified, created_at, last_login
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
