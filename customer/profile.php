
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/notifications.php";
require_once "../includes/profile.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$userId = (int) $_SESSION["user_id"];

$profileError = "";
$passwordError = "";
$flash = $_SESSION["profile_flash"] ?? "";
unset($_SESSION["profile_flash"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $profileError = "Invalid form submission. Please refresh and try again.";
    } elseif ($action === "update_profile") {
        $error = updateProfile(
            $pdo,
            $userId,
            (string) ($_POST["full_name"] ?? ""),
            (string) ($_POST["phone"] ?? "")
        );

        if ($error === null) {
            $_SESSION["profile_flash"] = "Your details have been saved.";
            header("Location: profile.php");
            exit;
        }

        $profileError = $error;
    } elseif ($action === "change_password") {
        $error = changePassword(
            $pdo,
            $userId,
            (string) ($_POST["current_password"] ?? ""),
            (string) ($_POST["new_password"] ?? ""),
            (string) ($_POST["confirm_password"] ?? "")
        );

        if ($error === null) {
            createNotification(
                $pdo,
                $userId,
                "Password changed",
                "Your Human Matrix password was changed. If this was not you, contact HumanMatrix immediately.",
                "account"
            );

            $_SESSION["profile_flash"] = "Your password has been changed.";
            header("Location: profile.php");
            exit;
        }

        $passwordError = $error;
    }
}

$user = null;
$loadError = false;

try {
    $user = loadProfile($pdo, $userId);
    $unreadCount = unreadNotificationCount($pdo, $userId);
} catch (PDOException $exception) {
    error_log("HumanMatrix profile load error: " . $exception->getMessage());
    $loadError = true;
    $unreadCount = 0;
}

$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;

// Show what the customer just typed if a save failed.
$nameValue = $_SERVER["REQUEST_METHOD"] === "POST" && $profileError !== ""
    ? (string) ($_POST["full_name"] ?? "") : (string) ($user["full_name"] ?? "");
$phoneValue = $_SERVER["REQUEST_METHOD"] === "POST" && $profileError !== ""
    ? (string) ($_POST["phone"] ?? "") : (string) ($user["phone"] ?? "");

$boxStyle = "max-width:560px; padding:20px; margin-bottom:24px; border:1px solid #e5eaf0; border-radius:10px; background:#fff;";
$inputStyle = "width:100%; padding:10px; border:1px solid #ccd5df; border-radius:6px; font:inherit; box-sizing:border-box;";
$labelStyle = "display:block; margin:12px 0 4px; font-weight:bold;";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="dashboard.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="product.php">Equipment</a>
        <a href="../cart/view.php">Cart<?= $cartCount > 0 ? " (" . $cartCount . ")" : "" ?></a>
        <a href="orders.php">My Orders</a>
        <a href="notifications.php">Notifications<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
        <a class="active" href="profile.php">Profile</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">
        <div class="catalogue-section-heading">
            <div>
                <h2>My profile</h2>
                <p>Update your details and change your password.</p>
            </div>
        </div>

        <?php if ($flash !== ""): ?>
            <div class="catalogue-message" role="status" style="border-left:4px solid #15803d;"><?= e($flash) ?></div>
        <?php endif; ?>

        <?php if ($loadError || !$user): ?>
            <div class="catalogue-message">We couldn't load your profile. Please try again later.</div>
        <?php else: ?>

            <div style="<?= $boxStyle ?>">
                <h3 style="margin-top:0;">Your details</h3>

                <?php if ($profileError !== ""): ?>
                    <div class="catalogue-message" role="alert" style="border-left:4px solid #b91c1c;"><?= e($profileError) ?></div>
                <?php endif; ?>

                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <label for="full_name" style="<?= $labelStyle ?>">Full name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="100" required
                           value="<?= e($nameValue) ?>" style="<?= $inputStyle ?>">

                    <label for="email" style="<?= $labelStyle ?>">Email address</label>
                    <input type="email" id="email" value="<?= e($user["email"]) ?>" disabled
                           style="<?= $inputStyle ?> background:#f4f7fb;">
                    <small>Your email is your login and cannot be changed here. Contact HumanMatrix if it must change.</small>

                    <label for="phone" style="<?= $labelStyle ?>">Phone number</label>
                    <input type="tel" id="phone" name="phone" maxlength="20" required
                           value="<?= e($phoneValue) ?>" style="<?= $inputStyle ?>">

                    <p style="margin:16px 0 0;"><button type="submit">Save details</button></p>
                </form>
            </div>

            <div style="<?= $boxStyle ?>">
                <h3 style="margin-top:0;">Change password</h3>

                <?php if ($passwordError !== ""): ?>
                    <div class="catalogue-message" role="alert" style="border-left:4px solid #b91c1c;"><?= e($passwordError) ?></div>
                <?php endif; ?>

                <form method="POST" action="profile.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <label for="current_password" style="<?= $labelStyle ?>">Current password</label>
                    <input type="password" id="current_password" name="current_password" required
                           autocomplete="current-password" style="<?= $inputStyle ?>">

                    <label for="new_password" style="<?= $labelStyle ?>">New password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                           autocomplete="new-password" style="<?= $inputStyle ?>">
                    <small>At least <?= PASSWORD_MIN_LENGTH ?> characters.</small>

                    <label for="confirm_password" style="<?= $labelStyle ?>">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           autocomplete="new-password" style="<?= $inputStyle ?>">

                    <p style="margin:16px 0 0;"><button type="submit">Change password</button></p>
                </form>
            </div>

        <?php endif; ?>
    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>