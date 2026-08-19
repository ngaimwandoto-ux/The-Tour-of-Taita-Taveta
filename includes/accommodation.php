<?php
/**
 * includes/accommodation.php
 *
 * Properties are self-serve: a "host" account registers, then submits
 * properties, each requiring approval before going live.
 *
 * Bookings: guest pays the FULL total to TTTT's paybill via STK push.
 * platform_fee (20%) / host_payout_amount (80%) are computed and
 * stored for tracking. Paying hosts, and refunding guests, are BOTH
 * manual steps — nothing in this file or anywhere in the app sends
 * real M-Pesa money except the original STK push. Every "mark as
 * paid/refunded" action requires a person to click it after they've
 * actually sent the money — there is deliberately no batch or
 * unattended action that flips these statuses on its own.
 *
 * CANCELLATION POLICY BELOW IS A PLACEHOLDER — same as the ad-calculator
 * prices elsewhere on the site. Confirm real numbers before launch.
 */

require_once __DIR__ . '/database.php';

class Accommodation {
    private PDO $db;
    const PLATFORM_FEE_PERCENT = 20;

    // PLACEHOLDER cancellation policy — confirm before launch.
    const REFUND_FULL_DAYS_BEFORE = 5;   // 100% refund if cancelled this many days+ before check-in
    const REFUND_PARTIAL_DAYS_BEFORE = 2; // 50% refund if cancelled this many days+ before check-in
    const REFUND_PARTIAL_PERCENT = 50;

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
            $hostUserId, $data['name'], $data['region'], $data['category'],
            $data['description'] ?? '', (int) $data['capacity'], (int) $data['price_per_night'],
            $data['amenities'] ?? '', !empty($data['meals_included']) ? 1 : 0, $data['contact_phone'],
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
        return $stmt->fetch() ?: null;
    }

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
    // Bookings — creation
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

    // ---------------------------------------------------------------
    // Cancellation & refund — tracked, never auto-completed
    // ---------------------------------------------------------------

    /** PLACEHOLDER policy — see class constants above. */
    public function getCancellationRefundPercent(string $checkInDate): int {
        $now = new DateTime('today');
        $checkIn = DateTime::createFromFormat('Y-m-d', $checkInDate);
        if (!$checkIn) return 0;

        $daysUntilCheckIn = (int) $now->diff($checkIn)->days;
        if ($checkIn < $now) return 0; // already past check-in

        if ($daysUntilCheckIn >= self::REFUND_FULL_DAYS_BEFORE) return 100;
        if ($daysUntilCheckIn >= self::REFUND_PARTIAL_DAYS_BEFORE) return self::REFUND_PARTIAL_PERCENT;
        return 0;
    }

    /**
     * Cancels a booking and records what refund (if any) is owed.
     * Does NOT send any money — refund_status becomes "owed", visible
     * in admin/bookings.php, until someone sends it manually and marks
     * it "sent".
     */
    public function processCancellation(int $bookingId, string $reason, string $initiatedBy): array {
        if (!in_array($initiatedBy, ['guest', 'host', 'admin'], true)) {
            return ['success' => false, 'error' => 'Invalid initiator.'];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM bookings WHERE id = ? AND status IN ('pending', 'paid')
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            return ['success' => false, 'error' => 'Booking not found, or already cancelled/refunded.'];
        }

        $refundPercent = ($booking['status'] === 'paid')
            ? $this->getCancellationRefundPercent($booking['check_in'])
            : 0; // an unpaid ("pending") booking has nothing to refund — it was never collected
        $refundAmount = (int) round($booking['total_amount'] * $refundPercent / 100);
        $refundStatus = $refundAmount > 0 ? 'owed' : 'not_applicable';

        $stmt = $this->db->prepare("
            UPDATE bookings SET
                status = 'cancelled',
                refund_amount = ?,
                refund_status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$refundAmount, $refundStatus, $bookingId]);

        $stmt = $this->db->prepare("
            INSERT INTO booking_events (booking_id, event_type, amount, reason, initiated_by)
            VALUES (?, 'cancellation', ?, ?, ?)
        ");
        $stmt->execute([$bookingId, $refundAmount, $reason, $initiatedBy]);

        return [
            'success' => true,
            'booking_id' => $bookingId,
            'refund_percent' => $refundPercent,
            'refund_amount' => $refundAmount,
            'message' => $refundAmount > 0
                ? "Booking cancelled. KES {$refundAmount} is owed back to the guest — this needs to be sent manually and marked as sent in admin."
                : 'Booking cancelled. No refund applies under the current policy.',
        ];
    }

    /** Bookings where a refund is owed but hasn't been sent yet — for admin. */
    public function getRefundsOwed(): array {
        $stmt = $this->db->query("
            SELECT b.id, b.guest_name, b.guest_phone, b.check_in, b.check_out,
                   b.total_amount, b.refund_amount, b.mpesa_receipt,
                   p.name AS property_name
            FROM bookings b JOIN properties p ON p.id = b.property_id
            WHERE b.refund_status = 'owed'
            ORDER BY b.updated_at ASC
        ");
        return $stmt->fetchAll();
    }

    /** Call only after you've actually sent the refund. */
    public function markRefundSent(int $bookingId): void {
        $stmt = $this->db->prepare("UPDATE bookings SET refund_status = 'sent', status = 'refunded', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$bookingId]);
        $booking = $this->getBookingAmount($bookingId);
        $stmt = $this->db->prepare("INSERT INTO booking_events (booking_id, event_type, amount, initiated_by) VALUES (?, 'refund_sent', ?, 'admin')");
        $stmt->execute([$bookingId, $booking['refund_amount'] ?? 0]);
    }

    private function getBookingAmount(int $bookingId): ?array {
        $stmt = $this->db->prepare("SELECT refund_amount FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        return $stmt->fetch() ?: null;
    }

    // ---------------------------------------------------------------
    // Host payouts — tracked, never auto-completed
    // ---------------------------------------------------------------

    /** Individual paid bookings still owing a host payout — for admin/bookings.php. */
    public function getPayoutQueue(): array {
        $stmt = $this->db->query("
            SELECT b.id, b.guest_name, b.check_in, b.check_out, b.total_amount,
                   b.host_payout_amount, b.payout_status, b.mpesa_receipt,
                   p.id AS property_id, p.name AS property_name,
                   u.id AS host_user_id, u.display_name AS host_name,
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

    /** Same data, grouped by host — for a "pay this host's total, then confirm" view. */
    public function getPayoutSummaryByHost(): array {
        $stmt = $this->db->query("
            SELECT
                u.id AS host_user_id,
                u.display_name AS host_name,
                hp.mpesa_payout_number,
                COUNT(b.id) AS booking_count,
                SUM(b.host_payout_amount) AS total_owed
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users u ON u.id = p.host_user_id
            LEFT JOIN host_profiles hp ON hp.user_id = u.id
            WHERE b.status = 'paid' AND b.payout_status = 'not_paid'
            GROUP BY u.id
            ORDER BY total_owed DESC
        ");
        return $stmt->fetchAll();
    }

    /** Call only after you've actually sent this one booking's payout. */
    public function markPayoutSent(int $bookingId): void {
        $stmt = $this->db->prepare("SELECT host_payout_amount FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        $amount = $stmt->fetch()['host_payout_amount'] ?? 0;

        $stmt = $this->db->prepare("UPDATE bookings SET payout_status = 'paid_out', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$bookingId]);

        $stmt = $this->db->prepare("INSERT INTO booking_events (booking_id, event_type, amount, initiated_by) VALUES (?, 'payout_sent', ?, 'admin')");
        $stmt->execute([$bookingId, $amount]);
    }

    /**
     * Call only after you've actually sent ONE host their full total —
     * marks every one of that host's outstanding bookings paid at once,
     * so you're not clicking N times for N bookings you just paid in
     * one M-Pesa transaction. Still requires you to pick a specific
     * host and see their exact total first — never "everyone, blindly".
     */
    public function markAllPayoutsSentForHost(int $hostUserId): int {
        $stmt = $this->db->prepare("
            SELECT id, host_payout_amount FROM bookings b
            JOIN properties p ON p.id = b.property_id
            WHERE p.host_user_id = ? AND b.status = 'paid' AND b.payout_status = 'not_paid'
        ");
        $stmt->execute([$hostUserId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->markPayoutSent((int) $row['id']);
        }
        return count($rows);
    }
}
