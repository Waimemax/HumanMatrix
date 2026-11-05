<?php
// Sends email through SMTP using PHPMailer.
//
// Nothing here ever throws. If email is switched off, mis-configured, or the
// mail server cannot be reached, the failure is written to the PHP error log
// and the caller carries on. An order must never fail because an email failed.

require_once __DIR__ . "/phpmailer/Exception.php";
require_once __DIR__ . "/phpmailer/PHPMailer.php";
require_once __DIR__ . "/phpmailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;

function mailConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [];
    $file = __DIR__ . "/../config/mail.php";

    if (is_file($file)) {
        $loaded = require $file;

        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return $config;
}

function mailEnabled(): bool
{
    $config = mailConfig();

    return !empty($config["enabled"])
        && !empty($config["host"])
        && !empty($config["from_email"]);
}

function mailEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Wraps a message in a simple, readable email layout.
function emailLayout(string $heading, string $bodyHtml, bool $forAdmin = false, ?array $customButton = null): string
{
    $config = mailConfig();
    $siteUrl = rtrim((string) ($config["site_url"] ?? ""), "/");

    $button = "";

    if ($customButton !== null) {
        $button = '<p style="margin:24px 0 0;"><a href="' . mailEscape($customButton[1])
            . '" style="background:#2476a8;color:#ffffff;padding:10px 18px;border-radius:6px;'
            . 'text-decoration:none;display:inline-block;">' . mailEscape($customButton[0]) . '</a></p>';
    } elseif ($siteUrl !== "") {
        $link = $forAdmin ? "/admin/notifications.php" : "/customer/orders.php";
        $label = $forAdmin ? "Open notifications" : "View my orders";

        $button = '<p style="margin:24px 0 0;"><a href="' . mailEscape($siteUrl . $link)
            . '" style="background:#2476a8;color:#ffffff;padding:10px 18px;border-radius:6px;'
            . 'text-decoration:none;display:inline-block;">' . $label . '</a></p>';
    }

    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f7fb;">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">'
        . '<div style="background:#ffffff;border-radius:10px;padding:24px;border:1px solid #e5eaf0;">'
        . '<p style="margin:0 0 4px;color:#2476a8;font-weight:bold;">Human Matrix</p>'
        . '<h2 style="margin:0 0 14px;font-size:20px;">' . mailEscape($heading) . '</h2>'
        . $bodyHtml
        . $button
        . '</div>'
        . '<p style="color:#6b7785;font-size:12px;text-align:center;">'
        . 'You are receiving this because you have an account with Human Matrix.</p>'
        . '</div></body></html>';
}

function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = ""): bool
{
    if (!mailEnabled()) {
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = mailConfig();

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $config["host"];
        $mail->Port = (int) ($config["port"] ?? 587);
        $mail->Timeout = 8;          // seconds; keeps checkout quick if the server is slow
        $mail->SMTPDebug = 0;
        $mail->CharSet = "UTF-8";

        $encryption = strtolower((string) ($config["encryption"] ?? ""));

        if ($encryption === "ssl") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === "tls") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = "";
            $mail->SMTPAutoTLS = false;
        }

        if (!empty($config["username"])) {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $config["username"];
            $mail->Password = (string) ($config["password"] ?? "");
        }

        $mail->setFrom((string) $config["from_email"], (string) ($config["from_name"] ?? "Human Matrix"));
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== "" ? $textBody : trim(strip_tags($htmlBody));

        $mail->send();

        return true;
    } catch (Throwable $exception) {
        error_log("HumanMatrix email error: " . $exception->getMessage());

        return false;
    }
}

// Emails a user the same message that was saved as their notification.
// $extraHtml is optional extra content shown under the message (for example
// the list of items on a new order).
function emailUserAboutNotification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    ?string $extraHtml = null
): void {
    if (!mailEnabled()) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT email, full_name, role, status FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        error_log("HumanMatrix email lookup error: " . $exception->getMessage());
        return;
    }

    if (!$user || $user["status"] !== "active" || empty($user["email"])) {
        return;
    }

    $body = '<p style="margin:0 0 12px;">Hello ' . mailEscape($user["full_name"]) . ',</p>'
        . '<p style="margin:0 0 12px;">' . mailEscape($message) . '</p>'
        . ($extraHtml ?? "");

    sendEmail(
        (string) $user["email"],
        "Human Matrix: " . $title,
        emailLayout($title, $body, $user["role"] === "admin"),
        "Hello " . $user["full_name"] . ",\n\n" . $message
    );
}