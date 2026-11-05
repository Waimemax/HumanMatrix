<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: orders.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

function backToOrder(int $orderId, string $type, string $message): void
{
    $_SESSION["order_flash"] = ["type" => $type, "message" => $message];
    header("Location: " . ($orderId > 0 ? "order.php?id=" . $orderId : "orders.php"));
    exit;
}

$orderId = filter_var($_POST["order_id"] ?? "", FILTER_VALIDATE_INT);

if ($orderId === false || $orderId < 1) {
    backToOrder(0, "error", "That order could not be found.");
}

$token = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["csrf_token"] ?? "";

if (!is_string($token) || !is_string($sessionToken) || $sessionToken === "" || !hash_equals($sessionToken, $token)) {
    backToOrder($orderId, "error", "Your session token was invalid. Please try again.");
}

$userId = (int) $_SESSION["user_id"];

try {
    $pdo->beginTransaction();

    // Lock the order, and make sure it belongs to this customer.
    $stmt = $pdo->prepare("
        SELECT id, status, payment_status, total_amount, facility_name
        FROM orders
        WHERE id = :id AND user_id = :user_id
        FOR UPDATE
    ");
    $stmt->execute([":id" => $orderId, ":user_id" => $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException("That order could not be found.");
    }

    if ($order["status"] === "cancelled") {
        throw new RuntimeException("This order has already been cancelled.");
    }

    if ($order["status"] !== "pending") {
        throw new RuntimeException(
            "This order has already been confirmed, so it can no longer be cancelled here. "
            . "Please contact HumanMatrix."
        );
    }

    if ($order["payment_status"] === "paid") {
        throw new RuntimeException(
            "Payment has already been confirmed for this order. Please contact HumanMatrix to cancel it."
        );
    }

    $update = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id AND status = 'pending'");
    $update->execute([":id" => $orderId]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException("This order could not be cancelled. Please refresh and try again.");
    }

    // Keep any delivery record in step.
    $deliveryUpdate = $pdo->prepare("UPDATE deliveries SET delivery_status = 'cancelled' WHERE order_id = :id");
    $deliveryUpdate->execute([":id" => $orderId]);

    // Put the stock back on the shelf.
    $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :id");
    $itemsStmt->execute([":id" => $orderId]);

    $restore = $pdo->prepare("
        UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id
    ");

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $restore->execute([
            ":qty" => (int) $line["quantity"],
            ":id"  => (int) $line["product_id"],
        ]);
    }

    $pdo->commit();
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    backToOrder($orderId, "error", $exception->getMessage());
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("HumanMatrix customer cancel error: " . $exception->getMessage());
    backToOrder($orderId, "error", "We couldn't cancel your order right now. Please try again.");
}

// Record it, and tell the customer and the administrators. None of this can
// undo the cancellation if it fails.
$reference = orderReference($orderId);

try {
    $log = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
        VALUES (:user_id, 'order_cancelled_by_customer', :description, :ip, :now)
    ");
    $log->execute([
        ":user_id"     => $userId,
        ":description" => "Customer cancelled order " . $reference,
        ":ip"          => mb_substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45),
        ":now"         => date("Y-m-d H:i:s"),
    ]);
} catch (PDOException $exception) {
    error_log("HumanMatrix cancel log error: " . $exception->getMessage());
}

createNotification(
    $pdo,
    $userId,
    "Order cancelled",
    "You cancelled your order " . $reference . ". The items have been returned to stock."
);

notifyAdmins(
    $pdo,
    "Order cancelled by customer",
    "Order " . $reference . " from " . ($order["facility_name"] ?? "a customer")
    . " (KES " . number_format((float) $order["total_amount"], 2) . ") was cancelled by the customer."
);

backToOrder($orderId, "success", "Your order " . $reference . " has been cancelled.");