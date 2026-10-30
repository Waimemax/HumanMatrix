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

// Create a CSRF token to protect form submissions.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid form submission. Please refresh and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "add") {
                $name = trim((string) ($_POST["name"] ?? ""));
                $phone = trim((string) ($_POST["phone"] ?? ""));
                $address = trim((string) ($_POST["address"] ?? ""));

                if ($name === "" || mb_strlen($name) > 150) {
                    throw new RuntimeException(
                        "Enter a facility name (up to 150 characters)."
                    );
                }

                if ($phone !== "" && !preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) {
                    throw new RuntimeException("Enter a valid phone number or leave it blank.");
                }

                if (mb_strlen($address) > 500) {
                    throw new RuntimeException("The address must be 500 characters or fewer.");
                }

                $check = $pdo->prepare("SELECT id FROM facilities WHERE name = ? LIMIT 1");
                $check->execute([$name]);

                if ($check->fetch()) {
                    throw new RuntimeException("A facility with that name already exists.");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO facilities (name, phone, address, status)
                    VALUES (?, ?, ?, 'active')
                ");
                $stmt->execute([
                    $name,
                    $phone !== "" ? $phone : null,
                    $address !== "" ? $address : null,
                ]);

                header("Location: facilities.php?added=1");
                exit;
            }

            if ($action === "toggle") {
                $id = filter_var($_POST["facility_id"] ?? "", FILTER_VALIDATE_INT);
                $newStatus = $_POST["new_status"] ?? "";

                if (
                    $id === false || $id === null || $id < 1 ||
                    !in_array($newStatus, ["active", "inactive"], true)
                ) {
                    throw new RuntimeException("Invalid facility or status.");
                }

                $stmt = $pdo->prepare("UPDATE facilities SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $id]);

                header("Location: facilities.php?updated=1");
                exit;
            }

            throw new RuntimeException("Unknown facility action.");
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log("Facilities management error: " . $exception->getMessage());
            $error = "The operation could not be completed. Please try again.";
        }
    }
}

$facilities = [];

try {
    $facilities = $pdo->query("
        SELECT id, name, phone, address, status, created_at
        FROM facilities
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Facilities list error: " . $exception->getMessage());
    $error = $error !== "" ? $error : "The facilities could not be loaded. Has the SQL been run?";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facilities Management - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">

        <header class="admin-header">
            <h1>Facilities Management</h1>
            <p>Manage the healthcare facilities customers can order for.</p>
        </header>

        <?php if (isset($_GET["added"])): ?>
            <p class="notice success">Facility added successfully.</p>
        <?php elseif (isset($_GET["updated"])): ?>
            <p class="notice success">Facility status updated.</p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <section class="product-form-section">
            <h2>Add a Facility</h2>

            <form method="POST" action="facilities.php" class="product-form">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Facility Name *</label>
                        <input type="text" id="name" name="name"
                               maxlength="150" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" maxlength="20">
                    </div>

                    <div class="form-group full-width">
                        <label for="address">Delivery Address</label>
                        <textarea id="address" name="address"
                                  rows="2" maxlength="500"></textarea>
                    </div>
                </div>

                <button type="submit" class="primary-button">Add Facility</button>
            </form>
        </section>

        <section class="product-list-section">
            <h2>Facilities</h2>
            <p>Total facilities: <strong><?= count($facilities) ?></strong></p>

            <?php if (count($facilities) === 0): ?>
                <p>No facilities have been added yet.</p>
            <?php else: ?>

            <div class="table-wrapper">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facilities as $facility): ?>
                        <tr>
                            <td><?= (int) $facility["id"] ?></td>
                            <td><strong><?= e($facility["name"]) ?></strong></td>
                            <td><?= e($facility["phone"] ?? "") ?></td>
                            <td><?= e($facility["address"] ?? "") ?></td>
                            <td>
                                <span class="status-label <?= e($facility["status"]) ?>">
                                    <?= e(ucfirst($facility["status"])) ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="facilities.php">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION["csrf_token"]) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="facility_id"
                                           value="<?= (int) $facility["id"] ?>">
                                    <input type="hidden" name="new_status"
                                           value="<?= $facility["status"] === "active" ? "inactive" : "active" ?>">
                                    <button type="submit" class="secondary-button">
                                        <?= $facility["status"] === "active" ? "Deactivate" : "Activate" ?>
                                    </button>
                                </form>
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