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

// Read and validate the delivery details.
$facilityName    = trim((string) ($_POST["facility_name"] ?? ""));
$contactPhone    = trim((string) ($_POST["contact_phone"] ?? ""));
$deliveryAddress = trim((string) ($_POST["delivery_address"] ?? ""));
$notes           = trim((string) ($_POST["notes"] ?? ""));

$_SESSION["checkout_old"] = [
    "facility_name"    => $facilityName,
    "contact_phone"    => $contactPhone,
    "delivery_address" => $deliveryAddress,
    "notes"            => $notes,
];

if ($facilityName === "" || mb_strlen($facilityName) > 150) {
    redirectWith("checkout.php", "checkout_error", "Please enter a facility name (up to 150 characters).");
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

require_once "../config/database.php";

try {
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

    // Create the order.
    $orderStmt = $pdo->prepare("
        INSERT INTO orders
            (user_id, facility_name, contact_phone, delivery_address, notes, total_amount, status)
        VALUES
            (:user_id, :facility_name, :contact_phone, :delivery_address, :notes, :total_amount, 'pending')
    ");

    $orderStmt->execute([
        ":user_id"          => (int) $_SESSION["user_id"],
        ":facility_name"    => $facilityName,
        ":contact_phone"    => $contactPhone,
        ":delivery_address" => $deliveryAddress,
        ":notes"            => $notes !== "" ? $notes : null,
        ":total_amount"     => $total,
    ]);

    $orderId = (int) $pdo->lastInsertId();

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