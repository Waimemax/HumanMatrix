
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1>Admin Dashboard</h1>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION["full_name"]); ?>!</p>
        </header>

        <section>
            <h2>System Overview</h2>
            <p>You are logged in as an administrator.</p>
            <p>
                <strong>Email:</strong>
                <?php echo htmlspecialchars($_SESSION["email"]); ?>
            </p>
            <p>
                <strong>Role:</strong>
                <?php echo htmlspecialchars($_SESSION["role"]); ?>
            </p>
        </section>

        <section>
            <h2>System Management</h2>
            <p>Use the navigation menu to manage the medical equipment ordering system.</p>
        </section>
    </main>

</div>

</body>
</html>
