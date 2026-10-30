<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../customer/dashboard.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function statusBadgeStyle(string $status): string
{
    $colors = [
        "pending"   => "#b45309",
        "confirmed" => "#1d4ed8",
        "processing" => "#0e7490",
        "dispatched" => "#7c3aed",
        "in_transit" => "#6d28d9",
        "delivered" => "#15803d",
        "cancelled" => "#b91c1c",
        "paid"      => "#15803d",
        "unpaid"    => "#b45309",
    ];

    $color = $colors[$status] ?? "#475569";

    return "display:inline-block; padding:2px 10px; border-radius:12px; "
         . "font-size:0.85em; font-weight:600; color:#fff; background:" . $color . ";";
}

function paymentMethodLabel(string $method): string
{
    return $method === "before_delivery" ? "Pay before delivery" : "Pay upon delivery";
}

function statusLabel(string $status): string
{
    return ucfirst(str_replace("_", " ", $status));
}

// The deliveries table uses the same steps as orders, except that a
// "confirmed" order is still waiting for delivery to start.
function deliveryStatusFor(string $orderStatus): string
{
    return $orderStatus === "confirmed" ? "pending" : $orderStatus;
}

// Create or update the delivery record for an order. Call this inside the
// same transaction that changes the order, after the order row is locked.
function syncDelivery(PDO $pdo, int $orderId, string $orderStatus): int
{
    $orderStmt = $pdo->prepare("SELECT delivery_address FROM orders WHERE id = :id");
    $orderStmt->execute([":id" => $orderId]);
    $address = $orderStmt->fetchColumn();

    if ($address === false) {
        throw new RuntimeException("Order not found.");
    }

    $find = $pdo->prepare("SELECT id FROM deliveries WHERE order_id = :order_id LIMIT 1");
    $find->execute([":order_id" => $orderId]);
    $deliveryId = $find->fetchColumn();

    if ($deliveryId === false) {
        // A cancelled order that never had a delivery needs no record.
        if ($orderStatus === "cancelled") {
            return 0;
        }

        $insert = $pdo->prepare("
            INSERT INTO deliveries (order_id, delivery_address, delivery_status)
            VALUES (:order_id, :address, :status)
        ");
        $insert->execute([
            ":order_id" => $orderId,
            ":address"  => $address,
            ":status"   => deliveryStatusFor($orderStatus),
        ]);

        $deliveryId = (int) $pdo->lastInsertId();
    }

    $sets = ["delivery_status = :status"];

    if (in_array($orderStatus, ["dispatched", "in_transit", "delivered"], true)) {
        $sets[] = "dispatch_date = COALESCE(dispatch_date, NOW())";
    }

    if ($orderStatus === "delivered") {
        $sets[] = "delivered_date = NOW()";
    }

    $update = $pdo->prepare(
        "UPDATE deliveries SET " . implode(", ", $sets) . " WHERE id = :id"
    );
    $update->execute([
        ":status" => deliveryStatusFor($orderStatus),
        ":id"     => (int) $deliveryId,
    ]);

    return (int) $deliveryId;
}

// Which status changes are allowed from each current status.
// Delivered and cancelled orders are final.
$allowedTransitions = [
    "pending"    => ["confirmed", "cancelled"],
    "confirmed"  => ["processing", "cancelled"],
    "processing" => ["dispatched", "cancelled"],
    "dispatched" => ["in_transit", "delivered"],
    "in_transit" => ["delivered"],
    "delivered"  => [],
    "cancelled"  => [],
];

$orderId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null) {
    // Fall back to the id sent with the POST form.
    $orderId = filter_input(INPUT_POST, "order_id", FILTER_VALIDATE_INT);
}

if ($orderId === false || $orderId === null || $orderId < 1) {
    header("Location: orders.php");
    exit;
}

$error = "";

