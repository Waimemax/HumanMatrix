<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

// Security token for the edit forms.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Retrieve any message from the previous request.
$cartMessage = $_SESSION["cart_message"] ?? "";
unset($_SESSION["cart_message"], $_SESSION["cart_message_type"]);

require_once "../config/database.php";

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$cart = $_SESSION["cart"] ?? [];
$items = [];
$total = 0.0;
$error = false;

if (is_array($cart) && !empty($cart)) {
    try {
        $ids = array_map("intval", array_keys($cart));
        $placeholders = implode(",", array_fill(0, count($ids), "?"));

        $stmt = $pdo->prepare("
            SELECT id, name, price, stock_quantity
            FROM products
            WHERE id IN ($placeholders) AND status = 'active'
        ");
        $stmt->execute($ids);

        $validIds = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $validIds[] = (int) $row["id"];
            $qty = (int) $cart[$row["id"]];
            $subtotal = (float) $row["price"] * $qty;
            $total += $subtotal;

            $items[] = [
                "id"       => (int) $row["id"],
                "name"     => $row["name"],
                "price"    => (float) $row["price"],
                "quantity" => $qty,
                "stock"    => (int) $row["stock_quantity"],
                "subtotal" => $subtotal,
            ];
        }
        // Drop cart entries whose product is inactive or deleted, so they
        // can never get stuck in the session.
        $removedCount = 0;

        foreach (array_keys($_SESSION["cart"]) as $cartId) {
            if (!in_array((int) $cartId, $validIds, true)) {
                unset($_SESSION["cart"][$cartId]);
                $removedCount++;
            }
        }

        if ($removedCount > 0 && $cartMessage === "") {
            $cartMessage = "Some items were removed because they are no longer available.";
        }
    } catch (PDOException $exception) {
        error_log("HumanMatrix cart view error: " . $exception->getMessage());
        $error = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart | HumanMatrix</title>
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
        <a class="active" href="view.php">Cart</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">
        <div class="catalogue-section-heading">
            <div>
                <h2>Your Cart</h2>
                <p>Review and edit the items you have added.</p>
            </div>
        </div>

        <?php if ($cartMessage !== ""): ?>
            <div class="catalogue-message" role="status">
                <?= e($cartMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="catalogue-message">
                We couldn't load your cart. Please try again later.
            </div>

        <?php elseif (empty($items)): ?>
            <div class="catalogue-message">
                <h3>Your cart is empty</h3>
                <p><a href="../customer/product.php">Browse equipment</a></p>
            </div>

        <?php else: ?>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left; border-bottom:1px solid #ccc;">
                        <th style="padding:8px;">Product</th>
                        <th style="padding:8px;">Price</th>
                        <th style="padding:8px;">Quantity</th>
                        <th style="padding:8px;">Subtotal</th>
                        <th style="padding:8px;">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px;"><?= e($item["name"]) ?></td>
                            <td style="padding:8px;">KSh <?= number_format($item["price"], 2) ?></td>

                            <td style="padding:8px;">
                                <form action="update.php" method="POST" style="display:flex; gap:6px;">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= $item["id"] ?>">

                                    <label for="qty-<?= $item["id"] ?>" class="visually-hidden" style="position:absolute; left:-9999px;">
                                        Quantity for <?= e($item["name"]) ?>
                                    </label>
                                    <input
                                        type="number"
                                        id="qty-<?= $item["id"] ?>"
                                        name="quantity"
                                        value="<?= $item["quantity"] ?>"
                                        min="1"
                                        max="<?= $item["stock"] ?>"
                                        required
                                        style="width:70px;"
                                    >
                                    <button type="submit">Update</button>
                                </form>
                            </td>

                            <td style="padding:8px;">KSh <?= number_format($item["subtotal"], 2) ?></td>

                            <td style="padding:8px;">
                                <form action="update.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= $item["id"] ?>">
                                    <button type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="padding:8px; text-align:right;"><strong>Total</strong></td>
                        <td colspan="2" style="padding:8px;"><strong>KSh <?= number_format($total, 2) ?></strong></td>
                    </tr>
                </tfoot>
            </table>

            <form action="update.php" method="POST" style="margin-top:16px;"
                  onsubmit="return confirm('Remove all items from your cart?');">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="clear">
                <button type="submit">Clear cart</button>
            </form>

            <p style="margin-top:16px;">
                <a href="checkout.php"><strong>Proceed to checkout</strong></a>
            </p>
        <?php endif; ?>
    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>