<?php
// Safaricom calls this address after the customer answers the M-Pesa prompt.
// Save as: payments/mpesa_callback.php  (create the "payments" folder).
//
// It has no login because Safaricom, not a person, calls it. It is protected by
// the secret in the address, and it can only update a payment request that
// Human Matrix itself created.

require_once "../config/database.php";
require_once "../includes/mpesa_helper.php";

header("Content-Type: application/json");

function acknowledge(): void
{
    echo json_encode(["ResultCode" => 0, "ResultDesc" => "Accepted"]);
    exit;
}

$config = mpesaConfig();
$key = $_GET["key"] ?? "";

if (
    !is_string($key) ||
    empty($config["callback_secret"]) ||
    !hash_equals((string) $config["callback_secret"], $key)
) {
    http_response_code(403);
    echo json_encode(["ResultCode" => 1, "ResultDesc" => "Rejected"]);
    exit;
}

$raw = file_get_contents("php://input", false, null, 0, 65536);
$data = json_decode((string) $raw, true);
$callback = is_array($data) ? ($data["Body"]["stkCallback"] ?? null) : null;

if (!is_array($callback) || empty($callback["CheckoutRequestID"]) || !isset($callback["ResultCode"])) {
    acknowledge();
}

$meta = [];

foreach (($callback["CallbackMetadata"]["Item"] ?? []) as $item) {
    if (!is_array($item) || !isset($item["Name"])) {
        continue;
    }

    if ($item["Name"] === "MpesaReceiptNumber" && isset($item["Value"])) {
        $meta["receipt"] = (string) $item["Value"];
    }

    if ($item["Name"] === "Amount" && isset($item["Value"])) {
        $meta["amount"] = $item["Value"];
    }
}

mpesaApplyResult(
    $pdo,
    (string) $callback["CheckoutRequestID"],
    (int) $callback["ResultCode"],
    (string) ($callback["ResultDesc"] ?? ""),
    $meta
);

acknowledge();