
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

$customerName = $_SESSION["full_name"] ?? "Customer";
$customerEmail = $_SESSION["email"] ?? "";
$customerRole = $_SESSION["role"] ?? "customer";

$safeName = htmlspecialchars($customerName, ENT_QUOTES, "UTF-8");
$safeEmail = htmlspecialchars($customerEmail, ENT_QUOTES, "UTF-8");
$safeRole = htmlspecialchars(ucfirst($customerRole), ENT_QUOTES, "UTF-8");

// Get the first name for a friendlier welcome.
$nameParts = preg_split('/\s+/', trim($customerName));
$firstName = $nameParts[0] ?? "Customer";
$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, "UTF-8");

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Dashboard | Human Matrix</title>

    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body class="customer-dashboard-page">

    <header class="dashboard-header">

        <a href="../index.php" class="dashboard-brand">
            <span class="brand-icon">HM</span>
            <span>Human Matrix</span>
        </a>

        <nav class="dashboard-nav" aria-label="Main navigation">
            <a href="../index.php" class="nav-home">Home</a>
            <a href="#my-account">My Account</a>
            <a href="../auth/logout.php" class="dashboard-logout">
                   <a href="../cart/view.php">Cart</a>
                <span aria-hidden="true">↪</span> Logout
            </a>
        </nav>

    </header>

    <main class="dashboard-main">

        <section class="welcome-banner">

            <div class="welcome-copy">
                <span class="welcome-label">CUSTOMER DASHBOARD</span>

                <h1>Welcome back, <?= $safeFirstName; ?>! <span aria-hidden="true">👋</span></h1>

                <p>
                    We're glad to have you here. Explore medical equipment
                    and manage your Human Matrix account in one place.
                </p>

                <a href="../index.php" class="welcome-button">
                    Explore Human Matrix <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="welcome-decoration" aria-hidden="true">
                <div class="welcome-circle">
                    <span>HM</span>
                    <small>Healthcare made simpler</small>
                </div>
            </div>

        </section>

        <section class="dashboard-section">

            <div class="dashboard-section-heading">
                <div>
                    <h2>Quick access</h2>
                    <p>Where would you like to go today?</p>
                </div>
            </div>

            <div class="dashboard-cards">

                <a href="../index.php" class="dashboard-card">
                    <div class="dashboard-card-icon equipment-icon">
                        <span aria-hidden="true">✚</span>
                    </div>

                    <h3>Explore Equipment</h3>

                    <p>
                        Visit Human Matrix to explore the available
                        medical equipment and services.
                    </p>

                    <span class="dashboard-card-link">
                        Explore now <span aria-hidden="true">→</span>
                    </span>
                </a>

                <div class="dashboard-card">
                    <div class="dashboard-card-icon orders-icon">
                        <span aria-hidden="true">▤</span>
                    </div>

                    <h3>My Orders</h3>

                    <p>
                        Your order history and delivery tracking
                        will be available here as those features are added.
                    </p>

                    <span class="dashboard-coming-soon">
                        Coming soon
                    </span>
                </div>

                <div class="dashboard-card" id="my-account">
                    <div class="dashboard-card-icon profile-icon">
                        <span aria-hidden="true">♙</span>
                    </div>

                    <h3>My Account</h3>

                    <p>
                        Check the account details associated with
                        your Human Matrix login.
                    </p>

                    <a href="#account-details" class="dashboard-card-link">
                        View details <span aria-hidden="true">↓</span>
                    </a>
                </div>

            </div>

        </section>

        <section class="account-panel" id="account-details">

            <div class="account-panel-heading">
                <div class="account-avatar" aria-hidden="true">
                    <?= htmlspecialchars(strtoupper(substr($firstName, 0, 1)), ENT_QUOTES, "UTF-8"); ?>
                </div>

                <div>
                    <h2>Your account</h2>
                    <p>Your current profile information</p>
                </div>
            </div>

            <div class="account-details-grid">

                <div class="account-detail">
                    <span>Full name</span>
                    <strong><?= $safeName; ?></strong>
                </div>

                <div class="account-detail">
                    <span>Email address</span>
                    <strong><?= $safeEmail; ?></strong>
                </div>

                <div class="account-detail">
                    <span>Account role</span>
                    <strong><?= $safeRole; ?></strong>
                </div>

                <div class="account-detail">
                    <span>Session status</span>
                    <strong class="session-active">
                        <span aria-hidden="true">●</span> Signed in
                    </strong>
                </div>

            </div>

        </section>

        <footer class="dashboard-footer">
            <p>&copy; <?= date("Y"); ?> Human Matrix. Supporting healthcare through reliable equipment access.</p>
        </footer>

    </main>

</body>
</html>
