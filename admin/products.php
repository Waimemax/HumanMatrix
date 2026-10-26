
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION["role"] !== "admin") {
    header("Location: ../customer/dashboard.php");
    exit;
}

require_once "../config/database.php";

// Create a CSRF token to protect form submissions.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// Handle all product actions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid form submission. Please refresh and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "add") {
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
                $image = null;
                $specifications = trim($_POST["specifications"] ?? "");

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
                    $stock < 0
                ) {
                    throw new RuntimeException(
                        "Enter a valid name, category, price and stock quantity."
                    );
                }

            // Handle image upload manually if necessary.
                /*if (strlen($image) > 255) {
                 throw new RuntimeException(
                "The image path must not exceed 255 characters."
                 );
                }*/

                $check = $pdo->prepare(
                    "SELECT id FROM categories WHERE id = ?"
                );
                $check->execute([$category_id]);

                if (!$check->fetch()) {
                    throw new RuntimeException(
                        "Please select a valid category."
                    );
                }
            //checks upload error and file size limit 2MB
            $upload = $_FILES["image"] ?? null;

            if ($upload !== null && $upload["error"] !== UPLOAD_ERR_NO_FILE) {
            if ($upload["error"] !== UPLOAD_ERR_OK) {
              throw new RuntimeException("The image upload failed. Please try again.");
             }

             if ($upload["size"] > 2 * 1024 * 1024) {
               throw new RuntimeException("The image must not exceed 2 MB.");
            }

             if (!is_uploaded_file($upload["tmp_name"])) {
              throw new RuntimeException("Invalid image upload.");
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
             $mime = $finfo->file($upload["tmp_name"]);

              $allowedTypes = [
               "image/jpeg" => "jpg",
               "image/png"  => "png",
             "image/webp" => "webp"
          ];

            if (!isset($allowedTypes[$mime])) {
               throw new RuntimeException("Only JPG, PNG and WEBP images are allowed.");
            }

             if (@getimagesize($upload["tmp_name"]) === false) {
               throw new RuntimeException("The uploaded file is not a valid image.");
           }

            $uploadDirectory = __DIR__ . "/../assets/images/products/";

         if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
        throw new RuntimeException("The product image folder is not writable.");
         }

        $filename = bin2hex(random_bytes(16)) . "." . $allowedTypes[$mime];
         $destination = $uploadDirectory . $filename;

         if (!move_uploaded_file($upload["tmp_name"], $destination)) {
        throw new RuntimeException("Unable to save the uploaded image.");
        }

       $image = "assets/images/products/" . $filename;
        }

                $stmt = $pdo->prepare(
                    "INSERT INTO products
                    (category_id, name, description, price,
                     stock_quantity, image, specifications, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
                );

                $stmt->execute([
                    $category_id,
                    $name,
                    $description !== "" ? $description : null,
                    $price,
                    $stock,
                    $image,
                    $specifications !== "" ? $specifications : null
                ]);

                header("Location: products.php?added=1");
                exit;
            }
            if ($action === "toggle") {
                $id = filter_var(
                    $_POST["product_id"] ?? "",
                    FILTER_VALIDATE_INT
                );
                $new_status = $_POST["new_status"] ?? "";

                if (
                    $id === false || $id === null || $id < 1 ||
                    !in_array($new_status, ["active", "inactive"], true)
                ) {
                    throw new RuntimeException("Invalid product or status.");
                }

                $stmt = $pdo->prepare(
                    "UPDATE products SET status = ? WHERE id = ?"
                );
                $stmt->execute([$new_status, $id]);

                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare(
                        "SELECT id, status FROM products WHERE id = ?"
                    );
                    $check->execute([$id]);
                    $product = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        throw new RuntimeException("Product not found.");
                    }

                    if ($product["status"] !== $new_status) {
                        throw new RuntimeException(
                            "Unable to update the product status."
                        );
                    }
                }

                header("Location: products.php?updated=1");
                exit;
            }

            if ($action === "delete") {
                $id = filter_var(
                    $_POST["product_id"] ?? "",
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id === null || $id < 1) {
                    throw new RuntimeException("Invalid product ID.");
                }

                // Keep products that are referenced by order history.
                $check = $pdo->prepare(
                    "SELECT id FROM order_items WHERE product_id = ? LIMIT 1"
                );
                $check->execute([$id]);

                if ($check->fetch()) {
                    throw new RuntimeException(
                        "This product is linked to an order. Deactivate it instead."
                    );
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM products WHERE id = ?"
                );
                $stmt->execute([$id]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException("Product not found.");
                }

                header("Location: products.php?deleted=1");
                exit;
            }

            throw new RuntimeException("Unknown product action.");
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "The operation could not be completed. Check the product details and try again.";
            error_log("Products management database error: " . $e->getMessage());
        }
    }
}

