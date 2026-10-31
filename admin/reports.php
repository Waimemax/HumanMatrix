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

// Stop spreadsheet programs from running a cell as a formula.
function csvSafe($value): string
{
    $text = (string) $value;

    if ($text !== "" && in_array($text[0], ["=", "+", "-", "@", "\t", "\r"], true)) {
        return "'" . $text;
    }

    return $text;
}

function validDate($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $date = DateTime::createFromFormat("Y-m-d", $value);

    return ($date && $date->format("Y-m-d") === $value) ? $value : null;
}

$lowStockLimit = 5;

// Date range: this month so far unless the admin chooses another.
$from = validDate($_GET["from"] ?? null) ?? date("Y-m-01");
$to = validDate($_GET["to"] ?? null) ?? date("Y-m-d");
$error = "";

if ($from > $to) {
    $error = "The start date must be on or before the end date.";
    $from = date("Y-m-01");
    $to = date("Y-m-d");
}

$rangeStart = $from . " 00:00:00";
// Exclusive upper bound: the start of the day after "to".
$rangeEnd = date("Y-m-d", strtotime($to . " +1 day")) . " 00:00:00";
$rangeParams = [":start" => $rangeStart, ":end" => $rangeEnd];

// CSV export of the orders in the range.
if (($_GET["export"] ?? "") === "orders") {
    try {
        $stmt = $pdo->prepare("
            SELECT id, created_at, facility_name, contact_phone, status,
                   payment_method, payment_status, total_amount
            FROM orders
            WHERE created_at >= :start AND created_at < :end
            ORDER BY id ASC
        ");
        $stmt->execute($rangeParams);

        header("Content-Type: text/csv; charset=utf-8");
        header('Content-Disposition: attachment; filename="orders_' . $from . '_to_' . $to . '.csv"');

        $out = fopen("php://output", "w");
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
        fputcsv($out, ["Order", "Placed", "Facility", "Phone", "Status",
                       "Payment method", "Payment status", "Total (KES)"]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($out, [
                "HM-" . str_pad((string) $row["id"], 6, "0", STR_PAD_LEFT),
                $row["created_at"],
                csvSafe($row["facility_name"] ?? ""),
                csvSafe($row["contact_phone"]),
                statusLabel($row["status"]),
                $row["payment_method"] === "before_delivery" ? "Before delivery" : "On delivery",
                ucfirst($row["payment_status"]),
                number_format((float) $row["total_amount"], 2, ".", ""),
            ]);
        }

        fclose($out);
        exit;
    } catch (PDOException $exception) {
        error_log("Admin report export error: " . $exception->getMessage());
        $error = "The export could not be created. Please try again.";
    }
}

$summary = [
    "orders" => 0, "cancelled" => 0, "sales" => 0.0,
    "collected" => 0.0, "outstanding" => 0.0,
];
$byStatus = [];
$topProducts = [];
$topFacilities = [];
$salesByPeriod = [];
$periodLabel = "Day";
$stock = ["active" => 0, "out" => 0, "low" => 0];

try {
    $row = $pdo->prepare("
        SELECT
            COUNT(*) AS orders_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(SUM(CASE WHEN status <> 'cancelled'
                              THEN total_amount ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' AND payment_status = 'paid'
                              THEN total_amount ELSE 0 END), 0) AS collected,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' AND payment_status = 'unpaid'
                              THEN total_amount ELSE 0 END), 0) AS outstanding
        FROM orders
        WHERE created_at >= :start AND created_at < :end
    ");
    $row->execute($rangeParams);
    $r = $row->fetch(PDO::FETCH_ASSOC);

    $summary = [
        "orders"      => (int) ($r["orders_count"] ?? 0),
        "cancelled"   => (int) ($r["cancelled"] ?? 0),
        "sales"       => (float) ($r["sales"] ?? 0),
        "collected"   => (float) ($r["collected"] ?? 0),
        "outstanding" => (float) ($r["outstanding"] ?? 0),
    ];

    $statusStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS amount
        FROM orders
        WHERE created_at >= :start AND created_at < :end
        GROUP BY status
        ORDER BY total DESC
    ");
    $statusStmt->execute($rangeParams);
    $byStatus = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    $productStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(MAX(i.product_name), ''), MAX(p.name)) AS name,
               SUM(i.quantity) AS units,
               SUM(i.subtotal) AS revenue
        FROM order_items i
        INNER JOIN orders o ON o.id = i.order_id
        LEFT JOIN products p ON p.id = i.product_id
        WHERE o.created_at >= :start AND o.created_at < :end
          AND o.status <> 'cancelled'
        GROUP BY i.product_id
        ORDER BY units DESC, revenue DESC
        LIMIT 10
    ");
    $productStmt->execute($rangeParams);
    $topProducts = $productStmt->fetchAll(PDO::FETCH_ASSOC);

    $facilityStmt = $pdo->prepare("
        SELECT COALESCE(o.facility_name, 'Unknown') AS facility,
               COUNT(*) AS orders_count,
               SUM(o.total_amount) AS amount
        FROM orders o
        WHERE o.created_at >= :start AND o.created_at < :end
          AND o.status <> 'cancelled'
        GROUP BY COALESCE(o.facility_name, 'Unknown')
        ORDER BY amount DESC
        LIMIT 10
    ");
    $facilityStmt->execute($rangeParams);
    $topFacilities = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by day for short ranges and by month for long ones.
    $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    $length = $days > 62 ? 7 : 10;
    $periodLabel = $days > 62 ? "Month" : "Day";

    $periodStmt = $pdo->prepare("
        SELECT SUBSTR(created_at, 1, $length) AS period,
               COUNT(*) AS orders_count,
               SUM(total_amount) AS amount
        FROM orders
        WHERE created_at >= :start AND created_at < :end
          AND status <> 'cancelled'
        GROUP BY SUBSTR(created_at, 1, $length)
        ORDER BY period ASC
    ");
    $periodStmt->execute($rangeParams);
    $salesByPeriod = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Inventory is a snapshot of right now, not of the date range.
    $stockStmt = $pdo->prepare("
        SELECT COUNT(*) AS active_total,
               SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_total,
               SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= :limit
                        THEN 1 ELSE 0 END) AS low_total
        FROM products
        WHERE status = 'active'
    ");
    $stockStmt->execute([":limit" => $lowStockLimit]);
    $s = $stockStmt->fetch(PDO::FETCH_ASSOC);

    $stock = [
        "active" => (int) ($s["active_total"] ?? 0),
        "out"    => (int) ($s["out_total"] ?? 0),
        "low"    => (int) ($s["low_total"] ?? 0),
    ];
} catch (PDOException $exception) {
    error_log("Admin reports error: " . $exception->getMessage());
    $error = $error !== "" ? $error : "The report could not be loaded. Please try again.";
}

$exportQuery = http_build_query(["from" => $from, "to" => $to, "export" => "orders"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Reports</h1>
            <p>Sales, orders, products and stock for the period you choose.</p>
        </header>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-form-section">
            <form method="GET" action="reports.php"
                  style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <div class="form-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= e($from) ?>"
                           max="<?= e(date("Y-m-d")) ?>" required>
                </div>

                <div class="form-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= e($to) ?>"
                           max="<?= e(date("Y-m-d")) ?>" required>
                </div>

                <button type="submit" class="primary-button">Show report</button>
                <a class="secondary-button" href="reports.php?<?= e($exportQuery) ?>">
                    Download orders (CSV)
                </a>
            </form>
        </section>

        <section class="product-list-section">
            <h2>Summary</h2>
            <p>
                <?= e(date("d M Y", strtotime($from))) ?> &ndash;
                <?= e(date("d M Y", strtotime($to))) ?>
            </p>

            <div class="table-wrapper">
                <table class="product-table">
                    <tbody>
                        <tr><td>Orders placed</td><td><strong><?= (int) $summary["orders"] ?></strong></td></tr>
                        <tr><td>Cancelled orders</td><td><?= (int) $summary["cancelled"] ?></td></tr>
                        <tr><td>Sales (excluding cancelled)</td><td><strong>KES <?= number_format($summary["sales"], 2) ?></strong></td></tr>
                        <tr><td>Payments confirmed</td><td>KES <?= number_format($summary["collected"], 2) ?></td></tr>
                        <tr><td>Payments outstanding</td><td>KES <?= number_format($summary["outstanding"], 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="product-list-section">
            <h2>Orders by status</h2>

            <?php if (empty($byStatus)): ?>
                <p>No orders in this period.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="product-table">
                        <thead>
                            <tr><th>Status</th><th>Orders</th><th>Value</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byStatus as $row): ?>
                                <tr>
                                    <td><?= e(statusLabel($row["status"])) ?></td>
                                    <td><?= (int) $row["total"] ?></td>
                                    <td>KES <?= number_format((float) $row["amount"], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="product-list-section">
            <h2>Sales by <?= e(strtolower($periodLabel)) ?></h2>

            <?php if (empty($salesByPeriod)): ?>
                <p>No sales in this period.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="product-table">
                        <thead>
                            <tr><th><?= e($periodLabel) ?></th><th>Orders</th><th>Sales</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($salesByPeriod as $row): ?>
                                <tr>
                                    <td><?= e($row["period"]) ?></td>
                                    <td><?= (int) $row["orders_count"] ?></td>
                                    <td>KES <?= number_format((float) $row["amount"], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="product-list-section">
            <h2>Top products</h2>

            <?php if (empty($topProducts)): ?>
                <p>No products sold in this period.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="product-table">
                        <thead>
                            <tr><th>Product</th><th>Units sold</th><th>Revenue</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $row): ?>
                                <tr>
                                    <td><?= e($row["name"] ?? "") ?></td>
                                    <td><?= (int) $row["units"] ?></td>
                                    <td>KES <?= number_format((float) $row["revenue"], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="product-list-section">
            <h2>Top facilities</h2>

            <?php if (empty($topFacilities)): ?>
                <p>No orders in this period.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="product-table">
                        <thead>
                            <tr><th>Facility</th><th>Orders</th><th>Spent</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topFacilities as $row): ?>
                                <tr>
                                    <td><?= e($row["facility"]) ?></td>
                                    <td><?= (int) $row["orders_count"] ?></td>
                                    <td>KES <?= number_format((float) $row["amount"], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="product-list-section">
            <h2>Inventory right now</h2>
            <p>
                <?= (int) $stock["active"] ?> active products &middot;
                <strong><?= (int) $stock["low"] ?></strong> low
                (<?= (int) $lowStockLimit ?> or fewer) &middot;
                <strong><?= (int) $stock["out"] ?></strong> out of stock.
                <a href="products.php">Manage products</a>
            </p>
        </section>

    </main>
</div>

</body>
</html>