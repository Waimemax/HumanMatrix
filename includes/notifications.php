<?php
// Helpers for customer notifications.
// Uses the existing `notifications` table:
//   id, user_id, title, message, type, is_read, created_at
//
// None of these functions throw. A failed notification is logged and the
// order flow carries on, because an order must never fail just because a
// message could not be saved.

require_once __DIR__ . "/mailer.php";

function orderReference(int $orderId): string
{
    return "HM-" . str_pad((string) $orderId, 6, "0", STR_PAD_LEFT);
}

function createNotification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    string $type = "order",
    ?string $emailExtraHtml = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (:user_id, :title, :message, :type)
        ");
        $stmt->execute([
            ":user_id" => $userId,
            ":title"   => mb_substr($title, 0, 150),
            ":message" => $message,
            ":type"    => mb_substr($type, 0, 50),
        ]);
    } catch (PDOException $exception) {
        error_log("HumanMatrix notification error: " . $exception->getMessage());
        return;
    }

    // Also send the message by email (does nothing if email is switched off).
    emailUserAboutNotification($pdo, $userId, $title, $message, $emailExtraHtml);
}

// Send the same notification to every active administrator.
function notifyAdmins(
    PDO $pdo,
    string $title,
    string $message,
    string $type = "order"
): void {
    try {
        $admins = $pdo->query(
            "SELECT id FROM users WHERE role = 'admin' AND status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $exception) {
        error_log("HumanMatrix admin lookup error: " . $exception->getMessage());
        return;
    }

    foreach ($admins as $adminId) {
        createNotification($pdo, (int) $adminId, $title, $message, $type);
    }
}

function unreadNotificationCount(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :user_id AND is_read = 0
        ");
        $stmt->execute([":user_id" => $userId]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $exception) {
        error_log("HumanMatrix notification count error: " . $exception->getMessage());

        return 0;
    }
}

// The title and message sent to the customer when an order reaches a status.
// Returns null for statuses that need no message.
function orderStatusNotice(string $status, string $reference): ?array
{
    $notices = [
        "confirmed" => [
            "Order confirmed",
            "Your order $reference has been confirmed.",
        ],
        "processing" => [
            "Order being prepared",
            "Your order $reference is being prepared for dispatch.",
        ],
        "dispatched" => [
            "Order dispatched",
            "Your order $reference has been dispatched.",
        ],
        "in_transit" => [
            "Order on its way",
            "Your order $reference is on its way to your facility.",
        ],
        "delivered" => [
            "Order delivered",
            "Your order $reference has been delivered. Thank you for ordering with HumanMatrix.",
        ],
        "cancelled" => [
            "Order cancelled",
            "Your order $reference has been cancelled. Contact HumanMatrix if you have any questions.",
        ],
    ];

    return $notices[$status] ?? null;
}