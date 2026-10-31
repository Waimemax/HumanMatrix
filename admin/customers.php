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

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Optional search by name, email or phone.
$search = trim((string) ($_GET["search"] ?? ""));
$search = mb_substr($search, 0, 100);

$customers = [];
$totals = ["customers" => 0, "active" => 0, "with_orders" => 0];
$error = "";

// Activate or deactivate a customer account. Only customers can be changed
// here, so an administrator account can never be locked out from this page.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid form submission. Please refresh and try again.";
    } else {
        try {
            $id = filter_var($_POST["customer_id"] ?? "", FILTER_VALIDATE_INT);
            $newStatus = $_POST["new_status"] ?? "";

            if (
                $id === false || $id === null || $id < 1 ||
                !in_array($newStatus, ["active", "inactive"], true)
            ) {
                throw new RuntimeException("Invalid customer or status.");
            }

            $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'customer'");
            $check->execute([$id]);

            if (!$check->fetch()) {
                throw new RuntimeException("Customer not found.");
            }

            $update = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'customer'");
            $update->execute([$newStatus, $id]);

            $back = "customers.php?updated=" . ($newStatus === "active" ? "activated" : "deactivated");

            // Return to the same search the admin was looking at.
            $returnSearch = mb_substr(trim((string) ($_POST["search"] ?? "")), 0, 100);

            if ($returnSearch !== "") {
                $back .= "&search=" . urlencode($returnSearch);
            }

            header("Location: " . $back);
            exit;
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log("Admin customer status error: " . $exception->getMessage());
            $error = "The account could not be updated. Please try again.";
        }
    }
}

try {
    $sql = "
        SELECT u.id, u.full_name, u.email, u.phone, u.status, u.created_at,
               COUNT(o.id) AS order_count,
               COALESCE(SUM(CASE WHEN o.status <> 'cancelled'
                                 THEN o.total_amount ELSE 0 END), 0) AS total_spent,
               MAX(o.created_at) AS last_order
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.id
        WHERE u.role = 'customer'
    ";

    $params = [];

    if ($search !== "") {
        $sql .= " AND (u.full_name LIKE :search_name
                    OR u.email LIKE :search_email
                    OR u.phone LIKE :search_phone)";

        $like = "%" . $search . "%";
        $params[":search_name"] = $like;
        $params[":search_email"] = $like;
        $params[":search_phone"] = $like;
    }

    $sql .= "
        GROUP BY u.id, u.full_name, u.email, u.phone, u.status, u.created_at
        ORDER BY u.created_at DESC, u.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($customers as $customer) {
        $totals["customers"]++;

        if ($customer["status"] === "active") {
            $totals["active"]++;
        }

        if ((int) $customer["order_count"] > 0) {
            $totals["with_orders"]++;
        }
    }
} catch (PDOException $exception) {
    error_log("Admin customers error: " . $exception->getMessage());
    $error = "The customers could not be loaded. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Customers</h1>
            <p>Registered customers and how much they have ordered.</p>
        </header>

        <?php if (($_GET["updated"] ?? "") === "activated"): ?>
            <p class="notice success">Customer account activated.</p>
        <?php elseif (($_GET["updated"] ?? "") === "deactivated"): ?>
            <p class="notice success">
                Customer account deactivated. They can no longer sign in
                or place new orders.
            </p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-list-section">

            <form method="GET" action="customers.php"
                  style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px;">
                <div class="form-group">
                    <label for="search">Search customers</label>
                    <input type="search" id="search" name="search"
                           placeholder="Name, email or phone"
                           maxlength="100" value="<?= e($search) ?>">
                </div>

                <button type="submit" class="primary-button">Search</button>

                <?php if ($search !== ""): ?>
                    <a class="secondary-button" href="customers.php">Clear</a>
                <?php endif; ?>
            </form>

            <p>
                Showing <strong><?= (int) $totals["customers"] ?></strong>
                customer(s) &middot;
                <?= (int) $totals["active"] ?> active &middot;
                <?= (int) $totals["with_orders"] ?> have placed an order
            </p>

            <?php if (empty($customers)): ?>
                <p>
                    <?= $search !== ""
                        ? "No customers match your search."
                        : "No customers have registered yet." ?>
                </p>
            <?php else: ?>

            <div class="table-wrapper">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Orders</th>
                            <th>Total spent</th>
                            <th>Last order</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><strong><?= e($customer["full_name"]) ?></strong></td>
                            <td><?= e($customer["email"]) ?></td>
                            <td><?= e($customer["phone"]) ?></td>
                            <td>
                                <span class="status-label <?= e($customer["status"]) ?>">
                                    <?= e(ucfirst($customer["status"])) ?>
                                </span>
                            </td>
                            <td><?= (int) $customer["order_count"] ?></td>
                            <td>KES <?= number_format((float) $customer["total_spent"], 2) ?></td>
                            <td>
                                <?= !empty($customer["last_order"])
                                    ? e(date("d M Y", strtotime($customer["last_order"])))
                                    : "&mdash;" ?>
                            </td>
                            <td><?= e(date("d M Y", strtotime($customer["created_at"]))) ?></td>
                            <td>
                                <form method="POST" action="customers.php"
                                      <?= $customer["status"] === "active"
                                          ? "onsubmit=\"return confirm('Deactivate this customer? They will not be able to sign in or place orders.');\""
                                          : "" ?>>
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION["csrf_token"]) ?>">
                                    <input type="hidden" name="customer_id"
                                           value="<?= (int) $customer["id"] ?>">
                                    <input type="hidden" name="new_status"
                                           value="<?= $customer["status"] === "active" ? "inactive" : "active" ?>">
                                    <input type="hidden" name="search"
                                           value="<?= e($search) ?>">
                                    <button type="submit" class="secondary-button">
                                        <?= $customer["status"] === "active" ? "Deactivate" : "Activate" ?>
                                    </button>
                                </form>
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