// Handle a status change or a payment confirmation.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid form submission. Please refresh and try again.";
    } else {
        $action = $_POST["action"] ?? "status";
        $newStatus = $_POST["new_status"] ?? "";

        try {
            $pdo->beginTransaction();

            // Lock the order so two admins cannot change it at once.
            $lock = $pdo->prepare("
                SELECT user_id, status, payment_method, payment_status
                FROM orders
                WHERE id = :id
                FOR UPDATE
            ");
            $lock->execute([":id" => $orderId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                throw new RuntimeException("Order not found.");
            }

            if ($action === "mark_paid") {
                if ($current["status"] === "cancelled") {
                    throw new RuntimeException("A cancelled order cannot be marked as paid.");
                }

                if ($current["payment_status"] === "paid") {
                    throw new RuntimeException("This order is already marked as paid.");
                }

                $pay = $pdo->prepare("
                    UPDATE orders
                    SET payment_status = 'paid', paid_at = NOW()
                    WHERE id = :id
                ");
                $pay->execute([":id" => $orderId]);

                $pdo->commit();

                createNotification(
                    $pdo,
                    (int) $current["user_id"],
                    "Payment received",
                    "Payment for your order " . orderReference($orderId) . " has been confirmed."
                );

                header("Location: order_view.php?id=" . $orderId . "&paid=1");
                exit;
            }

            if ($action === "set_eta") {
                if (in_array($current["status"], ["pending", "delivered", "cancelled"], true)) {
                    throw new RuntimeException(
                        "Confirm the order before scheduling delivery, and note that "
                        . "delivered or cancelled orders cannot be rescheduled."
                    );
                }

                $etaInput = trim((string) ($_POST["estimated_delivery"] ?? ""));
                $etaDate = DateTime::createFromFormat("Y-m-d", $etaInput);

                if (
                    !$etaDate ||
                    $etaDate->format("Y-m-d") !== $etaInput ||
                    $etaInput < date("Y-m-d")
                ) {
                    throw new RuntimeException(
                        "Enter a valid delivery date that is today or later."
                    );
                }

                $deliveryId = syncDelivery($pdo, $orderId, $current["status"]);

                $etaStmt = $pdo->prepare("
                    UPDATE deliveries SET estimated_delivery = :eta WHERE id = :id
                ");
                $etaStmt->execute([
                    ":eta" => $etaInput . " 00:00:00",
                    ":id"  => $deliveryId,
                ]);

                $pdo->commit();

                createNotification(
                    $pdo,
                    (int) $current["user_id"],
                    "Delivery date set",
                    "The expected delivery date for your order " . orderReference($orderId)
                    . " is " . $etaDate->format("d M Y") . "."
                );

                header("Location: order_view.php?id=" . $orderId . "&eta=1");
                exit;
            }

            // Otherwise this is a status change.
            if (
                !is_string($newStatus) ||
                !in_array($newStatus, $allowedTransitions[$current["status"]] ?? [], true)
            ) {
                throw new RuntimeException(
                    "That status change is not allowed for this order."
                );
            }

            // Pay-before-delivery orders must be paid before they ship.
            if (
                $newStatus === "dispatched" &&
                $current["payment_method"] === "before_delivery" &&
                $current["payment_status"] !== "paid"
            ) {
                throw new RuntimeException(
                    "This order is pay-before-delivery. Confirm payment before dispatching it."
                );
            }

            $update = $pdo->prepare("
                UPDATE orders SET status = :status WHERE id = :id
            ");
            $update->execute([
                ":status" => $newStatus,
                ":id"     => $orderId,
            ]);

            // Keep the delivery record in step with the order.
            syncDelivery($pdo, $orderId, $newStatus);

            // A cancelled order puts its stock back on the shelf.
            if ($newStatus === "cancelled") {
                $itemsStmt = $pdo->prepare("
                    SELECT product_id, quantity
                    FROM order_items
                    WHERE order_id = :order_id
                ");
                $itemsStmt->execute([":order_id" => $orderId]);

                $restore = $pdo->prepare("
                    UPDATE products
                    SET stock_quantity = stock_quantity + :qty
                    WHERE id = :id
                ");

                foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                    $restore->execute([
                        ":qty" => (int) $line["quantity"],
                        ":id"  => (int) $line["product_id"],
                    ]);
                }
            }

            $pdo->commit();

            $notice = orderStatusNotice($newStatus, orderReference($orderId));

            if ($notice !== null) {
                createNotification(
                    $pdo,
                    (int) $current["user_id"],
                    $notice[0],
                    $notice[1]
                );
            }

            header("Location: order_view.php?id=" . $orderId . "&updated=1");
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Admin order update error: " . $exception->getMessage());
            $error = "The order could not be updated. Please try again.";
        }
    }
}

// Load the order and its items.
$order = null;
$items = [];
$delivery = null;

try {
    $stmt = $pdo->prepare("
        SELECT id, user_id, facility_name, contact_phone, delivery_address,
               notes, payment_method, payment_status, paid_at,
               total_amount, status, created_at
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([":id" => $orderId]);
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

        $deliveryStmt = $pdo->prepare("
            SELECT delivery_status, dispatch_date, estimated_delivery, delivered_date
            FROM deliveries
            WHERE order_id = :order_id
            LIMIT 1
        ");
        $deliveryStmt->execute([":order_id" => $orderId]);
        $delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $exception) {
    error_log("Admin order view error: " . $exception->getMessage());
    $error = $error !== "" ? $error : "The order could not be loaded.";
}

$nextStatuses = $order ? ($allowedTransitions[$order["status"]] ?? []) : [];
$shippingBlocked = false;

if (
    $order &&
    $order["payment_method"] === "before_delivery" &&
    $order["payment_status"] !== "paid"
) {
    // Hide "dispatched" until payment is confirmed.
    $shippingBlocked = in_array("dispatched", $nextStatuses, true);
    $nextStatuses = array_values(array_diff($nextStatuses, ["dispatched"]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Order Details</h1>
            <p><a href="orders.php">&larr; Back to all orders</a></p>
        </header>

        <?php if (isset($_GET["updated"])): ?>
            <p class="notice success">Order status updated.</p>
        <?php elseif (isset($_GET["paid"])): ?>
            <p class="notice success">Payment confirmed.</p>
        <?php elseif (isset($_GET["eta"])): ?>
            <p class="notice success">Expected delivery date saved.</p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if (!$order): ?>
            <p>Order not found.</p>

        <?php else: ?>

        <section class="product-list-section">
            <h2>
                HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?>
                <span style="<?= e(statusBadgeStyle($order["status"])) ?>">
                    <?= e(statusLabel($order["status"])) ?>
                </span>
            </h2>

            <p>
                Placed on
                <strong><?= e(date("d M Y, H:i", strtotime($order["created_at"]))) ?></strong>
            </p>

            <h3>Facility and delivery</h3>
            <p>
                <strong><?= e($order["facility_name"]) ?></strong><br>
                <?= e($order["contact_phone"]) ?><br>
                <?= nl2br(e($order["delivery_address"])) ?>
            </p>

            <?php if (!empty($order["notes"])): ?>
                <p><strong>Customer notes:</strong> <?= nl2br(e($order["notes"])) ?></p>
            <?php endif; ?>

            <h3>Payment</h3>
            <p>
                <?= e(paymentMethodLabel($order["payment_method"])) ?>
                &middot;
                <span style="<?= e(statusBadgeStyle($order["payment_status"])) ?>">
                    <?= e(ucfirst($order["payment_status"])) ?>
                </span>
                <?php if ($order["payment_status"] === "paid" && !empty($order["paid_at"])): ?>
                    <br><small>Confirmed on
                        <?= e(date("d M Y, H:i", strtotime($order["paid_at"]))) ?></small>
                <?php endif; ?>
            </p>

            <h3>Delivery</h3>
            <p>
                <?php if (!empty($delivery["estimated_delivery"])): ?>
                    <strong>Expected:</strong>
                    <?= e(date("d M Y", strtotime($delivery["estimated_delivery"]))) ?>
                <?php else: ?>
                    <strong>Expected:</strong> not scheduled yet
                <?php endif; ?>

                <?php if (!empty($delivery["dispatch_date"])): ?>
                    <br><strong>Dispatched:</strong>
                    <?= e(date("d M Y, H:i", strtotime($delivery["dispatch_date"]))) ?>
                <?php endif; ?>

                <?php if (!empty($delivery["delivered_date"])): ?>
                    <br><strong>Delivered:</strong>
                    <?= e(date("d M Y, H:i", strtotime($delivery["delivered_date"]))) ?>
                <?php endif; ?>
            </p>

            <?php if (!in_array($order["status"], ["pending", "delivered", "cancelled"], true)): ?>
                <form method="POST"
                      action="order_view.php?id=<?= (int) $order["id"] ?>"
                      style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:16px;">
                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="order_id"
                           value="<?= (int) $order["id"] ?>">
                    <input type="hidden" name="action" value="set_eta">

                    <label for="estimated_delivery">Expected delivery date</label>
                    <input type="date" id="estimated_delivery" name="estimated_delivery"
                           min="<?= e(date("Y-m-d")) ?>"
                           value="<?= !empty($delivery["estimated_delivery"]) ? e(substr($delivery["estimated_delivery"], 0, 10)) : "" ?>"
                           required>

                    <button type="submit" class="secondary-button">Save date</button>
                </form>
            <?php elseif ($order["status"] === "pending"): ?>
                <p><small>Confirm the order to schedule its delivery.</small></p>
            <?php endif; ?>

            <h3>Items</h3>
            <div class="table-wrapper">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Unit price</th>
                            <th>Qty</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item["product_name"]) ?></td>
                            <td>KES <?= number_format((float) $item["unit_price"], 2) ?></td>
                            <td><?= (int) $item["quantity"] ?></td>
                            <td>KES <?= number_format((float) $item["subtotal"], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right;"><strong>Total</strong></td>
                            <td><strong>KES <?= number_format((float) $order["total_amount"], 2) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <?php if ($order["payment_status"] !== "paid" && $order["status"] !== "cancelled"): ?>
        <section class="product-form-section">
            <h2>Confirm payment</h2>
            <p>
                Payments are made outside the system. Once you have received
                KES <?= number_format((float) $order["total_amount"], 2) ?>
                from this facility, record it here.
            </p>
            <form method="POST"
                  action="order_view.php?id=<?= (int) $order["id"] ?>">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="order_id"
                       value="<?= (int) $order["id"] ?>">
                <input type="hidden" name="action" value="mark_paid">

                <button type="submit" class="primary-button"
                        onclick="return confirm('Confirm that payment for this order has been received?');">
                    Mark as Paid
                </button>
            </form>
        </section>
        <?php endif; ?>

        <section class="product-form-section">
            <h2>Update status</h2>

            <?php if ($shippingBlocked): ?>
                <p class="notice error">
                    This is a pay-before-delivery order. It can be dispatched only
                    after payment is confirmed above.
                </p>
            <?php endif; ?>

            <?php if (empty($nextStatuses)): ?>
                <?php if (!$shippingBlocked): ?>
                    <p>
                        This order is <strong><?= e($order["status"]) ?></strong>
                        and can no longer be changed.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST"
                      action="order_view.php?id=<?= (int) $order["id"] ?>"
                      style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="order_id"
                           value="<?= (int) $order["id"] ?>">
                    <input type="hidden" name="action" value="status">

                    <label for="new_status">Change status to</label>
                    <select id="new_status" name="new_status" required>
                        <?php foreach ($nextStatuses as $status): ?>
                            <option value="<?= e($status) ?>">
                                <?= e(statusLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="primary-button"
                            onclick="var s=document.getElementById('new_status').value;
                                     return s !== 'cancelled' || confirm('Cancel this order? The stock will be returned to inventory.');">
                        Update Status
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <?php endif; ?>

    </main>
</div>

</body>
</html>