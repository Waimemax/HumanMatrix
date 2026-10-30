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
    ];

    $color = $colors[$status] ?? "#475569";

    return "display:inline-block; padding:2px 10px; border-radius:12px; "
         . "font-size:0.85em; font-weight:600; color:#fff; background:" . $color . ";";
}

function statusLabel(string $status): string
{
    return ucfirst(str_replace("_", " ", $status));
}

$statuses = ["pending", "confirmed", "processing", "dispatched", "in_transit", "delivered", "cancelled"];

// Optional status filter from the URL.
$filter = $_GET["status"] ?? "";
if (!is_string($filter) || !in_array($filter, $statuses, true)) {
    $filter = "";
}

$orders = [];
$counts = array_fill_keys($statuses, 0);
$error = "";

try {
    // Count orders per status for the filter tabs.
    $countStmt = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM orders
        GROUP BY status
    ");

    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row["status"]] = (int) $row["total"];
    }

    $sql = "
        SELECT o.id, o.facility_name, o.contact_phone, o.total_amount,
               o.payment_method, o.payment_status,
               o.status, o.created_at,
               (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
        FROM orders o
    ";

    $params = [];

    if ($filter !== "") {
        $sql .= " WHERE o.status = :status";
        $params[":status"] = $filter;
    }

    $sql .= " ORDER BY o.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Admin orders list error: " . $exception->getMessage());
    $error = "The orders could not be loaded. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Orders Management</h1>
            <p>Review customer orders and update their status.</p>
        </header>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-list-section">

            <p style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <a href="orders.php"
                   <?= $filter === "" ? 'style="font-weight:700;"' : "" ?>>
                    All (<?= array_sum($counts) ?>)
                </a>

                <?php foreach ($statuses as $status): ?>
                    <a href="orders.php?status=<?= e($status) ?>"
                       <?= $filter === $status ? 'style="font-weight:700;"' : "" ?>>
                        <?= e(statusLabel($status)) ?> (<?= $counts[$status] ?>)
                    </a>
                <?php endforeach; ?>
            </p>

            <p>Showing: <strong><?= count($orders) ?></strong> order(s)</p>

            <?php if (count($orders) === 0): ?>
                <p>No orders found.</p>
            <?php else: ?>

            <div class="table-wrapper">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Facility</th>
                            <th>Phone</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Placed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <strong>HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?></strong>
                            </td>
                            <td><?= e($order["facility_name"]) ?></td>
                            <td><?= e($order["contact_phone"]) ?></td>
                            <td><?= (int) $order["item_count"] ?></td>
                            <td>KES <?= number_format((float) $order["total_amount"], 2) ?></td>
                            <td>
                                <?= $order["payment_method"] === "before_delivery" ? "Before delivery" : "On delivery" ?><br>
                                <small><strong><?= $order["payment_status"] === "paid" ? "Paid" : "Unpaid" ?></strong></small>
                            </td>
                            <td>
                                <span style="<?= e(statusBadgeStyle($order["status"])) ?>">
                                    <?= e(statusLabel($order["status"])) ?>
                                </span>
                            </td>
                            <td><?= e(date("d M Y, H:i", strtotime($order["created_at"]))) ?></td>
                            <td>
                                <a class="secondary-button"
                                   href="order_view.php?id=<?= (int) $order["id"] ?>">
                                    View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>
        </section>

    </main>
</div>

</body>
</html>