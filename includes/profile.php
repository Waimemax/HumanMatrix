<?php
// Shared logic for the Profile pages (customers and administrators).
// Each function returns an error message, or null when it worked.

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 72;      // bcrypt ignores anything past 72 bytes
const PASSWORD_MAX_FAILURES = 5;     // wrong "current password" tries ...
const PASSWORD_FAILURE_WINDOW = 900; // ... within 15 minutes

function loadProfile(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, phone, role, created_at
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([":id" => $userId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateProfile(PDO $pdo, int $userId, string $fullName, string $phone): ?string
{
    $fullName = trim($fullName);
    $phone = trim($phone);

    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
        return "Please enter your full name (2 to 100 characters).";
    }

    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) {
        return "Please enter a valid phone number.";
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET full_name = :name, phone = :phone WHERE id = :id");
        $stmt->execute([":name" => $fullName, ":phone" => $phone, ":id" => $userId]);
    } catch (PDOException $exception) {
        error_log("HumanMatrix profile update error: " . $exception->getMessage());

        return "We couldn't save your details. Please try again.";
    }

    $_SESSION["full_name"] = $fullName;

    return null;
}

function recentPasswordFailures(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM activity_logs
        WHERE user_id = :id AND action = 'password_change_failed' AND created_at >= :since
    ");
    $stmt->execute([
        ":id"    => $userId,
        ":since" => date("Y-m-d H:i:s", time() - PASSWORD_FAILURE_WINDOW),
    ]);

    return (int) $stmt->fetchColumn();
}

function changePassword(
    PDO $pdo,
    int $userId,
    string $current,
    string $new,
    string $confirm
): ?string {
    if ($current === "" || $new === "" || $confirm === "") {
        return "Please fill in all three password fields.";
    }

    if (strlen($new) < PASSWORD_MIN_LENGTH) {
        return "The new password must be at least " . PASSWORD_MIN_LENGTH . " characters long.";
    }

    if (strlen($new) > PASSWORD_MAX_LENGTH) {
        return "The new password must be " . PASSWORD_MAX_LENGTH . " characters or fewer.";
    }

    if ($new !== $confirm) {
        return "The new password and its confirmation do not match.";
    }

    try {
        if (recentPasswordFailures($pdo, $userId) >= PASSWORD_MAX_FAILURES) {
            return "Too many wrong attempts. Please wait 15 minutes and try again.";
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $userId]);
        $hash = $stmt->fetchColumn();

        if (!is_string($hash) || !password_verify($current, $hash)) {
            $log = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                VALUES (:id, 'password_change_failed', 'Wrong current password', :ip, :now)
            ");
            $log->execute([
                ":id"  => $userId,
                ":ip"  => mb_substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45),
                ":now" => date("Y-m-d H:i:s"),
            ]);

            return "Your current password is not correct.";
        }

        if (hash_equals($current, $new)) {
            return "Choose a new password that is different from your current one.";
        }

        $update = $pdo->prepare("UPDATE users SET password = :hash WHERE id = :id");
        $update->execute([
            ":hash" => password_hash($new, PASSWORD_DEFAULT),
            ":id"   => $userId,
        ]);

        // A successful change clears the wrong-attempt count.
        $clear = $pdo->prepare("
            DELETE FROM activity_logs WHERE user_id = :id AND action = 'password_change_failed'
        ");
        $clear->execute([":id" => $userId]);
    } catch (PDOException $exception) {
        error_log("HumanMatrix password change error: " . $exception->getMessage());

        return "We couldn't change your password. Please try again.";
    }

    // Start a fresh session id now that the credentials changed.
    session_regenerate_id(true);

    return null;
}