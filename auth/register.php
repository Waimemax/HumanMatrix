<?php

require_once "../config/database.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        $message = "Please fill in all fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";

    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";

    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";

    } else {

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $message = "An account with this email already exists.";

        } else {

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO users 
                    (full_name, email, phone, password, role, status)
                    VALUES (?, ?, ?, ?, 'customer', 'active')";

            $stmt = $pdo->prepare($sql);

            if ($stmt->execute([
                $full_name,
                $email,
                $phone,
                $hashed_password
            ])) {
                $message = "Registration successful! You can now log in.";
            } else {
                $message = "Registration failed. Please try again.";
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
    <title>Create Account | Human Matrix</title>
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
                <span class="brand-tag">YOUR HEALTHCARE EQUIPMENT PARTNER</span>

                <h1>Healthcare solutions, made simpler.</h1>

                <p>
                    Create your account to discover medical equipment,
                    manage orders and stay updated on deliveries.
                </p>

                <div class="brand-benefit">
                    <span class="benefit-icon">✓</span>
                    <span>Explore medical equipment in one place</span>
                </div>

                <div class="brand-benefit">
                    <span class="benefit-icon">✓</span>
                    <span>Keep your orders organised</span>
                </div>

                <div class="brand-benefit">
                    <span class="benefit-icon">✓</span>
                    <span>Access your account whenever you need it</span>
                </div>
            </div>

            <p class="brand-footer">
                Supporting better access to healthcare equipment.
            </p>
        </section>

        <section class="auth-form-panel">
            <div class="auth-form-container">

                <div class="mobile-brand">
                    <span class="brand-icon">HM</span>
                    <span>Human Matrix</span>
                </div>

                <span class="form-eyebrow">GET STARTED</span>
                <h2>Create your account</h2>

                <p class="form-description">
                    Join Human Matrix in a few simple steps.
                </p>

                <?php if (!empty($message)): ?>
                    <div
                        class="auth-message <?php echo strpos($message, 'successful') !== false ? 'auth-success' : ''; ?>"
                        role="alert"
                    >
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>

                        <?php if (strpos($message, 'successful') !== false): ?>
                            <p class="success-login-link">
                                <a href="login.php">Sign in to your account →</a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">

                    <div class="form-group">
                        <label for="full_name">Full name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter your full name"
                            autocomplete="name"
                            value="<?php echo htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            required
                        >
                    </div>

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
                        <label for="phone">Phone number</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            placeholder="Enter your phone number"
                            autocomplete="tel"
                            value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
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
                                placeholder="At least 8 characters"
                                autocomplete="new-password"
                                minlength="8"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                onclick="toggleField('password', this)"
                                aria-label="Show password"
                            >Show</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm password</label>

                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Enter your password again"
                                autocomplete="new-password"
                                minlength="8"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                onclick="toggleField('confirm_password', this)"
                                aria-label="Show confirmation password"
                            >Show</button>
                        </div>
                    </div>

                    <button type="submit" class="auth-submit">
                        Create Account <span aria-hidden="true">→</span>
                    </button>

                </form>

                <p class="auth-switch">
                    Already have an account?
                    <a href="login.php">Sign in</a>
                </p>

                <p class="auth-security">
                    Your password is securely hashed before it is stored.
                </p>

            </div>
        </section>

    </main>

    <script>
        function toggleField(fieldId, button) {
            const field = document.getElementById(fieldId);
            const isHidden = field.type === "password";

            field.type = isHidden ? "text" : "password";
            button.textContent = isHidden ? "Hide" : "Show";
            button.setAttribute(
                "aria-label",
                isHidden ? "Hide password" : "Show password"
            );
        }
    </script>

</body>
</html>
