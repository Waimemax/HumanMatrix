<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: view.php");
    exit;
}

function backToCart(string $message, string $type): void
{
    $_SESSION["cart_message"] = $message;
    $_SESSION["cart_message_type"] = $type;

    header("Location: view.php");
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
    backToCart("Your session token was invalid. Please try again.", "error");
}

$action = $_POST["action"] ?? "";

// Clear the whole cart. This needs no product id.
if ($action === "clear") {
    $_SESSION["cart"] = [];
    backToCart("Your cart has been cleared.", "success");
}

$productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    backToCart("Invalid product.", "error");
}

if (
    !isset($_SESSION["cart"]) ||
    !is_array($_SESSION["cart"]) ||
    !isset($_SESSION["cart"][$productId])
) {
    backToCart("That item is not in your cart.", "error");
}

// Remove an item.
if ($action === "remove") {
    unset($_SESSION["cart"][$productId]);
    backToCart("Item removed from your cart.", "success");
}

// Update an item's quantity.
if ($action === "update") {
    $quantity = filter_input(INPUT_POST, "quantity", FILTER_VALIDATE_INT);

    if ($quantity === false || $quantity === null || $quantity < 1) {
        backToCart("Please enter a quantity of 1 or more.", "error");
    }

    require_once "../config/database.php";

    try {
        // Always check the current stock in the database.
        $stmt = $pdo->prepare("
            SELECT name, stock_quantity
            FROM products
            WHERE id = :id AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([":id" => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            unset($_SESSION["cart"][$productId]);
            backToCart("This product is no longer available and was removed.", "error");
        }

        $availableStock = (int) $product["stock_quantity"];

        if ($quantity > $availableStock) {
            backToCart(
                "Only " . $availableStock . " unit(s) of " . $product["name"] .
                " are available. Your cart was not changed.",
                "error"
            );
        }

        $_SESSION["cart"][$productId] = $quantity;
        backToCart("Quantity updated for " . $product["name"] . ".", "success");
    } catch (PDOException $exception) {
        error_log("HumanMatrix cart update error: " . $exception->getMessage());
        backToCart("We couldn't update your cart right now. Please try again.", "error");
    }
}

backToCart("Unknown action.", "error");