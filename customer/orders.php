<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

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

$userId = (int) $_SESSION["user_id"];
$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;
$unreadCount = unreadNotificationCount($pdo, (int) $_SESSION["user_id"]);

// Filter tabs.
$filters = [
    "all"       => "All",
    "active"    => "In progress",
    "delivered" => "Delivered",
    "cancelled" => "Cancelled",
];

$filter = $_GET["view"] ?? "all";
if (!is_string($filter) || !isset($filters[$filter])) {
    $filter = "all";
}

$orders = [];
$counts = ["all" => 0, "active" => 0, "delivered" => 0, "cancelled" => 0];
$error = false;

try {
    $countStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM orders
        WHERE user_id = :user_id
        GROUP BY status
    ");
    $countStmt->execute([":user_id" => $userId]);

    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int) $row["total"];
        $counts["all"] += $total;

        if ($row["status"] === "delivered") {
            $counts["delivered"] += $total;
        } elseif ($row["status"] === "cancelled") {
            $counts["cancelled"] += $total;
        } else {
            $counts["active"] += $total;
        }
    }

    $sql = "
        SELECT o.id, o.facility_name, o.total_amount, o.status,
               o.payment_method, o.payment_status, o.created_at,
               d.estimated_delivery, d.delivered_date
        FROM orders o
        LEFT JOIN deliveries d ON d.order_id = o.id
        WHERE o.user_id = :user_id
    ";

    if ($filter === "active") {
        $sql .= " AND o.status NOT IN ('delivered', 'cancelled')";
    } elseif ($filter === "delivered") {
        $sql .= " AND o.status = 'delivered'";
    } elseif ($filter === "cancelled") {
        $sql .= " AND o.status = 'cancelled'";
    }

    $sql .= " ORDER BY o.id DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":user_id" => $userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("HumanMatrix My Orders error: " . $exception->getMessage());
    $error = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders | HumanMatrix</title>
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
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">
        <div class="catalogue-section-heading">
            <div>
                <h2>My Orders</h2>
                <p>Every order you have placed and where it stands.</p>
            </div>
        </div>

        <p style="display:flex; gap:14px; flex-wrap:wrap;">
            <?php foreach ($filters as $key => $label): ?>
                <a href="orders.php?view=<?= e($key) ?>"
                   <?= $filter === $key ? 'style="font-weight:700;"' : "" ?>>
                    <?= e($label) ?> (<?= (int) $counts[$key] ?>)
                </a>
            <?php endforeach; ?>
        </p>

        <?php if ($error): ?>
            <div class="catalogue-message">
                We couldn't load your orders. Please try again later.
            </div>

        <?php elseif (empty($orders)): ?>
            <div class="catalogue-message">
                <h3>No orders to show</h3>
                <p>
                    <?= $filter === "all"
                        ? "You haven't placed any orders yet."
                        : "You have no orders in this view." ?>
                    <a href="product.php">Browse equipment</a>
                </p>
            </div>

        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #ccc;">
                            <th style="padding:8px;">Order</th>
                            <th style="padding:8px;">Placed</th>
                            <th style="padding:8px;">Facility</th>
                            <th style="padding:8px;">Total</th>
                            <th style="padding:8px;">Payment</th>
                            <th style="padding:8px;">Status</th>
                            <th style="padding:8px;">Expected delivery</th>
                            <th style="padding:8px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:8px;">
                                    <strong>HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?></strong>
                                </td>
                                <td style="padding:8px;">
                                    <?= e(date("d M Y", strtotime($order["created_at"]))) ?>
                                </td>
                                <td style="padding:8px;"><?= e($order["facility_name"] ?? "") ?></td>
                                <td style="padding:8px;">
                                    KSh <?= number_format((float) $order["total_amount"], 2) ?>
                                </td>
                                <td style="padding:8px;">
                                    <?= $order["payment_method"] === "before_delivery" ? "Before delivery" : "On delivery" ?><br>
                                    <span style="<?= e(statusBadgeStyle($order["payment_status"])) ?>">
                                        <?= e(ucfirst($order["payment_status"])) ?>
                                    </span>
                                </td>
                                <td style="padding:8px;">
                                    <span style="<?= e(statusBadgeStyle($order["status"])) ?>">
                                        <?= e(statusLabel($order["status"])) ?>
                                    </span>
                                </td>
                                <td style="padding:8px;">
                                    <?php if ($order["status"] === "delivered" && !empty($order["delivered_date"])): ?>
                                        Delivered <?= e(date("d M Y", strtotime($order["delivered_date"]))) ?>
                                    <?php elseif ($order["status"] === "cancelled"): ?>
                                        &mdash;
                                    <?php elseif (!empty($order["estimated_delivery"])): ?>
                                        <?= e(date("d M Y", strtotime($order["estimated_delivery"]))) ?>
                                    <?php else: ?>
                                        Not scheduled yet
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px;">
                                    <a href="order.php?id=<?= (int) $order["id"] ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>