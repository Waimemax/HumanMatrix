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

// Products at or below this many units count as low stock.
$lowStockLimit = 5;

$stats = [
    "total_orders"     => 0,
    "pending"          => 0,
    "awaiting_payment" => 0,
    "in_fulfilment"    => 0,
    "delivered"        => 0,
    "sales"            => 0.0,
    "collected"        => 0.0,
];
$customerCount = 0;
$overdueDeliveries = 0;
$lowStockCount = 0;
$lowStock = [];
$recentOrders = [];
$error = "";

try {
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status NOT IN ('cancelled', 'delivered')
                      AND payment_method = 'before_delivery'
                      AND payment_status = 'unpaid' THEN 1 ELSE 0 END) AS awaiting_payment,
            SUM(CASE WHEN status IN ('processing', 'dispatched', 'in_transit')
                     THEN 1 ELSE 0 END) AS in_fulfilment,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
            COALESCE(SUM(CASE WHEN status <> 'cancelled'
                              THEN total_amount ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' AND payment_status = 'paid'
                              THEN total_amount ELSE 0 END), 0) AS collected
        FROM orders
    ")->fetch(PDO::FETCH_ASSOC);

    foreach ($stats as $key => $default) {
        $stats[$key] = in_array($key, ["sales", "collected"], true)
            ? (float) ($row[$key] ?? 0)
            : (int) ($row[$key] ?? 0);
    }

    $customerCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'customer'"
    )->fetchColumn();

    // Deliveries that were promised for a date that has passed.
    $overdueStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders o
        INNER JOIN deliveries d ON d.order_id = o.id
        WHERE o.status IN ('confirmed', 'processing', 'dispatched', 'in_transit')
          AND d.estimated_delivery IS NOT NULL
          AND d.estimated_delivery < :today
    ");
    $overdueStmt->execute([":today" => date("Y-m-d") . " 00:00:00"]);
    $overdueDeliveries = (int) $overdueStmt->fetchColumn();

    $lowStmt = $pdo->prepare("
        SELECT id, name, stock_quantity
        FROM products
        WHERE status = 'active' AND stock_quantity <= :limit
        ORDER BY stock_quantity ASC, name ASC
        LIMIT 8
    ");
    $lowStmt->execute([":limit" => $lowStockLimit]);
    $lowStock = $lowStmt->fetchAll(PDO::FETCH_ASSOC);

    $lowCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM products
        WHERE status = 'active' AND stock_quantity <= :limit
    ");
    $lowCountStmt->execute([":limit" => $lowStockLimit]);
    $lowStockCount = (int) $lowCountStmt->fetchColumn();

    $recentOrders = $pdo->query("
        SELECT id, facility_name, total_amount, status, payment_method,
               payment_status, created_at
        FROM orders
        ORDER BY id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Admin dashboard error: " . $exception->getMessage());
    $error = "Some figures could not be loaded. Please try again.";
}

$cardStyle = "display:block; padding:18px; background:#fff; border-radius:10px; "
           . "box-shadow:0 2px 8px rgba(0,0,0,0.06); text-decoration:none; color:inherit;";
$numberStyle = "display:block; font-size:1.8em; font-weight:700; margin:4px 0;";
$labelStyle = "display:block; font-size:0.9em; color:#475569;";
$gridStyle = "display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); "
           . "gap:14px; margin-bottom:24px;";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1>Admin Dashboard</h1>
            <p>Welcome, <?= e($_SESSION["full_name"] ?? "Administrator") ?>!</p>
        </header>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section>
            <h2>Needs your attention</h2>

            <div style="<?= $gridStyle ?>">
                <a href="orders.php?status=pending" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">New orders to confirm</span>
                    <span style="<?= $numberStyle ?> color:<?= $stats["pending"] > 0 ? "#b45309" : "inherit" ?>;">
                        <?= (int) $stats["pending"] ?>
                    </span>
                </a>

                <a href="orders.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Awaiting payment (pay before delivery)</span>
                    <span style="<?= $numberStyle ?> color:<?= $stats["awaiting_payment"] > 0 ? "#b45309" : "inherit" ?>;">
                        <?= (int) $stats["awaiting_payment"] ?>
                    </span>
                </a>

                <a href="deliveries.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Overdue deliveries</span>
                    <span style="<?= $numberStyle ?> color:<?= $overdueDeliveries > 0 ? "#b91c1c" : "inherit" ?>;">
                        <?= (int) $overdueDeliveries ?>
                    </span>
                </a>

                <a href="products.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Low or out of stock (<?= (int) $lowStockLimit ?> or fewer)</span>
                    <span style="<?= $numberStyle ?> color:<?= $lowStockCount > 0 ? "#b91c1c" : "inherit" ?>;">
                        <?= (int) $lowStockCount ?>
                    </span>
                </a>
            </div>
        </section>

        <section>
            <h2>Overview</h2>

            <div style="<?= $gridStyle ?>">
                <a href="orders.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Total orders</span>
                    <span style="<?= $numberStyle ?>"><?= (int) $stats["total_orders"] ?></span>
                </a>

                <a href="deliveries.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Being prepared or delivered</span>
                    <span style="<?= $numberStyle ?>"><?= (int) $stats["in_fulfilment"] ?></span>
                </a>

                <a href="deliveries.php?view=delivered" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Delivered</span>
                    <span style="<?= $numberStyle ?>"><?= (int) $stats["delivered"] ?></span>
                </a>

                <a href="customers.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Customers</span>
                    <span style="<?= $numberStyle ?>"><?= (int) $customerCount ?></span>
                </a>

                <a href="reports.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Total sales (excl. cancelled)</span>
                    <span style="<?= $numberStyle ?> font-size:1.4em;">
                        KES <?= number_format($stats["sales"], 2) ?>
                    </span>
                </a>

                <a href="reports.php" style="<?= $cardStyle ?>">
                    <span style="<?= $labelStyle ?>">Payments confirmed</span>
                    <span style="<?= $numberStyle ?> font-size:1.4em;">
                        KES <?= number_format($stats["collected"], 2) ?>
                    </span>
                </a>
            </div>
        </section>

        <section class="product-list-section">
            <h2>Recent orders</h2>

            <?php if (empty($recentOrders)): ?>
                <p>No orders have been placed yet.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Facility</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Placed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td><strong>HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?></strong></td>
                                    <td><?= e($order["facility_name"] ?? "") ?></td>
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
                                           href="order_view.php?id=<?= (int) $order["id"] ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p><a href="orders.php">View all orders</a></p>
            <?php endif; ?>
        </section>

        <section class="product-list-section">
            <h2>Low stock</h2>

            <?php if (empty($lowStock)): ?>
                <p>All active products have more than <?= (int) $lowStockLimit ?> units in stock.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>In stock</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStock as $product): ?>
                                <tr>
                                    <td><?= e($product["name"]) ?></td>
                                    <td>
                                        <?php if ((int) $product["stock_quantity"] === 0): ?>
                                            <span class="stock-empty">Out of stock</span>
                                        <?php else: ?>
                                            <?= (int) $product["stock_quantity"] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="secondary-button"
                                           href="edit_product.php?id=<?= (int) $product["id"] ?>">Restock</a>
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