
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

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$id = filter_var($_GET["id"] ?? "", FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    header("Location: products.php");
    exit;
}

$error = "";

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: products.php");
    exit;
}

$categoryStmt = $pdo->query(
    "SELECT id, name FROM categories ORDER BY name ASC"
);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $error = "Invalid form submission. Refresh and try again.";
    } else {
        $name = trim($_POST["name"] ?? "");
        $category_id = filter_var(
            $_POST["category_id"] ?? "",
            FILTER_VALIDATE_INT
        );
        $description = trim($_POST["description"] ?? "");
        $price = trim($_POST["price"] ?? "");
        $stock = filter_var(
            $_POST["stock_quantity"] ?? "",
            FILTER_VALIDATE_INT
        );
        $image = trim($_POST["image"] ?? "");
        $specifications = trim($_POST["specifications"] ?? "");
        $status = $_POST["status"] ?? "";

        if (
            $name === "" ||
            strlen($name) > 150 ||
            $category_id === false ||
            $category_id === null ||
            $category_id < 1 ||
            !is_numeric($price) ||
            !is_finite((float) $price) ||
            (float) $price < 0 ||
            !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $price) ||
            $stock === false ||
            $stock === null ||
            $stock < 0 ||
            !in_array($status, ["active", "inactive"], true) ||
            strlen($image) > 255
        ) {
            $error = "Please enter valid product details in all required fields.";
        } else {
            $check = $pdo->prepare(
                "SELECT id FROM categories WHERE id = ?"
            );
            $check->execute([$category_id]);

            if (!$check->fetch()) {
                $error = "Please select a valid category.";
            } else {
                try {
                    $update = $pdo->prepare(
                        "UPDATE products
                         SET category_id = ?,
                             name = ?,
                             description = ?,
                             price = ?,
                             stock_quantity = ?,
                             image = ?,
                             specifications = ?,
                             status = ?
                         WHERE id = ?"
                    );

                    $update->execute([
                        $category_id,
                        $name,
                        $description !== "" ? $description : null,
                        $price,
                        $stock,
                        $image !== "" ? $image : null,
                        $specifications !== "" ? $specifications : null,
                        $status,
                        $id
                    ]);

                    header("Location: products.php?edited=1");
                    exit;
                } catch (PDOException $ex) {
                    error_log("Product update error: " . $ex->getMessage());
                    $error = "Unable to update the product. Please try again.";
                }
            }
        }

        // Keep submitted values visible if validation fails.
        $product["name"] = $name;
        $product["category_id"] = $category_id ?: $product["category_id"];
        $product["description"] = $description;
        $product["price"] = $price;
        $product["stock_quantity"] = $stock !== false && $stock !== null
            ? $stock : $product["stock_quantity"];
        $product["image"] = $image;
        $product["specifications"] = $specifications;
        $product["status"] = $status;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Edit Product</h1>
            <p>Update the medical equipment product details below.</p>
        </header>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-form-section">
            <h2>Product Details</h2>

            <form method="POST"
                  action="edit_product.php?id=<?= (int) $id ?>"
                  class="product-form">

                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION["csrf_token"]) ?>">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="name">Product Name *</label>
                        <input type="text" id="name" name="name"
                               maxlength="150"
                               value="<?= e($product["name"]) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category *</label>
                        <select id="category_id" name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category["id"] ?>"
                                    <?= (int) $product["category_id"] === (int) $category["id"] ? "selected" : "" ?>>
                                    <?= e($category["name"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="price">Price (KES) *</label>
                        <input type="number" id="price" name="price"
                               min="0" max="9999999999.99" step="0.01"
                               value="<?= e($product["price"]) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="stock_quantity">Stock Quantity *</label>
                        <input type="number" id="stock_quantity"
                               name="stock_quantity" min="0"
                               max="4294967295" step="1"
                               value="<?= e($product["stock_quantity"]) ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="image">Image Path (optional)</label>
                        <input type="text" id="image" name="image"
                               maxlength="255"
                               value="<?= e($product["image"]) ?>">
                    </div>

                    <div class="form-group">
                        <label for="status">Product Status *</label>
                        <select id="status" name="status" required>
                            <option value="active"
                                <?= $product["status"] === "active" ? "selected" : "" ?>>
                                Active
                            </option>
                            <option value="inactive"
                                <?= $product["status"] === "inactive" ? "selected" : "" ?>>
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="description">Description</label>
                        <textarea id="description" name="description"
                                  rows="3"><?= e($product["description"]) ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="specifications">Specifications</label>
                        <textarea id="specifications" name="specifications"
                                  rows="3"><?= e($product["specifications"]) ?></textarea>
                    </div>

                </div>

                <button type="submit" class="primary-button">
                    Save Changes
                </button>

                <a href="products.php" class="cancel-link">Cancel</a>
            </form>
        </section>

    </main>
</div>

</body>
</html>
