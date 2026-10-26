
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../customer/product.php");
    exit;
}

// Verify the cart form's security token.
$submittedToken = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["csrf_token"] ?? "";

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === "" ||
    !hash_equals($sessionToken, $submittedToken)
) {
    $_SESSION["cart_message"] = "Your session token was invalid. Please try again.";
    $_SESSION["cart_message_type"] = "error";

    header("Location: ../customer/product.php");
    exit;
}

$productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, "quantity", FILTER_VALIDATE_INT);

if (
    $productId === false ||
    $productId === null ||
    $productId < 1 ||
    $quantity === false ||
    $quantity === null ||
    $quantity < 1
) {
    $_SESSION["cart_message"] = "Please select a valid product and quantity.";
    $_SESSION["cart_message_type"] = "error";

    header("Location: ../customer/product.php");
    exit;
}

require_once "../config/database.php";

try {
    // Always verify the current product and stock in the database.
    $stmt = $pdo->prepare("
        SELECT id, name, stock_quantity
        FROM products
        WHERE id = :id AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([":id" => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $_SESSION["cart_message"] = "This product is unavailable.";
        $_SESSION["cart_message_type"] = "error";
    } else {
        $availableStock = (int) $product["stock_quantity"];

        if ($availableStock < 1) {
            $_SESSION["cart_message"] = "This product is out of stock.";
            $_SESSION["cart_message_type"] = "error";
        } else {
            if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
                $_SESSION["cart"] = [];
            }

            $currentQuantity = (int) (
                $_SESSION["cart"][$productId] ?? 0
            );

            if ($quantity + $currentQuantity > $availableStock) {
                $_SESSION["cart_message"] =
                    "Only " . $availableStock .
                    " unit(s) are currently available. Your cart was not changed.";

                $_SESSION["cart_message_type"] = "error";
            } else {
                $_SESSION["cart"][$productId] =
                    $currentQuantity + $quantity;

                $_SESSION["cart_message"] =
                    $product["name"] . " added to your cart.";

                $_SESSION["cart_message_type"] = "success";
            }
        }
    }
} catch (PDOException $exception) {
    error_log("HumanMatrix add-to-cart error: " . $exception->getMessage());

    $_SESSION["cart_message"] =
        "We couldn't add this product right now. Please try again.";

    $_SESSION["cart_message_type"] = "error";
}

header("Location: ../customer/product.php");
exit;
