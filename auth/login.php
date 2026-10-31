<?php

session_start();

require_once "../config/database.php";

$message = "";

// Login attempt limiting: after too many failed sign-ins for the same email
// (or from the same address) sign-in is paused for a while. Failures are
// kept in the existing activity_logs table.
const LOGIN_MAX_PER_EMAIL = 5;
const LOGIN_MAX_PER_IP = 20;
const LOGIN_WINDOW_SECONDS = 900; // 15 minutes

function loginFailureCount(PDO $pdo, string $column, string $value): int
{
    $sql = $column === "ip"
        ? "SELECT COUNT(*) FROM activity_logs
           WHERE action = 'login_failed' AND ip_address = :v AND created_at >= :since"
        : "SELECT COUNT(*) FROM activity_logs
           WHERE action = 'login_failed' AND description = :v AND created_at >= :since";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":v"     => $value,
        ":since" => date("Y-m-d H:i:s", time() - LOGIN_WINDOW_SECONDS),
    ]);

    return (int) $stmt->fetchColumn();
}

function isLoginBlocked(PDO $pdo, string $email, string $ip): bool
{
    try {
        return loginFailureCount($pdo, "email", $email) >= LOGIN_MAX_PER_EMAIL
            || loginFailureCount($pdo, "ip", $ip) >= LOGIN_MAX_PER_IP;
    } catch (PDOException $exception) {
        // If the log cannot be read, do not lock everybody out.
        error_log("HumanMatrix login throttle read error: " . $exception->getMessage());
        return false;
    }
}

function recordLoginFailure(PDO $pdo, string $email, string $ip): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
            VALUES (NULL, 'login_failed', :email, :ip, :now)
        ");
        $stmt->execute([
            ":email" => $email,
            ":ip"    => $ip,
            ":now"   => date("Y-m-d H:i:s"),
        ]);
    } catch (PDOException $exception) {
        error_log("HumanMatrix login throttle write error: " . $exception->getMessage());
    }
}

function clearLoginFailures(PDO $pdo, string $email): void
{
    try {
        $stmt = $pdo->prepare(
            "DELETE FROM activity_logs WHERE action = 'login_failed' AND description = :email"
        );
        $stmt->execute([":email" => $email]);
    } catch (PDOException $exception) {
        error_log("HumanMatrix login throttle clear error: " . $exception->getMessage());
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim((string) ($_POST["email"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $emailKey = mb_substr(strtolower($email), 0, 150);
    $ip = mb_substr((string) ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 0, 45);

    if (empty($email) || empty($password)) {

        $message = "Please enter your email and password.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";

    } elseif (isLoginBlocked($pdo, $emailKey, $ip)) {

        $message = "Too many failed sign-in attempts. Please wait 15 minutes and try again.";

    } else {

        $sql = "SELECT id, full_name, email, password, role, status
                FROM users
                WHERE email = ?
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {

            recordLoginFailure($pdo, $emailKey, $ip);
            $message = "Invalid email or password.";

        } elseif ($user["status"] !== "active") {

            $message = "Your account is inactive. Please contact the administrator.";

        } elseif (!password_verify($password, $user["password"])) {

            recordLoginFailure($pdo, $emailKey, $ip);
            $message = "Invalid email or password.";

        } else {

            clearLoginFailures($pdo, $emailKey);
            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            if ($user["role"] === "admin") {

                header("Location: ../admin/dashboard.php");
                exit;

            } else {

                header("Location: ../customer/dashboard.php");
                exit;
            }
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Human Matrix</title>
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

                <div class="brand-benefit">
                    <span class="benefit-icon">✓</span>
                    <span>Browse medical equipment with ease</span>
                </div>

                <div class="brand-benefit">
                    <span class="benefit-icon">✓</span>
                    <span>Manage your orders in one place</span>
                </div>

                <div class="brand-benefit">
                    <span class="benefit-icon">✓</span>
                    <span>Stay informed about deliveries</span>
                </div>
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

                <span class="form-eyebrow">WELCOME BACK</span>
                <h2>Sign in to your account</h2>
                <p class="form-description">
                    Enter your details below to continue.
                </p>

                <?php if (!empty($message)): ?>
                    <div class="auth-message" role="alert">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">

                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="you@example.com"
                            autocomplete="email"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >
                            <button
                                type="button"
                                class="password-toggle"
                                onclick="togglePassword()"
                                aria-label="Show password"
                            >Show</button>
                        </div>
                    </div>

                    <button type="submit" class="auth-submit">
                        Sign In <span aria-hidden="true">→</span>
                    </button>

                </form>

                <p class="auth-switch">
                    Don't have an account?
                    <a href="register.php">Create an account</a>
                </p>

                <p class="auth-security">
                    Your account helps you manage your Human Matrix activity.
                </p>

            </div>
        </section>

    </main>

    <script>
        function togglePassword() {
            const password = document.getElementById("password");
            const button = document.querySelector(".password-toggle");
            const isHidden = password.type === "password";

            password.type = isHidden ? "text" : "password";
            button.textContent = isHidden ? "Hide" : "Show";
            button.setAttribute(
                "aria-label",
                isHidden ? "Hide password" : "Show password"
            );
        }
    </script>

</body>
</html>