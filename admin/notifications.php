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
require_once "../includes/notifications.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$userId = (int) $_SESSION["user_id"];

$error = "";

// Mark one notification, or all of them, as read.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid form submission. Please refresh and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "mark_all") {
                $stmt = $pdo->prepare("
                    UPDATE notifications SET is_read = 1
                    WHERE user_id = :user_id AND is_read = 0
                ");
                $stmt->execute([":user_id" => $userId]);

                header("Location: notifications.php");
                exit;
            }

            if ($action === "mark_read") {
                $id = filter_input(INPUT_POST, "notification_id", FILTER_VALIDATE_INT);

                if ($id === false || $id === null || $id < 1) {
                    throw new RuntimeException("Invalid notification.");
                }

                // A customer can only change their own notifications.
                $stmt = $pdo->prepare("
                    UPDATE notifications SET is_read = 1
                    WHERE id = :id AND user_id = :user_id
                ");
                $stmt->execute([":id" => $id, ":user_id" => $userId]);

                header("Location: notifications.php");
                exit;
            }

            throw new RuntimeException("Unknown action.");
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log("HumanMatrix notifications update error: " . $exception->getMessage());
            $error = "We couldn't update your notifications. Please try again.";
        }
    }
}

$notifications = [];
$unreadCount = 0;
$loadError = false;

try {
    $stmt = $pdo->prepare("
        SELECT id, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY id DESC
        LIMIT 50
    ");
    $stmt->execute([":user_id" => $userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = unreadNotificationCount($pdo, $userId);
} catch (PDOException $exception) {
    error_log("HumanMatrix notifications load error: " . $exception->getMessage());
    $loadError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Human Matrix</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">

    <?php require_once "../includes/admin_nav.php"; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1>Notifications</h1>
            <p>New orders and other alerts for administrators.</p>
        </header>

        <?php if ($unreadCount > 0): ?>
            <form method="POST" action="notifications.php" style="margin-bottom:14px;">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="mark_all">
                <button type="submit" class="secondary-button">Mark all as read</button>
            </form>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="notice error"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if ($loadError): ?>
            <p class="notice error">The notifications could not be loaded. Please try again.</p>
        <?php elseif (empty($notifications)): ?>
            <p>No notifications yet. You will be told here when a customer places an order.</p>
        <?php else: ?>
            <ul style="list-style:none; padding:0; margin:0; display:grid; gap:10px;">
                <?php foreach ($notifications as $note): ?>
                    <?php
                    $isUnread = (int) $note["is_read"] === 0;
                    $orderLink = "";
                    if (preg_match('/HM-(\d{6})/', $note["message"], $match)) {
                        $orderLink = "order_view.php?id=" . (int) $match[1];
                    }
                    ?>
                    <li style="padding:14px 16px; border-radius:8px; border:1px solid #e5eaf0; background:<?= $isUnread ? "#eef6fc" : "#ffffff" ?>;">
                        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <strong>
                                <?php if ($isUnread): ?><span aria-label="Unread" style="color:#2476a8;">&#9679;</span><?php endif; ?>
                                <?= e($note["title"]) ?>
                            </strong>
                            <small><?= e(date("d M Y, H:i", strtotime($note["created_at"]))) ?></small>
                        </div>

                        <p style="margin:6px 0;"><?= e($note["message"]) ?></p>

                        <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                            <?php if ($orderLink !== ""): ?>
                                <a href="<?= e($orderLink) ?>">View order</a>
                            <?php endif; ?>

                            <?php if ($isUnread): ?>
                                <form method="POST" action="notifications.php" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $note["id"] ?>">
                                    <button type="submit" class="secondary-button">Mark as read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</div>

</body>
</html>