// Load categories for the product form.
$categoryStmt = $pdo->query(
    "SELECT id, name FROM categories ORDER BY name ASC"
);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Load products and their category names.
$productStmt = $pdo->query(
    "SELECT p.id, p.name, p.description, p.price,
            p.stock_quantity, p.image, p.status, p.created_at,
            c.name AS category_name
     FROM products p
     INNER JOIN categories c ON p.category_id = c.id
     ORDER BY p.id DESC"
);
$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Management - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Products Management</h1>
            <p>Manage medical equipment, prices and inventory.</p>
        </header>

        <?php if (isset($_GET["added"])): ?>
            <p class="notice success">Product added successfully.</p>
        <?php elseif (isset($_GET["updated"])): ?>
            <p class="notice success">Product status updated.</p>
        <?php elseif (isset($_GET["deleted"])): ?>
            <p class="notice success">Product deleted successfully.</p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-form-section">
            <h2>Add a New Product</h2>

            <?php if (count($categories) === 0): ?>
                <p>No categories are available. Add a category first.</p>
            <?php else: ?>

            <form method="POST" action="products.php"
      class="product-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Product Name *</label>
                        <input type="text" id="name" name="name"
                               maxlength="150" required>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category *</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category["id"] ?>">
                                    <?= e($category["name"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="price">Price (KES) *</label>
                        <input type="number" id="price" name="price"
                               min="0" max="9999999999.99"
                               step="0.01" required>
                    </div>

                    <div class="form-group">
                        <label for="stock_quantity">Stock Quantity *</label>
                        <input type="number" id="stock_quantity"
                               name="stock_quantity" min="0"
                               max="4294967295" step="1" required>
                    </div>

                    <div class="form-group">
                      <label for="image">Product Image (optional)</label>
                       <input type="file" id="image" name="image"
                       accept="image/jpeg,image/png,image/webp">
                      <small>Allowed formats: JPG, PNG, WEBP. Maximum size: 2 MB.</small>
                    </div>

                    <div class="form-group full-width">
                        <label for="description">Description</label>
                        <textarea id="description" name="description"
                                  rows="3"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="specifications">Specifications</label>
                        <textarea id="specifications" name="specifications"
                                  rows="3"
                                  placeholder="Brand, model, dimensions, features..."></textarea>
                    </div>
                </div>

                <button type="submit" class="primary-button">
                    Add Product
                </button>
            </form>

            <?php endif; ?>
        </section>

        <section class="product-list-section">
            <h2>Product Inventory</h2>

            <p>Total products: <strong><?= count($products) ?></strong></p>

            <?php if (count($products) === 0): ?>
                <p>No products have been added yet.</p>
            <?php else: ?>

            <div class="table-wrapper">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= (int) $product["id"] ?></td>
                            <td>
                                <strong><?= e($product["name"]) ?></strong>
                                <?php if (!empty($product["description"])): ?>
                                    <p><?= e($product["description"]) ?></p>
                                <?php endif; ?>
                            </td>
                            <td><?= e($product["category_name"]) ?></td>
                            <td>KES <?= number_format((float) $product["price"], 2) ?></td>
                            <td>
                                <?php if ((int) $product["stock_quantity"] === 0): ?>
                                    <span class="stock-empty">Out of stock</span>
                                <?php else: ?>
                                    <?= (int) $product["stock_quantity"] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-label <?= e($product["status"]) ?>">
                                    <?= e(ucfirst($product["status"])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="product-actions">
                                    <a class="secondary-button"
   href="edit_product.php?id=<?= (int) $product["id"] ?>">
    Edit
</a>
                                    <form method="POST" action="products.php">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION["csrf_token"]) ?>">
                                        <input type="hidden" name="action"
                                               value="toggle">
                                        <input type="hidden" name="product_id"
                                               value="<?= (int) $product["id"] ?>">
                                        <input type="hidden" name="new_status"
                                               value="<?= $product["status"] === "active" ? "inactive" : "active" ?>">
                                        <button type="submit" class="secondary-button">
                                            <?= $product["status"] === "active" ? "Deactivate" : "Activate" ?>
                                        </button>
                                    </form>

                                    <form method="POST" action="products.php"
                                          onsubmit="return confirm('Are you sure you want to delete this product?');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION["csrf_token"]) ?>">
                                        <input type="hidden" name="action"
                                               value="delete">
                                        <input type="hidden" name="product_id"
                                               value="<?= (int) $product["id"] ?>">
                                        <button type="submit" class="delete-button">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>
        </section>

    </main>
</div>

</body>
</html>
