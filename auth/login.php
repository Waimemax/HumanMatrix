<?php

session_start();

require_once "../config/database.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (empty($email) || empty($password)) {

        $message = "Please enter your email and password.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";

    } else {

        $sql = "SELECT id, full_name, email, password, role, status
                FROM users
                WHERE email = ?
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {

            $message = "Invalid email or password.";

        } elseif ($user["status"] !== "active") {

            $message = "Your account is inactive. Please contact the administrator.";

        } elseif (!password_verify($password, $user["password"])) {

            $message = "Invalid email or password.";

        } else {

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
