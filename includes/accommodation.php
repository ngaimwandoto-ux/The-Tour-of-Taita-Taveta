<?php
/**
 * includes/accommodation.php
 *
 * Properties are self-serve: a "host" account registers, then submits
 * one or more properties, each requiring approval (is_public) before
 * going live — same moderation pattern as sponsor/team logos, now
 * actually wired into admin/approve.php (see getPendingProperties()/
 * setPropertyPublic() below).
 *
 * Bookings: the guest pays the FULL nightly total to TTTT's own
 * paybill via STK push (Safaricom doesn't support paying a third
 * party's number directly from this flow). platform_fee (20%) and
 * host_payout_amount (80%) are computed and stored at booking time,
 * for tracking — the guest never sees this split, only the total.
 * Paying the host their share is a MANUAL step for now (no B2C
 * automation yet) — see admin/bookings.php for the payout tracker.
 */

require_once __DIR__ . '/database.php';

class Accommodation {
    private PDO $db;
    const PLATFORM_FEE_PERCENT = 20;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ---------------------------------------------------------------
    // Properties
    // ---------------------------------------------------------------

    public function registerProperty(int $hostUserId, array $data): array {
        $required = ['name', 'region', 'category', 'capacity', 'price_per_night', 'contact_phone'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "Missing required field: $field"];
            }
        }

        $validCategories = ['homestay', 'self-contained-unit', 'campsite', 'other'];
        if (!in_array($data['category'], $validCategories, true)) {
            return ['success' => false, 'error' => 'Invalid category.'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO properties (
                host_user_id, name, region, category, description, capacity,
                price_per_night, amenities, meals_included, contact_phone
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $hostUserId,
            $data['name'],
            $data['region'],
            $data['category'],
            $data['description'] ?? '',
            (int) $data['capacity'],
            (int) $data['price_per_night'],
            $data['amenities'] ?? '',
            !empty($data['meals_included']) ? 1 : 0,
            $data['contact_phone'],
        ]);

        return ['success' => true, 'property_id' => (int) $this->db->lastInsertId()];
    }

    public function getMyProperties(int $hostUserId): array {
        $stmt = $this->db->prepare("
            SELECT id, name, region, category, price_per_night, capacity,
                   is_public, is_active, logo_path, created_at
            FROM properties WHERE host_user_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$hostUserId]);
        return $stmt->fetchAll();
    }

    /** Public, approved + active listings only — for the accommodation page. */
    public function getPublicListings(): array {
        $stmt = $this->db->query("
            SELECT id, name, region, category, description, capacity,
                   price_per_night, amenities, meals_included, contact_phone, logo_path
            FROM properties WHERE is_public = 1 AND is_active = 1
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }

    public function getProperty(int $propertyId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $property = $stmt->fetch();
        return $property ?: null;
    }

    /** Every property, regardless of approval status — for admin/approve.php. */
    public function getAllPropertiesForReview(): array {
        $stmt = $this->db->query("
            SELECT p.id, p.name, p.region, p.category, p.price_per_night,
                   p.capacity, p.is_public, p.logo_path, p.created_at,
                   u.display_name AS host_name, u.email AS host_email
            FROM properties p JOIN users u ON u.id = p.host_user_id
            ORDER BY p.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    public function setPropertyPublic(int $propertyId, bool $isPublic): void {
        $stmt = $this->db->prepare("UPDATE properties SET is_public = ? WHERE id = ?");
        $stmt->execute([$isPublic ? 1 : 0, $propertyId]);
    }

    // ---------------------------------------------------------------
    // Bookings
    // ---------------------------------------------------------------

    public function calculateSplit(int $totalAmount): array {
        $fee = (int) round($totalAmount * self::PLATFORM_FEE_PERCENT / 100);
        return ['platform_fee' => $fee, 'host_payout_amount' => $totalAmount - $fee];
    }

    public function createPendingBooking(string $checkoutRequestId, int $propertyId, string $guestName, string $guestPhone, string $checkIn, string $checkOut, int $nights, int $totalAmount): void {
        $split = $this->calculateSplit($totalAmount);
        $stmt = $this->db->prepare("
            INSERT INTO bookings (
                checkout_request_id, property_id, guest_name, guest_phone,
                check_in, check_out, nights, total_amount, platform_fee,
                host_payout_amount, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $checkoutRequestId, $propertyId, $guestName, $guestPhone,
            $checkIn, $checkOut, $nights, $totalAmount,
            $split['platform_fee'], $split['host_payout_amount'],
        ]);
    }

    /** For admin/bookings.php — paid bookings, showing payout status per host. */
    public function getPayoutQueue(): array {
        $stmt = $this->db->query("
            SELECT b.id, b.guest_name, b.check_in, b.check_out, b.total_amount,
                   b.host_payout_amount, b.payout_status, b.mpesa_receipt,
                   p.name AS property_name, u.display_name AS host_name,
                   hp.mpesa_payout_number
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users u ON u.id = p.host_user_id
            LEFT JOIN host_profiles hp ON hp.user_id = u.id
            WHERE b.status = 'paid'
            ORDER BY b.payout_status ASC, b.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    public function markPayoutSent(int $bookingId): void {
        $stmt = $this->db->prepare("UPDATE bookings SET payout_status = 'paid_out' WHERE id = ?");
        $stmt->execute([$bookingId]);
    }
}
