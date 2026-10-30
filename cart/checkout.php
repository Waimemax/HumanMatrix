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
$facilities = [];
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

    // Facilities the customer can order for.
    $facilityStmt = $pdo->query("
        SELECT id, name, phone, address
        FROM facilities
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $facilities = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
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

$selectedFacility = (int) ($old["facility_id"] ?? 0);
$selectedFacilityName = "";

foreach ($facilities as $facility) {
    if ((int) $facility["id"] === $selectedFacility) {
        $selectedFacilityName = $facility["name"];
        break;
    }
}
$selectedPayment = $old["payment_method"] ?? "";
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
                <p>Confirm your order, choose your facility and how you will pay.</p>
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

            <?php if (empty($facilities)): ?>
                <div class="catalogue-message">
                    <h3>No facilities available</h3>
                    <p>
                        There are no facilities set up yet, so an order cannot be placed.
                        Please contact HumanMatrix to have your facility added.
                    </p>
                    <p><a href="view.php">Back to cart</a></p>
                </div>
            <?php else: ?>

            <form id="checkout-form" action="place_order.php" method="POST" style="display:grid; gap:12px; max-width:520px;">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">

                <h3 style="margin-bottom:0;">Facility and delivery</h3>

                <label for="facility_search">Facility</label>
                <input
                    type="text"
                    id="facility_search"
                    name="facility_search"
                    list="facility_list"
                    autocomplete="off"
                    placeholder="Start typing your facility name..."
                    maxlength="150"
                    required
                    value="<?= e($selectedFacilityName) ?>"
                >
                <datalist id="facility_list">
                    <?php foreach ($facilities as $facility): ?>
                        <option
                            value="<?= e($facility["name"]) ?>"
                            data-id="<?= (int) $facility["id"] ?>"
                            data-phone="<?= e($facility["phone"] ?? "") ?>"
                            data-address="<?= e($facility["address"] ?? "") ?>"
                        ></option>
                    <?php endforeach; ?>
                </datalist>
                <input type="hidden" id="facility_id" name="facility_id"
                       value="<?= $selectedFacility > 0 ? $selectedFacility : "" ?>">
                <small>Type to search, then pick your facility from the suggestions.</small>

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

                <h3 style="margin-bottom:0;">Payment</h3>
                <fieldset style="border:0; padding:0; margin:0; display:grid; gap:8px;">
                    <legend style="padding:0; margin-bottom:6px;">When will you pay?</legend>

                    <label style="display:flex; gap:8px; align-items:flex-start;">
                        <input type="radio" name="payment_method" value="before_delivery"
                               required <?= $selectedPayment === "before_delivery" ? "checked" : "" ?>>
                        <span>
                            <strong>Pay before delivery</strong><br>
                            <small>HumanMatrix will contact you with payment details.
                            Your order is shipped once payment is confirmed.</small>
                        </span>
                    </label>

                    <label style="display:flex; gap:8px; align-items:flex-start;">
                        <input type="radio" name="payment_method" value="on_delivery"
                               <?= $selectedPayment === "on_delivery" ? "checked" : "" ?>>
                        <span>
                            <strong>Pay upon delivery</strong><br>
                            <small>Pay when the equipment is delivered to your facility.</small>
                        </span>
                    </label>
                </fieldset>

                <div style="display:flex; gap:12px; align-items:center;">
                    <button type="submit">Place Order</button>
                    <a href="view.php">Back to cart</a>
                </div>
            </form>

            <script>
                (function () {
                    var form = document.getElementById("checkout-form");
                    var search = document.getElementById("facility_search");
                    var hiddenId = document.getElementById("facility_id");
                    var list = document.getElementById("facility_list");
                    var phone = document.getElementById("contact_phone");
                    var address = document.getElementById("delivery_address");

                    function findOption(text) {
                        var wanted = text.trim().toLowerCase();
                        for (var i = 0; i < list.options.length; i++) {
                            if (list.options[i].value.toLowerCase() === wanted) {
                                return list.options[i];
                            }
                        }
                        return null;
                    }

                    function syncFacility() {
                        var option = findOption(search.value);

                        if (!option) {
                            hiddenId.value = "";
                            search.setCustomValidity("Please choose your facility from the suggestions.");
                            return;
                        }

                        search.setCustomValidity("");

                        // Pre-fill phone and address only when the facility changes.
                        // The customer can still edit both before submitting.
                        if (hiddenId.value !== option.getAttribute("data-id")) {
                            hiddenId.value = option.getAttribute("data-id");
                            phone.value = option.getAttribute("data-phone") || phone.value;
                            address.value = option.getAttribute("data-address") || address.value;
                        }
                    }

                    search.addEventListener("input", syncFacility);
                    search.addEventListener("change", syncFacility);

                    // Re-validate on load (for a form re-filled after an error).
                    if (search.value !== "") { syncFacility(); }

                    // Ask the customer to confirm the details before ordering.
                    form.addEventListener("submit", function (event) {
                        syncFacility();

                        var method = form.querySelector('input[name="payment_method"]:checked');
                        var methodText = method && method.value === "before_delivery"
                            ? "Pay before delivery"
                            : "Pay upon delivery";

                        var summary =
                            "Please confirm your order details:\n\n" +
                            "Facility: " + search.value.trim() + "\n" +
                            "Phone: " + phone.value.trim() + "\n" +
                            "Delivery address: " + address.value.trim() + "\n" +
                            "Payment: " + methodText + "\n\n" +
                            "Place this order?";

                        if (!window.confirm(summary)) {
                            event.preventDefault();
                        }
                    });
                })();
            </script>

            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>