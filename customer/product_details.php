<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Messages from the cart (e.g. "Added to cart") are shown on the catalogue
// and cart pages, so they are left untouched here.

require_once "../config/database.php";
require_once "../includes/notifications.php";

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$productId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    header("Location: product.php");
    exit;
}

$product = false;
$loadError = false;
$unreadCount = 0;

try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.description, p.specifications, p.price,
               p.stock_quantity, p.image, c.name AS category_name
        FROM products p
        INNER JOIN categories c ON p.category_id = c.id
        WHERE p.id = :id AND p.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([":id" => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    $unreadCount = unreadNotificationCount($pdo, (int) $_SESSION["user_id"]);
} catch (PDOException $exception) {
    error_log("HumanMatrix product details error: " . $exception->getMessage());
    $loadError = true;
}

$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;

$imageUrl = "";
$specLines = [];
$inStock = false;

if ($product) {
    $imagePath = trim((string) ($product["image"] ?? ""));

    if (
        $imagePath !== "" &&
        !str_contains($imagePath, "..") &&
        !str_contains($imagePath, "\\") &&
        preg_match('#\Aassets/images/products/[a-zA-Z0-9._-]+\z#', $imagePath) &&
        is_file(__DIR__ . "/../" . $imagePath)
    ) {
        $imageUrl = "../" . $imagePath;
    }

    // Specifications are typed by the admin, one per line. A line written as
    // "Label: value" is shown as a table row; any other line is a plain row.
    $rawSpecs = str_replace(["\r\n", "\r"], "\n", (string) ($product["specifications"] ?? ""));

    foreach (explode("\n", $rawSpecs) as $line) {
        $line = trim($line);

        if ($line === "") {
            continue;
        }

        $parts = explode(":", $line, 2);

        if (count($parts) === 2 && trim($parts[0]) !== "" && trim($parts[1]) !== "") {
            $specLines[] = [trim($parts[0]), trim($parts[1])];
        } else {
            $specLines[] = [null, $line];
        }
    }

    $inStock = (int) $product["stock_quantity"] > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $product ? e($product["name"]) : "Product" ?> | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="dashboard.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="dashboard.php">Dashboard</a>
        <a class="active" href="product.php">Equipment</a>
        <a href="../cart/view.php">Cart<?= $cartCount > 0 ? " (" . $cartCount . ")" : "" ?></a>
        <a href="orders.php">My Orders</a>
        <a href="notifications.php">Notifications<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">

        <p><a href="product.php">&larr; Back to all equipment</a></p>

        <?php if ($loadError): ?>
            <div class="catalogue-message">
                We couldn't load this product. Please try again later.
            </div>

        <?php elseif (!$product): ?>
            <div class="catalogue-message">
                <h3>Product not found</h3>
                <p>This item is no longer available.
                   <a href="product.php">Browse the catalogue</a>.</p>
            </div>

        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:28px; align-items:start;">

                <div>
                    <?php if ($imageUrl !== ""): ?>
                        <img src="<?= e($imageUrl) ?>" alt="<?= e($product["name"]) ?>"
                             style="width:100%; max-height:420px; object-fit:contain; border-radius:10px; background:#f4f7fb;">
                    <?php else: ?>
                        <div class="catalogue-image-placeholder" style="min-height:260px; border-radius:10px;">
                            <span>Medical Equipment</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <p class="catalogue-category"><?= e($product["category_name"]) ?></p>
                    <h2 style="margin:4px 0 12px;"><?= e($product["name"]) ?></h2>

                    <p class="catalogue-price" style="font-size:1.5em; margin:0 0 6px;">
                        KSh <?= number_format((float) $product["price"], 2) ?>
                    </p>

                    <p>
                        <span class="stock-badge <?= $inStock ? "in-stock" : "out-stock" ?>" style="position:static;">
                            <?= $inStock ? "In Stock" : "Out of Stock" ?>
                        </span>
                        <span class="stock-quantity">
                            <?= (int) $product["stock_quantity"] ?> available
                        </span>
                    </p>

                    <form action="../cart/add.php" method="POST" class="cart-add-form">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="product_id" value="<?= (int) $product["id"] ?>">

                        <label for="quantity">Quantity</label>
                        <input type="number" id="quantity" name="quantity" value="1"
                               min="1" max="<?= (int) $product["stock_quantity"] ?>"
                               required <?= !$inStock ? "disabled" : "" ?>>

                        <button type="submit" <?= !$inStock ? "disabled" : "" ?>>
                            <?= $inStock ? "Add to Cart" : "Out of Stock" ?>
                        </button>
                    </form>
                </div>
            </div>

            <h3 style="margin-top:32px;">Description</h3>
            <p style="white-space:pre-line;"><?php
                $description = trim((string) ($product["description"] ?? ""));
                echo $description !== ""
                    ? e($description)
                    : "Contact HumanMatrix for more information about this product.";
            ?></p>

            <h3 style="margin-top:24px;">Specifications</h3>
            <?php if (empty($specLines)): ?>
                <p>No specifications have been listed for this product.</p>
            <?php else: ?>
                <table style="width:100%; max-width:700px; border-collapse:collapse;">
                    <tbody>
                        <?php foreach ($specLines as [$label, $value]): ?>
                            <tr>
                                <?php if ($label !== null): ?>
                                    <th style="text-align:left; padding:10px; border-bottom:1px solid #e5eaf0; width:35%; background:#f4f7fb;">
                                        <?= e($label) ?>
                                    </th>
                                    <td style="padding:10px; border-bottom:1px solid #e5eaf0;"><?= e($value) ?></td>
                                <?php else: ?>
                                    <td colspan="2" style="padding:10px; border-bottom:1px solid #e5eaf0;"><?= e($value) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>