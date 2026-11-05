<?php
session_start();

require_once "../config/database.php";
require_once "../includes/mailer.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

const RESET_MAX_PER_EMAIL = 3;
const RESET_MAX_PER_IP = 10;
const RESET_WINDOW_SECONDS = 900;   // 15 minutes
const RESET_LINK_SECONDS = 3600;    // a reset link works for 1 hour

function resetRequestCount(PDO $pdo, string $column, string $value): int
{
    $sql = $column === "ip"
        ? "SELECT COUNT(*) FROM activity_logs
           WHERE action = 'password_reset_requested' AND ip_address = :v AND created_at >= :since"
        : "SELECT COUNT(*) FROM activity_logs
           WHERE action = 'password_reset_requested' AND description = :v AND created_at >= :since";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([":v" => $value, ":since" => date("Y-m-d H:i:s", time() - RESET_WINDOW_SECONDS)]);

    return (int) $stmt->fetchColumn();
}

$message = "";
$messageIsError = false;
$done = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $email = trim((string) ($_POST["email"] ?? ""));

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $message = "Invalid form submission. Please refresh the page and try again.";
        $messageIsError = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageIsError = true;
    } elseif (!mailEnabled()) {
        $message = "Password reset by email is not set up yet. Please contact the administrator.";
        $messageIsError = true;
    } else {
        $emailKey = mb_substr(strtolower($email), 0, 150);
        $ip = mb_substr((string) ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 0, 45);

        try {
            $tooMany = resetRequestCount($pdo, "email", $emailKey) >= RESET_MAX_PER_EMAIL
                || resetRequestCount($pdo, "ip", $ip) >= RESET_MAX_PER_IP;

            // Every request is recorded, so the limit also covers unknown emails.
            $log = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                VALUES (NULL, 'password_reset_requested', :email, :ip, :now)
            ");
            $log->execute([":email" => $emailKey, ":ip" => $ip, ":now" => date("Y-m-d H:i:s")]);

            if (!$tooMany) {
                $stmt = $pdo->prepare("
                    SELECT id, full_name, email FROM users
                    WHERE email = :email AND status = 'active' LIMIT 1
                ");
                $stmt->execute([":email" => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Only the newest link works.
                    $pdo->prepare("DELETE FROM password_resets WHERE user_id = :id AND used_at IS NULL")
                        ->execute([":id" => (int) $user["id"]]);

                    $secret = bin2hex(random_bytes(32));

                    $pdo->prepare("
                        INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
                        VALUES (:id, :hash, :expires, :now)
                    ")->execute([
                        ":id"      => (int) $user["id"],
                        ":hash"    => hash("sha256", $secret),
                        ":expires" => date("Y-m-d H:i:s", time() + RESET_LINK_SECONDS),
                        ":now"     => date("Y-m-d H:i:s"),
                    ]);

                    $config = mailConfig();
                    $siteUrl = rtrim((string) ($config["site_url"] ?? "http://localhost/HumanMatrix"), "/");
                    $link = $siteUrl . "/auth/reset_password.php?token=" . $secret;

                    $body = '<p style="margin:0 0 12px;">Hello ' . mailEscape($user["full_name"]) . ',</p>'
                        . '<p style="margin:0 0 12px;">We received a request to reset your Human Matrix password. '
                        . 'The link below works for 1 hour and can be used once.</p>'
                        . '<p style="margin:0 0 12px;color:#6b7785;">If you did not ask for this, ignore this email. '
                        . 'Your password will not change.</p>';

                    sendEmail(
                        (string) $user["email"],
                        "Human Matrix: reset your password",
                        emailLayout("Reset your password", $body, false, ["Choose a new password", $link]),
                        "Reset your Human Matrix password (valid for 1 hour): " . $link
                    );
                }
            }
        } catch (PDOException $exception) {
            error_log("HumanMatrix password reset request error: " . $exception->getMessage());
        }

        // The same answer whether or not the email exists.
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password | Human Matrix</title>
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

                <span class="form-eyebrow">PASSWORD HELP</span>
                <h2>Forgot your password?</h2>
                <p class="form-description">
                    Enter your account email and we will send you a link to choose a new password.
                </p>

                <?php if ($done): ?>
                    <div class="auth-message" role="status">
                        If an account exists for that email, a reset link is on its way.
                        It is valid for 1 hour. Check your Spam folder if you do not see it.
                    </div>
                <?php elseif ($message !== ""): ?>
                    <div class="auth-message" role="alert">
                        <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>

                <?php if (!$done): ?>
                <form method="POST" action="" class="auth-form">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com"
                               autocomplete="email" required
                               value="<?= htmlspecialchars((string) ($_POST["email"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </div>

                    <button type="submit" class="auth-submit">
                        Send reset link <span aria-hidden="true">&rarr;</span>
                    </button>
                </form>
                <?php endif; ?>

                <p class="auth-switch">
                    <a href="login.php">Back to sign in</a>
                </p>

            </div>
        </section>

    </main>

</body>
</html>