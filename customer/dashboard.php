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

function statusLabel(string $status): string
{
    return ucfirst(str_replace("_", " ", $status));
}

$customerName = $_SESSION["full_name"] ?? "Customer";
$customerEmail = $_SESSION["email"] ?? "";
$customerRole = $_SESSION["role"] ?? "customer";

$safeName = e($customerName);
$safeEmail = e($customerEmail);
$safeRole = e(ucfirst($customerRole));

// Get the first name for a friendlier welcome.
$nameParts = preg_split('/\s+/', trim($customerName));
$firstName = $nameParts[0] ?? "Customer";
$safeFirstName = e($firstName);

$userId = (int) $_SESSION["user_id"];

$cart = $_SESSION["cart"] ?? [];
$cartLines = 0;
$cartTotal = 0.0;

$statusCounts = [
    "pending"    => 0,
    "confirmed"  => 0,
    "processing" => 0,
    "dispatched" => 0,
    "in_transit" => 0,
    "delivered"  => 0,
    "cancelled"  => 0,
];
$recentOrders = [];
$recentNotifications = [];
$unreadCount = 0;
$dataError = false;

try {
    // Cart summary: only count products that are still available.
    if (is_array($cart) && !empty($cart)) {
        $ids = array_map("intval", array_keys($cart));
        $placeholders = implode(",", array_fill(0, count($ids), "?"));

        $stmt = $pdo->prepare("
            SELECT id, price
            FROM products
            WHERE id IN ($placeholders) AND status = 'active'
        ");
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cartLines++;
            $cartTotal += (float) $row["price"] * (int) $cart[$row["id"]];
        }
    }

    // Order counts by status for this customer.
    $countStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM orders
        WHERE user_id = :user_id
        GROUP BY status
    ");
    $countStmt->execute([":user_id" => $userId]);

    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($statusCounts[$row["status"]])) {
            $statusCounts[$row["status"]] = (int) $row["total"];
        }
    }

    // The five most recent orders.
    $orderStmt = $pdo->prepare("
        SELECT id, total_amount, status, payment_method, payment_status, created_at
        FROM orders
        WHERE user_id = :user_id
        ORDER BY id DESC
        LIMIT 5
    ");
    $orderStmt->execute([":user_id" => $userId]);
    $recentOrders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

    // The latest notifications and how many are unread.
    $noteStmt = $pdo->prepare("
        SELECT id, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY id DESC
        LIMIT 3
    ");
    $noteStmt->execute([":user_id" => $userId]);
    $recentNotifications = $noteStmt->fetchAll(PDO::FETCH_ASSOC);
    $unreadCount = unreadNotificationCount($pdo, $userId);
} catch (PDOException $exception) {
    error_log("HumanMatrix customer dashboard error: " . $exception->getMessage());
    $dataError = true;
}

$totalOrders = array_sum($statusCounts);
$onTheWay = $statusCounts["dispatched"] + $statusCounts["in_transit"];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Dashboard | Human Matrix</title>

    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body class="customer-dashboard-page">

    <header class="dashboard-header">

        <a href="../index.php" class="dashboard-brand">
            <span class="brand-icon">HM</span>
            <span>Human Matrix</span>
        </a>

        <nav class="dashboard-nav" aria-label="Main navigation">
            <a href="../index.php" class="nav-home">Home</a>
            <a href="product.php">Equipment</a>
            <a href="../cart/view.php">Cart<?= $cartLines > 0 ? " (" . $cartLines . ")" : "" ?></a>
            <a href="orders.php">My Orders</a>
            <a href="notifications.php">Notifications<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
            <a href="profile.php">My Account</a>
            <a href="../auth/logout.php" class="dashboard-logout">
                <span aria-hidden="true">↪</span> Logout
            </a>
        </nav>

    </header>

    <main class="dashboard-main">

        <section class="welcome-banner">

            <div class="welcome-copy">
                <span class="welcome-label">CUSTOMER DASHBOARD</span>

                <h1>Welcome back, <?= $safeFirstName; ?>! <span aria-hidden="true">👋</span></h1>

                <p>
                    We're glad to have you here. Browse medical equipment,
                    place orders for your facility and follow them through
                    to delivery.
                </p>

                <a href="product.php" class="welcome-button">
                    Browse Equipment <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="welcome-decoration" aria-hidden="true">
                <div class="welcome-circle">
                    <span>HM</span>
                    <small>Healthcare made simpler</small>
                </div>
            </div>

        </section>

        <section class="dashboard-section">

            <div class="dashboard-section-heading">
                <div>
                    <h2>Quick access</h2>
                    <p>Where would you like to go today?</p>
                </div>
            </div>

            <div class="dashboard-cards">

                <a href="product.php" class="dashboard-card">
                    <div class="dashboard-card-icon equipment-icon">
                        <span aria-hidden="true">✚</span>
                    </div>

                    <h3>Explore Equipment</h3>

                    <p>
                        Browse the equipment catalogue, check prices and
                        see what is currently in stock.
                    </p>

                    <span class="dashboard-card-link">
                        Browse now <span aria-hidden="true">→</span>
                    </span>
                </a>

                <a href="../cart/view.php" class="dashboard-card">
                    <div class="dashboard-card-icon orders-icon">
                        <span aria-hidden="true">🛒</span>
                    </div>

                    <h3>My Cart</h3>

                    <?php if ($cartLines > 0): ?>
                        <p>
                            <strong><?= (int) $cartLines ?>
                            item<?= $cartLines === 1 ? "" : "s" ?></strong>
                            in your cart, worth
                            <strong>KSh <?= number_format($cartTotal, 2) ?></strong>.
                        </p>

                        <span class="dashboard-card-link">
                            Review and check out <span aria-hidden="true">→</span>
                        </span>
                    <?php else: ?>
                        <p>Your cart is empty. Add equipment to start an order.</p>

                        <span class="dashboard-card-link">
                            View cart <span aria-hidden="true">→</span>
                        </span>
                    <?php endif; ?>
                </a>

                <a href="orders.php" class="dashboard-card">
                    <div class="dashboard-card-icon orders-icon">
                        <span aria-hidden="true">▤</span>
                    </div>

                    <h3>My Orders</h3>

                    <?php if ($dataError): ?>
                        <p>Your orders could not be loaded right now.</p>
                    <?php elseif ($totalOrders === 0): ?>
                        <p>You haven't placed any orders yet.</p>
                    <?php else: ?>
                        <p>
                            <strong><?= (int) $totalOrders ?></strong>
                            order<?= $totalOrders === 1 ? "" : "s" ?> placed.
                            <?= (int) $statusCounts["pending"] ?> pending,
                            <?= (int) $onTheWay ?> on the way,
                            <?= (int) $statusCounts["delivered"] ?> delivered.
                        </p>
                    <?php endif; ?>

                    <span class="dashboard-card-link">
                        View all orders <span aria-hidden="true">→</span>
                    </span>
                </a>

                <div class="dashboard-card" id="my-account">
                    <div class="dashboard-card-icon profile-icon">
                        <span aria-hidden="true">♙</span>
                    </div>

                    <h3>My Account</h3>

                    <p>
                        Check the account details associated with
                        your Human Matrix login.
                    </p>

                    <a href="#account-details" class="dashboard-card-link">
                        View details <span aria-hidden="true">↓</span>
                    </a>
                    <a href="profile.php" class="dashboard-card-link">
                        Edit profile &amp; password <span aria-hidden="true">→</span>
                    </a>
                </div>

            </div>

        </section>

        <section class="account-panel" id="notifications">

            <div class="account-panel-heading">
                <div class="account-avatar" aria-hidden="true">&#9993;</div>

                <div>
                    <h2>Notifications</h2>
                    <p>
                        <?= $unreadCount > 0
                            ? (int) $unreadCount . " unread update" . ($unreadCount === 1 ? "" : "s")
                            : "You're all caught up" ?>
                    </p>
                </div>
            </div>

            <?php if (empty($recentNotifications)): ?>
                <p>No notifications yet. We'll tell you when your orders move forward.</p>
            <?php else: ?>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:8px;">
                    <?php foreach ($recentNotifications as $note): ?>
                        <li style="padding:10px 14px; border-radius:8px; border:1px solid #e5eaf0; background:<?= (int) $note["is_read"] === 0 ? "#eef6fc" : "#ffffff" ?>;">
                            <strong>
                                <?php if ((int) $note["is_read"] === 0): ?>
                                    <span aria-label="Unread" style="color:#2476a8;">&#9679;</span>
                                <?php endif; ?>
                                <?= e($note["title"]) ?>
                            </strong>
                            <small style="margin-left:8px;"><?= e(date("d M Y, H:i", strtotime($note["created_at"]))) ?></small>
                            <p style="margin:4px 0 0;"><?= e($note["message"]) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p style="margin-top:12px;">
                <a href="notifications.php"><strong>View all notifications</strong></a>
            </p>

        </section>

        <section class="account-panel" id="my-orders">

            <div class="account-panel-heading">
                <div class="account-avatar" aria-hidden="true">▤</div>

                <div>
                    <h2>Recent orders</h2>
                    <p>Your latest orders and where they stand</p>
                </div>
            </div>

            <?php if ($dataError): ?>
                <p>We couldn't load your orders. Please try again later.</p>

            <?php elseif (empty($recentOrders)): ?>
                <p>
                    No orders yet.
                    <a href="product.php">Browse equipment</a> to place your first order.
                </p>

            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left; border-bottom:1px solid #ccc;">
                                <th style="padding:8px;">Order</th>
                                <th style="padding:8px;">Placed</th>
                                <th style="padding:8px;">Total</th>
                                <th style="padding:8px;">Payment</th>
                                <th style="padding:8px;">Status</th>
                                <th style="padding:8px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr style="border-bottom:1px solid #eee;">
                                    <td style="padding:8px;">
                                        <strong>HM-<?= str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) ?></strong>
                                    </td>
                                    <td style="padding:8px;">
                                        <?= e(date("d M Y", strtotime($order["created_at"]))) ?>
                                    </td>
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
                                        <a href="order.php?id=<?= (int) $order["id"] ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p style="margin-top:12px;">
                    <?php if ($totalOrders > count($recentOrders)): ?>
                        Showing your latest <?= count($recentOrders) ?> of
                        <?= (int) $totalOrders ?> orders.
                    <?php endif; ?>
                    <a href="orders.php"><strong>View all orders</strong></a>
                </p>
            <?php endif; ?>

        </section>

        <section class="account-panel" id="account-details">

            <div class="account-panel-heading">
                <div class="account-avatar" aria-hidden="true">
                    <?= e(strtoupper(substr($firstName, 0, 1))); ?>
                </div>

                <div>
                    <h2>Your account</h2>
                    <p>Your current profile information</p>
                </div>
            </div>

            <div class="account-details-grid">

                <div class="account-detail">
                    <span>Full name</span>
                    <strong><?= $safeName; ?></strong>
                </div>

                <div class="account-detail">
                    <span>Email address</span>
                    <strong><?= $safeEmail; ?></strong>
                </div>

                <div class="account-detail">
                    <span>Account role</span>
                    <strong><?= $safeRole; ?></strong>
                </div>

                <div class="account-detail">
                    <span>Session status</span>
                    <strong class="session-active">
                        <span aria-hidden="true">●</span> Signed in
                    </strong>
                </div>

            </div>

        </section>

        <footer class="dashboard-footer">
            <p>&copy; <?= date("Y"); ?> Human Matrix. Supporting healthcare through reliable equipment access.</p>
        </footer>

    </main>

</body>
</html>