<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

// Create a security token for cart forms.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Retrieve any cart message from the previous request.
$cartMessage = $_SESSION["cart_message"] ?? "";
$cartMessageType = $_SESSION["cart_message_type"] ?? "success";

unset($_SESSION["cart_message"], $_SESSION["cart_message_type"]);

require_once "../config/database.php";
require_once "../includes/notifications.php";

$unreadCount = unreadNotificationCount($pdo, (int) ($_SESSION["user_id"] ?? 0));
$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}


$products = [];
$categories = [];
$error = false;

// Get the selected category from the URL.
$selectedCategory = filter_input(
    INPUT_GET,
    "category",
    FILTER_VALIDATE_INT
);

if ($selectedCategory === false || $selectedCategory === null || $selectedCategory < 1) {
    $selectedCategory = 0;
}

// Get and clean the customer's search term.
$searchTerm = trim($_GET["search"] ?? "");

// Limit search length.
$searchTerm = substr($searchTerm, 0, 100);

try {
    // Load categories dynamically from the database.
    $categoryStmt = $pdo->query("
        SELECT id, name
        FROM categories
        ORDER BY name ASC
    ");

    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    
    // Load active products with optional category and search filters.
    $sql = "
        SELECT
            p.id,
            p.name,
            p.description,
            p.price,
            p.stock_quantity,
            p.image,
            c.name AS category_name
        FROM products p
        INNER JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'active'
    ";

    $params = [];

    if ($selectedCategory > 0) {
        $sql .= " AND p.category_id = :category_id";
        $params[":category_id"] = $selectedCategory;
    }

    if ($searchTerm !== "") {
        $sql .= "
            AND (
                p.name LIKE :search_name
                OR p.description LIKE :search_description
                OR c.name LIKE :search_category
            )
        ";

        $searchValue = "%" . $searchTerm . "%";
        $params[":search_name"] = $searchValue;
        $params[":search_description"] = $searchValue;
        $params[":search_category"] = $searchValue;
    }

    $sql .= " ORDER BY p.created_at DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("HumanMatrix catalogue error: " . $exception->getMessage());
    $error = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Equipment | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="../customer/dashboard.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="dashboard.php">Dashboard</a>
        <a class="active" href="product.php">Equipment</a>
        <a href="../cart/view.php">Cart<?= $cartCount > 0 ? " (" . $cartCount . ")" : "" ?></a>
        <a href="orders.php">My Orders</a>
        <a href="notifications.php">Notifications<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
        <a href="profile.php">Profile</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">

    <section class="catalogue-hero">
        <p class="catalogue-eyebrow">HUMAN MATRIX MEDICAL SUPPLIES</p>
        <h1>Medical Equipment Catalogue</h1>
        <p>
            Explore medical equipment and supplies for your healthcare facility.
            View product details, prices and current stock availability.
        </p>
    </section>

    <section class="catalogue-section">

    <?php if ($cartMessage !== ""): ?>
    <div class="catalogue-message" role="status">
        <?= e($cartMessage) ?>
    </div>
<?php endif; ?>

<div class="catalogue-search">
    <form method="GET" action="product.php">
        <label for="product-search">Search medical equipment</label>

        <div class="catalogue-search-controls">
            <input
                type="search"
                id="product-search"
                name="search"
                placeholder="e.g. hospital bed, wheelchair..."
                value="<?= e($searchTerm) ?>"
                maxlength="100"
            >

            <?php if ($selectedCategory > 0): ?>
                <input
                    type="hidden"
                    name="category"
                    value="<?= (int) $selectedCategory ?>"
                >
            <?php endif; ?>

            <button type="submit">Search</button>

            <?php if ($searchTerm !== ""): ?>
                <a href="product.php<?= $selectedCategory > 0 ? "?category=" . (int) $selectedCategory : "" ?>">
                    Clear search
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>


<div class="catalogue-filter">
    <form method="GET" action="product.php">
        <label for="category">Filter by category</label>

        <select
            name="category"
            id="category"
            onchange="this.form.submit()"
        >
            <option value="0">All Categories</option>

            <?php foreach ($categories as $category): ?>
                <option
                    value="<?= (int) $category["id"] ?>"
                    <?= $selectedCategory === (int) $category["id"] ? "selected" : "" ?>
                >
                    <?= e($category["name"]) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <noscript>
            <button type="submit">Apply Filter</button>
        </noscript>
    </form>
</div>

        <div class="catalogue-section-heading">
            <div>
                <h2>Available Equipment</h2>
                <p>Browse our currently active products.</p>
            </div>

            <span class="catalogue-count">
                <?= count($products) ?>
                <?= count($products) === 1 ? "product" : "products" ?>
            </span>
        </div>

        <?php if ($error): ?>

            <div class="catalogue-message">
                We couldn't load the equipment catalogue. Please try again later.
            </div>

        <?php elseif (empty($products)): ?>

            <div class="catalogue-message">
                <h3>No products available yet</h3>
                <p>
                    Products added by the HumanMatrix administrator
                    will appear here.
                </p>
            </div>

        <?php else: ?>

            <div class="catalogue-grid">

                <?php foreach ($products as $product): ?>
                    <?php
                    $imagePath = trim((string) ($product["image"] ?? ""));
                    $imageUrl = "";

                    if (
                        $imagePath !== "" &&
                        !str_contains($imagePath, "..") &&
                        !str_contains($imagePath, "\\") &&
                        preg_match(
                            '#\Aassets/images/products/[a-zA-Z0-9._-]+\z#',
                            $imagePath
                        )
                    ) {
                        $candidate = __DIR__ . "/../" . $imagePath;

                        if (is_file($candidate)) {
                            $imageUrl = "../" . $imagePath;
                        }
                    }

                    $inStock = (int) $product["stock_quantity"] > 0;
                    ?>

                    <article class="catalogue-card">

                        <div class="catalogue-image-wrap">
                            <?php if ($imageUrl !== ""): ?>
                                <img
                                    class="catalogue-image"
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($product["name"]) ?>"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="catalogue-image-placeholder">
                                    <span>Medical Equipment</span>
                                </div>
                            <?php endif; ?>

                            <span class="stock-badge <?= $inStock ? "in-stock" : "out-stock" ?>">
                                <?= $inStock ? "In Stock" : "Out of Stock" ?>
                            </span>
                        </div>

                        <div class="catalogue-card-body">
                            <p class="catalogue-category">
                                <?= e($product["category_name"]) ?>
                            </p>

                            <h3>
                                <a href="product_details.php?id=<?= (int) $product["id"] ?>"
                                   style="color:inherit; text-decoration:none;"><?= e($product["name"]) ?></a>
                            </h3>

                            <p class="catalogue-description">
                                <?php
                                $description = trim(
                                    (string) ($product["description"] ?? "")
                                );

                                echo $description !== ""
                                    ? e($description)
                                    : "Contact HumanMatrix for more information about this product.";
                                ?>
                            </p>

                            <p style="margin:0 0 10px;">
                                <a href="product_details.php?id=<?= (int) $product["id"] ?>">View details &amp; specifications</a>
                            </p>

                            <div class="catalogue-card-footer">
                                <div>
                                    <span class="price-label">Price</span>
                                    <p class="catalogue-price">
                                        KSh <?= number_format((float) $product["price"], 2) ?>
                                    </p>
                                </div>

                                <span class="stock-quantity">
                                    <?= (int) $product["stock_quantity"] ?> available
                                </span>
                            </div>
                        </div>
                        <form action="../cart/add.php" method="POST" class="cart-add-form">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($_SESSION["csrf_token"]) ?>"
    >

    <input
        type="hidden"
        name="product_id"
        value="<?= (int) $product["id"] ?>"
    >

    <label for="quantity-<?= (int) $product["id"] ?>">
        Quantity
    </label>

    <input
        type="number"
        id="quantity-<?= (int) $product["id"] ?>"
        name="quantity"
        value="1"
        min="1"
        max="<?= (int) $product["stock_quantity"] ?>"
        required
        <?= !$inStock ? "disabled" : "" ?>
    >

    <button type="submit" <?= !$inStock ? "disabled" : "" ?>>
        <?= $inStock ? "Add to Cart" : "Out of Stock" ?>
    </button>
</form>

                    </article>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </section>

</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>