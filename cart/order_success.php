<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/mpesa_helper.php";
require_once "../includes/notifications.php";

$unreadCount = unreadNotificationCount($pdo, (int) ($_SESSION["user_id"] ?? 0));
$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function statusLabel(string $status): string
{
    return ucfirst(str_replace("_", " ", $status));
}

$orderId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    header("Location: ../customer/product.php");
    exit;
}

$order = null;
$items = [];
$error = false;

try {
    // Only the customer who placed the order may view it.
    $stmt = $pdo->prepare("
        SELECT id, facility_name, contact_phone, delivery_address, notes,
               payment_method, payment_status,
               total_amount, status, created_at
        FROM orders
        WHERE id = :id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ":id"      => $orderId,
        ":user_id" => (int) $_SESSION["user_id"],
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $itemStmt = $pdo->prepare("
            SELECT product_name, unit_price, quantity, subtotal
            FROM order_items
            WHERE order_id = :order_id
            ORDER BY id ASC
        ");
        $itemStmt->execute([":order_id" => $orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $exception) {
    error_log("HumanMatrix order confirmation error: " . $exception->getMessage());
    $error = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="../customer/dashboard.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="../customer/dashboard.php">Dashboard</a>
        <a href="../customer/product.php">Equipment</a>
        <a href="view.php">Cart<?= $cartCount > 0 ? " (" . $cartCount . ")" : "" ?></a>
        <a href="../customer/orders.php">My Orders</a>
        <a href="../customer/notifications.php">Notifications<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
        <a href="../customer/profile.php">Profile</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">

        <?php if ($error): ?>
            <div class="catalogue-message">
                We couldn't load your order. Please try again later.
            </div>

        <?php elseif (!$order): ?>
            <div class="catalogue-message">
                <h3>Order not found</h3>
                <p><a href="../customer/product.php">Back to equipment</a></p>
            </div>

        <?php else: ?>
            <div class="catalogue-section-heading">
                <div>
                    <h2>Thank you, your order has been placed</h2>
                    <p>
                        Order reference:
                        <strong>HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?></strong>
                        &middot; Status: <?= e(statusLabel($order["status"])) ?>
                    </p>
                </div>
            </div>

            <table style="width:100%; border-collapse:collapse; margin-bottom:24px;">
                <thead>
                    <tr style="text-align:left; border-bottom:1px solid #ccc;">
                        <th style="padding:8px;">Product</th>
                        <th style="padding:8px;">Price</th>
                        <th style="padding:8px;">Qty</th>
                        <th style="padding:8px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px;"><?= e($item["product_name"]) ?></td>
                            <td style="padding:8px;">KSh <?= number_format((float) $item["unit_price"], 2) ?></td>
                            <td style="padding:8px;"><?= (int) $item["quantity"] ?></td>
                            <td style="padding:8px;">KSh <?= number_format((float) $item["subtotal"], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="padding:8px; text-align:right;"><strong>Total</strong></td>
                        <td style="padding:8px;"><strong>KSh <?= number_format((float) $order["total_amount"], 2) ?></strong></td>
                    </tr>
                </tfoot>
            </table>

            <h3>Delivery details</h3>
            <p>
                <strong><?= e($order["facility_name"]) ?></strong><br>
                <?= e($order["contact_phone"]) ?><br>
                <?= nl2br(e($order["delivery_address"])) ?>
            </p>

            <?php if (!empty($order["notes"])): ?>
                <p><strong>Notes:</strong> <?= nl2br(e($order["notes"])) ?></p>
            <?php endif; ?>

            <h3>Payment</h3>
            <?php if ($order["payment_method"] === "before_delivery"): ?>
                <p>
                    <strong>Pay before delivery.</strong>
                    HumanMatrix will contact you on
                    <?= e($order["contact_phone"]) ?> with payment details.
                    Your order will be dispatched once payment is confirmed.
                </p>
            <?php else: ?>
                <p>
                    <strong>Pay upon delivery.</strong>
                    Please pay when the equipment is delivered to your facility.
                </p>
            <?php endif; ?>
            <p>Payment status: <?= e(ucfirst($order["payment_status"])) ?></p>

            <?php if (mpesaEnabled() && mpesaOrderPayable($order)): ?>
                <p>
                    <a href="../customer/pay_mpesa.php?order_id=<?= (int) $order["id"] ?>"
                       style="display:inline-block; background:#15803d; color:#fff; padding:10px 18px; border-radius:6px; text-decoration:none; font-weight:bold;">
                        Pay KSh <?= number_format(mpesaAmountFor($order), 2) ?> with M-Pesa now
                    </a>
                </p>
            <?php endif; ?>

            <p style="margin-top:24px;">
                <a href="../customer/order.php?id=<?= (int) $order["id"] ?>"><strong>Track this order</strong></a>
                &middot;
                <a href="../customer/orders.php">My orders</a>
                &middot;
                <a href="../customer/product.php">Continue shopping</a>
            </p>
        <?php endif; ?>

    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>