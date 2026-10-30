<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: checkout.php");
    exit;
}

function redirectWith(string $location, string $key, string $message): void
{
    $_SESSION[$key] = $message;

    if ($key === "cart_message") {
        $_SESSION["cart_message_type"] = "error";
    }

    header("Location: " . $location);
    exit;
}

// Verify the security token.
$submittedToken = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["csrf_token"] ?? "";

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === "" ||
    !hash_equals($sessionToken, $submittedToken)
) {
    redirectWith("checkout.php", "checkout_error", "Your session token was invalid. Please try again.");
}

$cart = $_SESSION["cart"] ?? [];

if (!is_array($cart) || empty($cart)) {
    redirectWith("view.php", "cart_message", "Your cart is empty.");
}

// Read the submitted details.
$facilityId      = filter_input(INPUT_POST, "facility_id", FILTER_VALIDATE_INT);
$contactPhone    = trim((string) ($_POST["contact_phone"] ?? ""));
$deliveryAddress = trim((string) ($_POST["delivery_address"] ?? ""));
$notes           = trim((string) ($_POST["notes"] ?? ""));
$paymentMethod   = $_POST["payment_method"] ?? "";

// Keep the entered values so the form can be re-filled after an error.
$_SESSION["checkout_old"] = [
    "facility_id"      => ($facilityId === false || $facilityId === null) ? 0 : $facilityId,
    "contact_phone"    => $contactPhone,
    "delivery_address" => $deliveryAddress,
    "notes"            => $notes,
    "payment_method"   => is_string($paymentMethod) ? $paymentMethod : "",
];

$facilitySearch = trim((string) ($_POST["facility_search"] ?? ""));

if (($facilityId === false || $facilityId === null || $facilityId < 1) && $facilitySearch === "") {
    redirectWith("checkout.php", "checkout_error", "Please select your facility.");
}

if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $contactPhone)) {
    redirectWith("checkout.php", "checkout_error", "Please enter a valid contact phone number.");
}

if ($deliveryAddress === "" || mb_strlen($deliveryAddress) > 500) {
    redirectWith("checkout.php", "checkout_error", "Please enter a delivery address (up to 500 characters).");
}

if (mb_strlen($notes) > 500) {
    redirectWith("checkout.php", "checkout_error", "Notes must be 500 characters or fewer.");
}

