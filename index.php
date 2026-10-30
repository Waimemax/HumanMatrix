<?php
session_start();

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$isLoggedIn = isset($_SESSION["user_id"]);
$isAdmin = $isLoggedIn && (($_SESSION["role"] ?? "") === "admin");

$dashboardLink = $isAdmin ? "admin/dashboard.php" : "customer/dashboard.php";
$cartCount = (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"]))
    ? count($_SESSION["cart"])
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Human Matrix | Medical Equipment Ordering and Delivery</title>
    <link rel="stylesheet" href="assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="index.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a class="active" href="index.php">Home</a>
        <a href="customer/product.php">Equipment</a>

        <?php if ($isLoggedIn): ?>
            <?php if (!$isAdmin): ?>
                <a href="cart/view.php">Cart<?= $cartCount > 0 ? " (" . $cartCount . ")" : "" ?></a>
            <?php endif; ?>
            <a href="<?= e($dashboardLink) ?>">Dashboard</a>
            <a href="auth/logout.php">Logout</a>
        <?php else: ?>
            <a href="auth/login.php">Login</a>
            <a href="auth/register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>

<main class="catalogue-main">

    <section class="catalogue-hero">
        <p class="catalogue-eyebrow">HUMAN MATRIX MEDICAL SUPPLIES</p>
        <h1>Medical Equipment Ordering and Delivery</h1>
        <p>
            Order hospital beds, diagnostic devices, laboratory equipment and
            patient care supplies for your healthcare facility, and follow your
            order through to delivery.
        </p>
        <p style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
            <a class="welcome-button" href="customer/product.php">
                Browse Equipment <span aria-hidden="true">→</span>
            </a>

            <?php if (!$isLoggedIn): ?>
                <a class="welcome-button" href="auth/login.php">Login to order</a>
            <?php endif; ?>
        </p>
    </section>

    <section class="catalogue-section">
        <div class="catalogue-section-heading">
            <div>
                <h2>How it works</h2>
                <p>From browsing to delivery in a few simple steps.</p>
            </div>
        </div>

        <div class="catalogue-grid">
            <article class="catalogue-card">
                <div class="catalogue-card-body">
                    <p class="catalogue-category">Step 1</p>
                    <h3>Browse equipment</h3>
                    <p class="catalogue-description">
                        Search the catalogue, view specifications and check
                        what is currently in stock.
                    </p>
                </div>
            </article>

            <article class="catalogue-card">
                <div class="catalogue-card-body">
                    <p class="catalogue-category">Step 2</p>
                    <h3>Place your order</h3>
                    <p class="catalogue-description">
                        Add items to your cart, choose your facility and decide
                        whether to pay before or upon delivery.
                    </p>
                </div>
            </article>

            <article class="catalogue-card">
                <div class="catalogue-card-body">
                    <p class="catalogue-category">Step 3</p>
                    <h3>Receive delivery</h3>
                    <p class="catalogue-description">
                        HumanMatrix confirms and ships your order, and the
                        equipment is delivered to your facility.
                    </p>
                </div>
            </article>
        </div>
    </section>

</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>