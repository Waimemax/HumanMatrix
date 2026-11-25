<?php
/**
 * HumanMatrix - Centralized Session Security
 *
 * All application PHP sessions should be initialized through this file.
 */

// Prevent unnecessary repeated initialization.
if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

// Determine whether the current request uses HTTPS.
// Do not trust forwarded headers unless a trusted proxy is configured.
$isHttps = (
    isset($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] !== "" &&
    strtolower((string) $_SERVER["HTTPS"]) !== "off"
);

// Also recognize direct HTTPS connections using port 443.
if (isset($_SERVER["SERVER_PORT"]) && (int) $_SERVER["SERVER_PORT"] === 443) {
    $isHttps = true;
}

// Harden PHP session handling.
ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_trans_sid", "0");

// Configure session cookie security.
ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_secure", $isHttps ? "1" : "0");
ini_set("session.cookie_samesite", "Lax");

// Apply cookie settings before starting the session.
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "secure" => $isHttps,
    "httponly" => true,
    "samesite" => "Lax"
]);

// Start the session.
session_start();
