
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

$message = "";
$message_type = "success";

// Delete a category
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_category_id"])) {

    $category_id = filter_var(
        $_POST["delete_category_id"],
        FILTER_VALIDATE_INT
    );

    if (!$category_id || $category_id < 1) {
        header("Location: categories.php?error=invalid");
        exit;
    }

    try {
        // Check whether any products use this category
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM products WHERE category_id = ?"
        );
        $check->execute([$category_id]);

        if ((int) $check->fetchColumn() > 0) {
            header("Location: categories.php?error=inuse");
            exit;
        }

        $delete = $pdo->prepare(
            "DELETE FROM categories WHERE id = ?"
        );
        $delete->execute([$category_id]);

        if ($delete->rowCount() > 0) {
            header("Location: categories.php?deleted=1");
        } else {
            header("Location: categories.php?error=notfound");
        }
        exit;

    } catch (PDOException $e) {
        header("Location: categories.php?error=failed");
        exit;
    }
}

// Add a new category
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($name === "") {
        $message = "Category name is required.";
        $message_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO categories (name, description)
                 VALUES (?, ?)"
            );

            $stmt->execute([
                $name,
                $description !== "" ? $description : null
            ]);

            header("Location: categories.php?added=1");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $message = "That category already exists.";
            } else {
                $message = "Unable to save the category. Please try again.";
            }

            $message_type = "error";
        }
    }
}

if (isset($_GET["added"])) {
    $message = "Category added successfully.";
} elseif (isset($_GET["deleted"])) {
    $message = "Category deleted successfully.";
} elseif (isset($_GET["error"])) {
    $message_type = "error";

    switch ($_GET["error"]) {
        case "inuse":
            $message = "This category cannot be deleted because products are assigned to it.";
            break;
        case "invalid":
            $message = "Invalid category selected.";
            break;
        case "notfound":
            $message = "Category not found or already deleted.";
            break;
        default:
            $message = "Unable to delete the category.";
    }
}

// Fetch categories from the database
$stmt = $pdo->query(
    "SELECT id, name, description, created_at
     FROM categories
     ORDER BY id DESC"
);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>
        .category-form,
        .category-table-container {
            background: white;
            padding: 24px;
            border-radius: 10px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .category-form label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        .category-form input,
        .category-form textarea {
            width: 100%;
            max-width: 600px;
            padding: 11px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
            font: inherit;
        }

        .category-form button {
            margin-top: 18px;
            padding: 11px 18px;
            background: #2476a8;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        .category-table {
            width: 100%;
            border-collapse: collapse;
        }

        .category-table th,
        .category-table td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e5eaf0;
        }

        .category-table th {
            background: #f4f7fb;
        }

        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .message.success {
            background: #e4f7eb;
            color: #17663a;
        }

        .message.error {
            background: #fde8e7;
            color: #a3211c;
        }

        .table-scroll {
            overflow-x: auto;
        }
    </style>
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1>Categories Management</h1>
            <p>Create and organize medical equipment categories.</p>
        </header>

        <?php if ($message !== ""): ?>
            <div class="message <?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="category-form">
            <h2>Add New Category</h2>

            <form method="POST" action="categories.php">
                <label for="name">Category Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    required
                    placeholder="e.g. Hospital Beds"
                >

                <label for="description">Description (optional)</label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    placeholder="Describe the equipment in this category"
                ></textarea>

                <button type="submit">Add Category</button>
            </form>
        </section>

        <section class="category-table-container">
            <h2>Existing Categories (<?= count($categories) ?>)</h2>

            <?php if (count($categories) > 0): ?>
                <div class="table-scroll">
                    <table class="category-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Date Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?= (int) $category["id"] ?></td>
                                    <td><?= htmlspecialchars($category["name"]) ?></td>
                                    <td>
                                        <?= htmlspecialchars(
                                            $category["description"] ?? ""
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars(
                                            $category["created_at"] ?? ""
                                        ) ?>
                                    </td>
                                    <td>
                                    <form method="POST"
                                    action="categories.php"
                                    onsubmit="return confirm('Are you sure you want to delete this category?');">

                           <input
                           type="hidden"
                           name="delete_category_id"
                           value="<?= (int) $category['id'] ?>"
                          >

                        <button
                        type="submit"
                        style="background:#c0392b;color:white;border:0;padding:8px 12px;border-radius:5px;cursor:pointer;"
                        >
                        Delete
                        </button>
                        </form>
                        </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No categories have been added yet. Use the form above to create your first category.</p>
            <?php endif; ?>
        </section>
    </main>

</div>

</body>
</html>
