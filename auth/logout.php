<?php

require_once __DIR__ . "/../includes/session.php";

// Logout must only be performed through POST.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method Not Allowed");
}

// Validate the submitted CSRF token.
$submittedToken = $_POST["csrf_token"] ?? null;
$sessionToken = $_SESSION["csrf_token"] ?? null;

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === "" ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit("Invalid logout request.");
}

// Capture the current session cookie configuration.
$cookieParams = session_get_cookie_params();

// Clear session data.
$_SESSION = [];

// Expire the session cookie using the same cookie settings.
if (ini_get("session.use_cookies")) {
    $cookieOptions = [
        "expires" => time() - 42000,
        "path" => $cookieParams["path"],
        "secure" => $cookieParams["secure"],
        "httponly" => $cookieParams["httponly"],
        "samesite" => $cookieParams["samesite"]
    ];

    if ($cookieParams["domain"] !== "") {
        $cookieOptions["domain"] = $cookieParams["domain"];
    }

    setcookie(session_name(), "", $cookieOptions);
}

// Destroy the server-side session.
session_destroy();

// Return the user to the login page.
header("Location: login.php");
exit;