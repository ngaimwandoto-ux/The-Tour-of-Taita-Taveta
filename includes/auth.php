<?php
/**
 * includes/auth.php
 *
 * Registration, login, and session tokens — plain PHP, no libraries.
 *
 * Security choices, and why, for future-you:
 *  - PASSWORD_DEFAULT (bcrypt), not Argon2id: Argon2 needs libsodium/argon2
 *    compiled into PHP, which isn't guaranteed on shared hosting. Bcrypt
 *    via PASSWORD_DEFAULT works everywhere and is still strong.
 *  - Token is a hand-rolled JWT-shaped token (header.payload.signature),
 *    verified with hash_equals() to avoid timing attacks on the signature
 *    comparison.
 *  - Token lives in an httpOnly, SameSite=Strict cookie — never
 *    localStorage — so it can't be read or exfiltrated by injected JS.
 *  - Generic error messages on login/register to avoid leaking whether
 *    a given email is registered (user enumeration).
 *  - Login is rate-limited per IP using the rate_limits table.
 */

require_once __DIR__ . '/database.php';

class Auth {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();

        if (strlen(JWT_SECRET) < 32 || JWT_SECRET === 'REPLACE_WITH_A_REAL_RANDOM_64_CHAR_HEX_STRING') {
            throw new RuntimeException(
                'JWT_SECRET is not configured. Generate one with: php -r "echo bin2hex(random_bytes(32));" and set it in config.php.'
            );
        }
    }

    // ---------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------

    private function checkRateLimit(string $ip, string $action = 'login'): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as attempts FROM rate_limits
            WHERE ip_address = ? AND action = ?
              AND created_at > datetime('now', ?)
        ");
        $stmt->execute([$ip, $action, '-' . RATE_LIMIT_LOGIN_WINDOW . ' seconds']);
        $attempts = (int) $stmt->fetch()['attempts'];

        if ($attempts >= RATE_LIMIT_LOGIN_MAX) {
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO rate_limits (ip_address, action) VALUES (?, ?)");
        $stmt->execute([$ip, $action]);
        return true;
    }

    // ---------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------

    public function register(array $data): array {
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please enter a valid email address.'];
        }

        $password = $data['password'] ?? '';
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $accountType = $data['account_type'] ?? '';
        $validTypes = ['rider', 'team', 'sponsor', 'supporter', 'partner', 'volunteer'];
        if (!in_array($accountType, $validTypes, true)) {
            return ['success' => false, 'error' => 'Invalid account type.'];
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            // Generic message — don't confirm the email already exists.
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
                $data['email'],
                $hash,
                $accountType,
                trim($data['display_name'] ?? $data['email']),
                $data['location'] ?? null,
                $verificationToken,
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

        // NOTE: no email is sent yet — mail() is unreliable on shared
        // hosting and there's no verified sender configured. Wire this
        // up (mail() or a transactional provider) when you actually
        // need verification emails to go out.

        return ['success' => true, 'user_id' => $userId, 'account_type' => $accountType];
    }

    private function createProfile(int $userId, string $type, array $data): void {
        switch ($type) {
            case 'rider':
                $stmt = $this->db->prepare("
                    INSERT INTO rider_profiles (user_id, category, gender) VALUES (?, ?, ?)
                ");
                $stmt->execute([$userId, $data['category'] ?? 'open', $data['gender'] ?? null]);
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
        }
    }

    // ---------------------------------------------------------------
    // Login / logout
    // ---------------------------------------------------------------

    public function login(string $email, string $password, string $ip): array {
        if (!$this->checkRateLimit($ip, 'login')) {
            return ['success' => false, 'error' => 'Too many login attempts. Please try again in 15 minutes.'];
        }

        $stmt = $this->db->prepare("
            SELECT id, password_hash, account_type, is_active FROM users WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            usleep(300000); // ~0.3s — blunts timing-based user enumeration
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'error' => 'This account is deactivated.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            usleep(300000);
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        $stmt = $this->db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);

        $token = $this->generateToken((int) $user['id']);
        $this->setAuthCookie($token);

        return ['success' => true, 'user_id' => $user['id'], 'account_type' => $user['account_type']];
    }

    public function logout(): array {
        setcookie('auth_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => APP_ENV === 'production',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return ['success' => true];
    }

    private function setAuthCookie(string $token): void {
        setcookie('auth_token', $token, [
            'expires' => time() + TOKEN_EXPIRY_SECONDS,
            'path' => '/',
            'secure' => APP_ENV === 'production', // allow plain HTTP only in local dev
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    // ---------------------------------------------------------------
    // Token (hand-rolled, JWT-shaped: header.payload.signature)
    // ---------------------------------------------------------------

    public function generateToken(int $userId): string {
        $header = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->b64(json_encode([
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + TOKEN_EXPIRY_SECONDS,
        ]));
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

    private function b64(string $data): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function unb64(string $data): string {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    // ---------------------------------------------------------------
    // Profile lookup
    // ---------------------------------------------------------------

    public function getUserById(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, email, account_type, display_name, location, is_active, email_verified, created_at, last_login
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
