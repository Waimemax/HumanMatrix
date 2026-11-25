<?php

require_once __DIR__ . "/logout_form.php";
$current_page = basename($_SERVER['PHP_SELF']);

$adminUnread = 0;

if (isset($pdo) && $pdo instanceof PDO && isset($_SESSION["user_id"])) {
    require_once __DIR__ . "/notifications.php";
    $adminUnread = unreadNotificationCount($pdo, (int) $_SESSION["user_id"]);
}
?>

<aside class="admin-sidebar">
    <div class="sidebar-brand">
                <h2>Human Matrix</h2>
        <p style="margin:0;font-size:11px;letter-spacing:.04em;text-transform:uppercase;opacity:.85;">Advanced Medical Services</p>
        <p>Administration</p>
    </div>

    <nav class="sidebar-nav">
        <a href="/HumanMatrix/admin/dashboard.php"
           class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
            Dashboard
        </a>

        <a href="/HumanMatrix/admin/categories.php"
           class="<?= $current_page === 'categories.php' ? 'active' : '' ?>">
            Categories
        </a>

        <a href="/HumanMatrix/admin/products.php"
           class="<?= $current_page === 'products.php' ? 'active' : '' ?>">
            Products
        </a>

        <a href="/HumanMatrix/admin/facilities.php"
           class="<?= $current_page === 'facilities.php' ? 'active' : '' ?>">
            Facilities
        </a>

        <a href="/HumanMatrix/admin/orders.php"
           class="<?= in_array($current_page, ['orders.php', 'order_view.php'], true) ? 'active' : '' ?>">
            Orders
        </a>

        <a href="/HumanMatrix/admin/customers.php"
           class="<?= $current_page === 'customers.php' ? 'active' : '' ?>">
            Customers
        </a>

        <a href="/HumanMatrix/admin/deliveries.php"
           class="<?= $current_page === 'deliveries.php' ? 'active' : '' ?>">
            Deliveries
        </a>

        <a href="/HumanMatrix/admin/reports.php"
           class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">
            Reports
        </a>

        <a href="/HumanMatrix/admin/notifications.php"
           class="<?= $current_page === 'notifications.php' ? 'active' : '' ?>">
            Notifications<?= $adminUnread > 0 ? ' (' . $adminUnread . ')' : '' ?>
        </a>

        <a href="/HumanMatrix/admin/profile.php"
           class="<?= $current_page === 'profile.php' ? 'active' : '' ?>">
            My Profile
        </a>

        <?php
renderLogoutForm(
    "/HumanMatrix/auth/logout.php",
    "admin-logout",
    "Logout"
);
?>
    </nav>
</aside>