<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

require_once "../config/database.php";

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$cart = $_SESSION["cart"] ?? [];

if (!is_array($cart) || empty($cart)) {
    $_SESSION["cart_message"] = "Your cart is empty.";
    $_SESSION["cart_message_type"] = "error";
    header("Location: view.php");
    exit;
}

// Messages and previously entered values from a failed submission.
$checkoutError = $_SESSION["checkout_error"] ?? "";
$old = $_SESSION["checkout_old"] ?? [];
unset($_SESSION["checkout_error"], $_SESSION["checkout_old"]);

$items = [];
$total = 0.0;
$error = false;

try {
    $ids = array_map("intval", array_keys($cart));
    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    $stmt = $pdo->prepare("
        SELECT id, name, price
        FROM products
        WHERE id IN ($placeholders) AND status = 'active'
    ");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qty = (int) $cart[$row["id"]];
        $subtotal = (float) $row["price"] * $qty;
        $total += $subtotal;

        $items[] = [
            "name"     => $row["name"],
            "price"    => (float) $row["price"],
            "quantity" => $qty,
            "subtotal" => $subtotal,
        ];
    }
} catch (PDOException $exception) {
    error_log("HumanMatrix checkout error: " . $exception->getMessage());
    $error = true;
}

if (!$error && empty($items)) {
    $_SESSION["cart_message"] = "The items in your cart are no longer available.";
    $_SESSION["cart_message_type"] = "error";
    header("Location: view.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="../customer/dashboard.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="../customer/dashboard.php">Dashboard</a>
        <a href="../customer/product.php">Equipment</a>
        <a href="view.php">Cart</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">
        <div class="catalogue-section-heading">
            <div>
                <h2>Checkout</h2>
                <p>Confirm your order and enter delivery details.</p>
            </div>
        </div>

        <?php if ($checkoutError !== ""): ?>
            <div class="catalogue-message" role="alert">
                <?= e($checkoutError) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="catalogue-message">
                We couldn't load your order. Please try again later.
            </div>
        <?php else: ?>

            <h3>Order summary</h3>
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
                            <td style="padding:8px;"><?= e($item["name"]) ?></td>
                            <td style="padding:8px;">KSh <?= number_format($item["price"], 2) ?></td>
                            <td style="padding:8px;"><?= (int) $item["quantity"] ?></td>
                            <td style="padding:8px;">KSh <?= number_format($item["subtotal"], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="padding:8px; text-align:right;"><strong>Total</strong></td>
                        <td style="padding:8px;"><strong>KSh <?= number_format($total, 2) ?></strong></td>
                    </tr>
                </tfoot>
            </table>

            <h3>Delivery details</h3>
            <form action="place_order.php" method="POST" style="display:grid; gap:12px; max-width:520px;">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">

                <label for="facility_name">Facility / organisation name</label>
                <input type="text" id="facility_name" name="facility_name"
                       maxlength="150" required
                       value="<?= e($old["facility_name"] ?? "") ?>">

                <label for="contact_phone">Contact phone</label>
                <input type="tel" id="contact_phone" name="contact_phone"
                       maxlength="20" required placeholder="e.g. +254 700 000 000"
                       value="<?= e($old["contact_phone"] ?? "") ?>">

                <label for="delivery_address">Delivery address</label>
                <textarea id="delivery_address" name="delivery_address"
                          rows="3" maxlength="500" required><?= e($old["delivery_address"] ?? "") ?></textarea>

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="2"
                          maxlength="500"><?= e($old["notes"] ?? "") ?></textarea>

                <div style="display:flex; gap:12px; align-items:center;">
                    <button type="submit">Place Order</button>
                    <a href="view.php">Back to cart</a>
                </div>
            </form>

        <?php endif; ?>
    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>