if (!is_string($paymentMethod) || !in_array($paymentMethod, ["before_delivery", "on_delivery"], true)) {
    redirectWith("checkout.php", "checkout_error", "Please choose when you will pay.");
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

try {
    // The facility must exist and be active. The name is read from the
    // database, never trusted from the browser.
    $facility = false;

    if ($facilityId !== false && $facilityId !== null && $facilityId >= 1) {
        $facilityStmt = $pdo->prepare("
            SELECT id, name
            FROM facilities
            WHERE id = :id AND status = 'active'
            LIMIT 1
        ");
        $facilityStmt->execute([":id" => $facilityId]);
        $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Fall back to an exact name match if the id did not arrive.
    if (!$facility && $facilitySearch !== "") {
        $facilityStmt = $pdo->prepare("
            SELECT id, name
            FROM facilities
            WHERE name = :name AND status = 'active'
            LIMIT 1
        ");
        $facilityStmt->execute([":name" => $facilitySearch]);
        $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$facility) {
        redirectWith("checkout.php", "checkout_error", "The selected facility is not available. Please choose another.");
    }

    $pdo->beginTransaction();

    // Lock products in a consistent order to avoid deadlocks.
    ksort($cart);

    $lockStmt = $pdo->prepare("
        SELECT id, name, price, stock_quantity
        FROM products
        WHERE id = :id AND status = 'active'
        FOR UPDATE
    ");

    $lines = [];
    $total = 0.0;

    foreach ($cart as $productId => $quantity) {
        $productId = (int) $productId;
        $quantity = (int) $quantity;

        $lockStmt->execute([":id" => $productId]);
        $product = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException(
                "An item in your cart is no longer available. Please review your cart."
            );
        }

        if ($quantity < 1 || $quantity > (int) $product["stock_quantity"]) {
            throw new RuntimeException(
                "Only " . (int) $product["stock_quantity"] . " unit(s) of " .
                $product["name"] . " are available. Please update your cart."
            );
        }

        // Always use the price from the database, never from the browser.
        $unitPrice = (float) $product["price"];
        $subtotal = $unitPrice * $quantity;
        $total += $subtotal;

        $lines[] = [
            "id"       => $productId,
            "name"     => $product["name"],
            "price"    => $unitPrice,
            "quantity" => $quantity,
            "subtotal" => $subtotal,
        ];
    }

    // Create the order. Payment always starts as unpaid; an administrator
    // confirms payment because payments are handled outside the system.
    $orderStmt = $pdo->prepare("
        INSERT INTO orders
            (user_id, order_number, facility_id, facility_name, contact_phone, delivery_address,
             notes, payment_method, payment_status, total_amount, status)
        VALUES
            (:user_id, :order_number, :facility_id, :facility_name, :contact_phone, :delivery_address,
             :notes, :payment_method, 'unpaid', :total_amount, 'pending')
    ");

    $orderStmt->execute([
        ":user_id"          => (int) $_SESSION["user_id"],
        ":order_number"     => "TMP-" . bin2hex(random_bytes(10)),
        ":facility_id"      => (int) $facility["id"],
        ":facility_name"    => $facility["name"],
        ":contact_phone"    => $contactPhone,
        ":delivery_address" => $deliveryAddress,
        ":notes"            => $notes !== "" ? $notes : null,
        ":payment_method"   => $paymentMethod,
        ":total_amount"     => $total,
    ]);

    $orderId = (int) $pdo->lastInsertId();

    // The orders table requires a unique order number. Replace the temporary
    // value with the readable reference, e.g. HM-000012.
    $numberStmt = $pdo->prepare("UPDATE orders SET order_number = :number WHERE id = :id");
    $numberStmt->execute([
        ":number" => "HM-" . str_pad((string) $orderId, 6, "0", STR_PAD_LEFT),
        ":id"     => $orderId,
    ]);

    // Save each line and reduce stock.
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items
            (order_id, product_id, product_name, unit_price, quantity, subtotal)
        VALUES
            (:order_id, :product_id, :product_name, :unit_price, :quantity, :subtotal)
    ");

    $stockStmt = $pdo->prepare("
        UPDATE products
        SET stock_quantity = stock_quantity - :deduct
        WHERE id = :id AND stock_quantity >= :required
    ");

    foreach ($lines as $line) {
        $itemStmt->execute([
            ":order_id"     => $orderId,
            ":product_id"   => $line["id"],
            ":product_name" => $line["name"],
            ":unit_price"   => $line["price"],
            ":quantity"     => $line["quantity"],
            ":subtotal"     => $line["subtotal"],
        ]);

        $stockStmt->execute([
            ":deduct"   => $line["quantity"],
            ":id"       => $line["id"],
            ":required" => $line["quantity"],
        ]);

        if ($stockStmt->rowCount() !== 1) {
            throw new RuntimeException(
                "Stock changed while placing your order. Please review your cart."
            );
        }
    }

    $pdo->commit();

    // Tell the customer their order was received. This never blocks the order.
    createNotification(
        $pdo,
        (int) $_SESSION["user_id"],
        "Order placed",
        "Your order " . orderReference($orderId) . " has been placed. "
        . "HumanMatrix will confirm it shortly."
    );

    // Order placed: clear the cart and the saved form values.
    $_SESSION["cart"] = [];
    unset($_SESSION["checkout_old"]);

    header("Location: order_success.php?id=" . $orderId);
    exit;
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirectWith("view.php", "cart_message", $exception->getMessage());
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("HumanMatrix place order error: " . $exception->getMessage());

    redirectWith(
        "checkout.php",
        "checkout_error",
        "We couldn't place your order right now. Please try again."
    );
}