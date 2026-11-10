<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/notifications.php";
require_once "../includes/mpesa_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// A message left by cancel_order.php.
$flash = $_SESSION["order_flash"] ?? null;
unset($_SESSION["order_flash"]);

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function statusLabel(string $status): string
{
    return ucfirst(str_replace("_", " ", $status));
}

function statusBadgeStyle(string $status): string
{
    $colors = [
        "pending"    => "#b45309",
        "confirmed"  => "#1d4ed8",
        "processing" => "#0e7490",
        "dispatched" => "#7c3aed",
        "in_transit" => "#6d28d9",
        "delivered"  => "#15803d",
        "cancelled"  => "#b91c1c",
        "paid"       => "#15803d",
        "unpaid"     => "#b45309",
    ];

    $color = $colors[$status] ?? "#475569";

    return "display:inline-block; padding:2px 10px; border-radius:12px; "
         . "font-size:0.85em; font-weight:600; color:#fff; background:" . $color . ";";
}

$orderId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    header("Location: orders.php");
    exit;
}

$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;
$unreadCount = unreadNotificationCount($pdo, (int) $_SESSION["user_id"]);

$order = null;
$mpesaReceipt = "";
$items = [];
$delivery = null;
$error = false;

try {
    // Only the customer who placed the order may view it.
    $stmt = $pdo->prepare("
        SELECT id, facility_name, contact_phone, delivery_address, notes,
               payment_method, payment_status, paid_at,
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
            SELECT COALESCE(NULLIF(i.product_name, ''), p.name) AS name,
                   i.unit_price, i.quantity, i.subtotal
            FROM order_items i
            LEFT JOIN products p ON p.id = i.product_id
            WHERE i.order_id = :order_id
            ORDER BY i.id ASC
        ");
        $itemStmt->execute([":order_id" => $orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $deliveryStmt = $pdo->prepare("
            SELECT dispatch_date, estimated_delivery, delivered_date
            FROM deliveries
            WHERE order_id = :order_id
            LIMIT 1
        ");
        $deliveryStmt->execute([":order_id" => $orderId]);
        $delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // M-Pesa receipt, if this order was paid that way. The page still works
        // if the mpesa_payments table has not been created yet.
        try {
            $receiptStmt = $pdo->prepare("
                SELECT mpesa_receipt FROM mpesa_payments
                WHERE order_id = :order_id AND status = 'success'
                ORDER BY id DESC LIMIT 1
            ");
            $receiptStmt->execute([":order_id" => $orderId]);
            $mpesaReceipt = $receiptStmt->fetchColumn() ?: "";
        } catch (PDOException $mpesaException) {
            $mpesaReceipt = "";
        }
    }
} catch (PDOException $exception) {
    error_log("HumanMatrix customer order view error: " . $exception->getMessage());
    $error = true;
}

// The steps an order moves through.
$steps = [
    "pending"    => "Order placed",
    "confirmed"  => "Confirmed",
    "processing" => "Processing",
    "dispatched" => "Dispatched",
    "in_transit" => "In transit",
    "delivered"  => "Delivered",
];

$stepKeys = array_keys($steps);
$currentIndex = $order ? array_search($order["status"], $stepKeys, true) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="../index.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="product.php">Equipment</a>
        <a href="../cart/view.php">Cart<?= $cartCount > 0 ? " (" . $cartCount . ")" : "" ?></a>
        <a class="active" href="orders.php">My Orders</a>
        <a href="notifications.php">Notifications<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
        <a href="profile.php">Profile</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">

        <?php if ($error): ?>
            <div class="catalogue-message">
                We couldn't load this order. Please try again later.
            </div>

        <?php elseif (!$order): ?>
            <div class="catalogue-message">
                <h3>Order not found</h3>
                <p><a href="orders.php">Back to my orders</a></p>
            </div>

        <?php else: ?>

            <?php if (is_array($flash) && !empty($flash["message"])): ?>
                <div class="catalogue-message" role="alert"
                     style="<?= ($flash["type"] ?? "") === "success" ? "border-left:4px solid #15803d;" : "border-left:4px solid #b91c1c;" ?>">
                    <?= e($flash["message"]) ?>
                </div>
            <?php endif; ?>

            <div class="catalogue-section-heading">
                <div>
                    <h2>
                        Order HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?>
                        <span style="<?= e(statusBadgeStyle($order["status"])) ?>">
                            <?= e(statusLabel($order["status"])) ?>
                        </span>
                    </h2>
                    <p>
                        Placed on <?= e(date("d M Y, H:i", strtotime($order["created_at"]))) ?>
                        &middot; <a href="orders.php">Back to my orders</a>
                        &middot; <a href="invoice.php?id=<?= (int) $order["id"] ?>" target="_blank">Print invoice</a>
                    </p>
                </div>
            </div>

            <h3>Progress</h3>

            <?php if ($order["status"] === "cancelled"): ?>
                <div class="catalogue-message">
                    This order was cancelled. If you did not expect this,
                    please contact HumanMatrix.
                </div>
            <?php else: ?>
                <ol style="list-style:none; padding:0; margin:0 0 20px; display:flex; flex-wrap:wrap; gap:10px;">
                    <?php foreach ($stepKeys as $index => $key): ?>
                        <?php
                        $done = $currentIndex !== false && $index < $currentIndex;
                        $current = $currentIndex !== false && $index === $currentIndex;

                        $background = $current ? "#2476a8" : ($done ? "#15803d" : "#e5eaf0");
                        $color = ($current || $done) ? "#fff" : "#475569";
                        $mark = $done ? "&#10003; " : ($current ? "&#9679; " : "");
                        ?>
                        <li style="padding:6px 12px; border-radius:16px; font-size:0.9em; font-weight:600; background:<?= $background ?>; color:<?= $color ?>;">
                            <?= $mark ?><?= e($steps[$key]) ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <h3>Delivery</h3>
            <p>
                <strong><?= e($order["facility_name"] ?? "") ?></strong><br>
                <?= e($order["contact_phone"]) ?><br>
                <?= nl2br(e($order["delivery_address"])) ?>
            </p>

            <p>
                <?php if ($order["status"] === "delivered" && !empty($delivery["delivered_date"])): ?>
                    <strong>Delivered on</strong>
                    <?= e(date("d M Y", strtotime($delivery["delivered_date"]))) ?>
                <?php elseif ($order["status"] === "cancelled"): ?>
                    <strong>Delivery:</strong> cancelled
                <?php elseif (!empty($delivery["estimated_delivery"])): ?>
                    <strong>Expected delivery:</strong>
                    <?= e(date("d M Y", strtotime($delivery["estimated_delivery"]))) ?>
                <?php else: ?>
                    <strong>Expected delivery:</strong> not scheduled yet.
                    HumanMatrix will set a date once your order is confirmed.
                <?php endif; ?>

                <?php if (!empty($delivery["dispatch_date"])): ?>
                    <br><strong>Dispatched on</strong>
                    <?= e(date("d M Y", strtotime($delivery["dispatch_date"]))) ?>
                <?php endif; ?>
            </p>

            <?php if (!empty($order["notes"])): ?>
                <p><strong>Your notes:</strong> <?= nl2br(e($order["notes"])) ?></p>
            <?php endif; ?>

            <h3>Payment</h3>
            <p>
                <?= $order["payment_method"] === "before_delivery"
                    ? "Pay before delivery"
                    : "Pay upon delivery" ?>
                &middot;
                <span style="<?= e(statusBadgeStyle($order["payment_status"])) ?>">
                    <?= e(ucfirst($order["payment_status"])) ?>
                </span>
                <?php if ($order["payment_status"] === "paid" && !empty($order["paid_at"])): ?>
                    <br><small>Confirmed on
                        <?= e(date("d M Y", strtotime($order["paid_at"]))) ?></small>
                    <?php if ($mpesaReceipt !== ""): ?>
                        <br><small>M-Pesa receipt: <strong><?= e($mpesaReceipt) ?></strong></small>
                    <?php endif; ?>
                <?php elseif ($order["payment_method"] === "before_delivery" && $order["status"] !== "cancelled"): ?>
                    <br><small>Your order is dispatched once payment is confirmed.</small>
                <?php endif; ?>
            </p>

            <?php if (mpesaEnabled() && mpesaOrderPayable($order)): ?>
                <p>
                    <a href="pay_mpesa.php?order_id=<?= (int) $order["id"] ?>"
                       style="display:inline-block; background:#15803d; color:#fff; padding:10px 18px; border-radius:6px; text-decoration:none; font-weight:bold;">
                        Pay KSh <?= number_format(mpesaAmountFor($order), 2) ?> with M-Pesa
                    </a>
                </p>
            <?php endif; ?>

            <h3>Items</h3>
            <div style="overflow-x:auto;">
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
                                <td style="padding:8px;"><?= e($item["name"] ?? "") ?></td>
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
            </div>

            <?php if ($order["status"] === "pending" && $order["payment_status"] !== "paid"): ?>
                <form method="POST" action="cancel_order.php"
                      onsubmit="return confirm('Cancel this order? The items will be returned to stock and this cannot be undone.');"
                      style="margin:0 0 20px;">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">
                    <button type="submit" style="background:#b91c1c;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">
                        Cancel this order
                    </button>
                    <small style="display:block;margin-top:6px;color:#475569;">
                        You can cancel while the order is still pending. After HumanMatrix confirms it, contact us instead.
                    </small>
                </form>
            <?php endif; ?>

            <p><a href="product.php">Continue shopping</a></p>

        <?php endif; ?>

    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>