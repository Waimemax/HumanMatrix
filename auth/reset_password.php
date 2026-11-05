<?php
session_start();

require_once "../config/database.php";
require_once "../includes/notifications.php";
require_once "../includes/profile.php";   // password length rules

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$secret = (string) ($_POST["token"] ?? $_GET["token"] ?? "");
$user = null;
$error = "";

// Find the person this link belongs to. Links expire and work only once.
if (preg_match('/^[a-f0-9]{64}$/', $secret)) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.id AS reset_id, u.id, u.email
            FROM password_resets r
            INNER JOIN users u ON u.id = r.user_id
            WHERE r.token_hash = :hash
              AND r.used_at IS NULL
              AND r.expires_at > :now
              AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([":hash" => hash("sha256", $secret), ":now" => date("Y-m-d H:i:s")]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $exception) {
        error_log("HumanMatrix password reset lookup error: " . $exception->getMessage());
    }
}

if ($user && $_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = $_POST["csrf_token"] ?? "";
    $new = (string) ($_POST["new_password"] ?? "");
    $confirm = (string) ($_POST["confirm_password"] ?? "");

    if (!is_string($csrf) || !hash_equals($_SESSION["csrf_token"], $csrf)) {
        $error = "Invalid form submission. Please refresh the page and try again.";
    } elseif (strlen($new) < PASSWORD_MIN_LENGTH) {
        $error = "The new password must be at least " . PASSWORD_MIN_LENGTH . " characters long.";
    } elseif (strlen($new) > PASSWORD_MAX_LENGTH) {
        $error = "The new password must be " . PASSWORD_MAX_LENGTH . " characters or fewer.";
    } elseif ($new !== $confirm) {
        $error = "The two passwords do not match.";
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE users SET password = :hash WHERE id = :id")
                ->execute([":hash" => password_hash($new, PASSWORD_DEFAULT), ":id" => (int) $user["id"]]);

            // This link and any other open links are now finished.
            $pdo->prepare("UPDATE password_resets SET used_at = :now WHERE user_id = :id AND used_at IS NULL")
                ->execute([":now" => date("Y-m-d H:i:s"), ":id" => (int) $user["id"]]);

            // Lift any sign-in lock that failed attempts had caused.
            $pdo->prepare("DELETE FROM activity_logs WHERE action = 'login_failed' AND description = :email")
                ->execute([":email" => mb_substr(strtolower((string) $user["email"]), 0, 150)]);

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("HumanMatrix password reset error: " . $exception->getMessage());
            $error = "We couldn't change your password. Please try again.";
        }

        if ($error === "") {
            createNotification(
                $pdo,
                (int) $user["id"],
                "Password changed",
                "Your Human Matrix password was reset. If this was not you, contact HumanMatrix immediately.",
                "account"
            );

            session_regenerate_id(true);
            header("Location: login.php?reset=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password | Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body class="auth-page">

    <main class="auth-layout">

        <section class="auth-brand-panel">
            <a href="../index.php" class="auth-brand">
                <span class="brand-icon">HM</span>
                <span>Human Matrix</span>
            </a>

            <div class="brand-content">
                <span class="brand-tag">HEALTHCARE EQUIPMENT SOLUTIONS</span>
                <h1>Better equipment.<br>Better healthcare.</h1>
                <p>
                    Access your account to explore medical equipment,
                    manage your orders and keep track of your deliveries.
                </p>
            </div>

            <p class="brand-footer">
                Supporting healthcare through reliable equipment access.
            </p>
        </section>

        <section class="auth-form-panel">
            <div class="auth-form-container">

                <div class="mobile-brand">
                    <span class="brand-icon">HM</span>
                    <span>Human Matrix</span>
                </div>

                <?php if (!$user): ?>
                    <span class="form-eyebrow">LINK PROBLEM</span>
                    <h2>This link is not valid</h2>
                    <p class="form-description">
                        The reset link has expired, was already used, or is incomplete.
                        Please request a new one.
                    </p>

                    <p class="auth-switch">
                        <a href="forgot_password.php">Request a new link</a> &middot;
                        <a href="login.php">Back to sign in</a>
                    </p>
                <?php else: ?>
                    <span class="form-eyebrow">PASSWORD HELP</span>
                    <h2>Choose a new password</h2>
                    <p class="form-description">
                        Use at least <?= PASSWORD_MIN_LENGTH ?> characters.
                    </p>

                    <?php if ($error !== ""): ?>
                        <div class="auth-message" role="alert">
                            <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="auth-form" autocomplete="off">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="token"
                               value="<?= htmlspecialchars($secret, ENT_QUOTES, "UTF-8") ?>">

                        <div class="form-group">
                            <label for="new_password">New password</label>
                            <input type="password" id="new_password" name="new_password"
                                   minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm new password</label>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   autocomplete="new-password" required>
                        </div>

                        <button type="submit" class="auth-submit">
                            Save new password <span aria-hidden="true">&rarr;</span>
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </section>

    </main>

</body>
</html>