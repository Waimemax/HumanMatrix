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
    ];

    $color = $colors[$status] ?? "#475569";

    return "display:inline-block; padding:2px 10px; border-radius:12px; "
         . "font-size:0.85em; font-weight:600; color:#fff; background:" . $color . ";";
}

$views = [
    "active"    => "In progress",
    "delivered" => "Delivered",
    "all"       => "All",
];

$view = $_GET["view"] ?? "active";
if (!is_string($view) || !isset($views[$view])) {
    $view = "active";
}

$deliveries = [];
$counts = ["active" => 0, "delivered" => 0, "all" => 0];
$error = "";
$today = date("Y-m-d");

try {
    // Only orders that have been confirmed can be scheduled for delivery.
    $countStmt = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM orders
        WHERE status IN ('confirmed', 'processing', 'dispatched', 'in_transit', 'delivered')
        GROUP BY status
    ");

    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int) $row["total"];
        $counts["all"] += $total;

        if ($row["status"] === "delivered") {
            $counts["delivered"] += $total;
        } else {
            $counts["active"] += $total;
        }
    }

    $sql = "
        SELECT o.id, o.facility_name, o.contact_phone, o.delivery_address, o.status,
               d.estimated_delivery, d.dispatch_date, d.delivered_date
        FROM orders o
        LEFT JOIN deliveries d ON d.order_id = o.id
    ";

    if ($view === "active") {
        $sql .= " WHERE o.status IN ('confirmed', 'processing', 'dispatched', 'in_transit')";
    } elseif ($view === "delivered") {
        $sql .= " WHERE o.status = 'delivered'";
    } else {
        $sql .= " WHERE o.status IN ('confirmed', 'processing', 'dispatched', 'in_transit', 'delivered')";
    }

    // Earliest expected delivery first; unscheduled orders last.
    $sql .= " ORDER BY (d.estimated_delivery IS NULL) ASC, d.estimated_delivery ASC, o.id DESC";

    $deliveries = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Admin deliveries error: " . $exception->getMessage());
    $error = "The deliveries could not be loaded. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries Management - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Deliveries Management</h1>
            <p>Track confirmed orders from preparation to delivery.</p>
        </header>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-list-section">

            <p style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <?php foreach ($views as $key => $label): ?>
                    <a href="deliveries.php?view=<?= e($key) ?>"
                       <?= $view === $key ? 'style="font-weight:700;"' : "" ?>>
                        <?= e($label) ?> (<?= (int) $counts[$key] ?>)
                    </a>
                <?php endforeach; ?>
            </p>

            <p>Showing: <strong><?= count($deliveries) ?></strong> delivery(ies)</p>

            <?php if (count($deliveries) === 0): ?>
                <p>
                    No deliveries to show. Orders appear here once you confirm them
                    on the Orders page.
                </p>
            <?php else: ?>

            <div class="table-wrapper">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Facility</th>
                            <th>Deliver to</th>
                            <th>Status</th>
                            <th>Expected</th>
                            <th>Dispatched</th>
                            <th>Delivered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deliveries as $row): ?>
                        <?php
                        $overdue = $row["status"] !== "delivered"
                            && !empty($row["estimated_delivery"])
                            && substr($row["estimated_delivery"], 0, 10) < $today;
                        ?>
                        <tr>
                            <td>
                                <strong>HM-<?= str_pad((string) $row["id"], 6, "0", STR_PAD_LEFT) ?></strong>
                            </td>
                            <td>
                                <?= e($row["facility_name"] ?? "") ?><br>
                                <small><?= e($row["contact_phone"]) ?></small>
                            </td>
                            <td><?= e($row["delivery_address"]) ?></td>
                            <td>
                                <span style="<?= e(statusBadgeStyle($row["status"])) ?>">
                                    <?= e(statusLabel($row["status"])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($row["estimated_delivery"])): ?>
                                    <?= e(date("d M Y", strtotime($row["estimated_delivery"]))) ?>
                                    <?php if ($overdue): ?>
                                        <br><small style="color:#b91c1c;"><strong>Overdue</strong></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <small>Not scheduled</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($row["dispatch_date"])
                                    ? e(date("d M Y", strtotime($row["dispatch_date"])))
                                    : "&mdash;" ?>
                            </td>
                            <td>
                                <?= !empty($row["delivered_date"])
                                    ? e(date("d M Y", strtotime($row["delivered_date"])))
                                    : "&mdash;" ?>
                            </td>
                            <td>
                                <a class="secondary-button"
                                   href="order_view.php?id=<?= (int) $row["id"] ?>">
                                    Manage
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