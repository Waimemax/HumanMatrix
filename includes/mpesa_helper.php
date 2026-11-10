<?php
// M-Pesa STK Push (Safaricom Daraja) helpers.
//
// Nothing in this file moves money by itself. The customer must approve the
// request on their own phone by entering their M-Pesa PIN.

require_once __DIR__ . "/notifications.php";

const MPESA_PLACEHOLDER_SECRET = "change-this-to-a-long-random-text";

function mpesaConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [];
    $file = __DIR__ . "/../config/mpesa.php";

    if (is_file($file)) {
        $loaded = require $file;

        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return $config;
}

function mpesaEnabled(): bool
{
    $c = mpesaConfig();

    return !empty($c["enabled"])
        && !empty($c["consumer_key"])
        && !empty($c["consumer_secret"])
        && !empty($c["shortcode"])
        && !empty($c["passkey"])
        && !empty($c["callback_base"])
        && !empty($c["callback_secret"])
        && $c["callback_secret"] !== MPESA_PLACEHOLDER_SECRET;
}

function mpesaBaseUrl(): string
{
    $c = mpesaConfig();

    if (!empty($c["base_url"])) {
        return rtrim((string) $c["base_url"], "/");
    }

    return ($c["environment"] ?? "sandbox") === "production"
        ? "https://api.safaricom.co.ke"
        : "https://sandbox.safaricom.co.ke";
}

// Turns 0712345678, +254712345678, 254 712 345 678 or 712345678 into 254712345678.
// Returns null if it is not a Safaricom-style number.
function mpesaNormalizePhone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (preg_match('/^0([17]\d{8})$/', $digits, $m)) {
        return "254" . $m[1];
    }

    if (preg_match('/^254([17]\d{8})$/', $digits, $m)) {
        return "254" . $m[1];
    }

    if (preg_match('/^([17]\d{8})$/', $digits, $m)) {
        return "254" . $m[1];
    }

    return null;
}

// The amount Safaricom is asked to collect. M-Pesa takes whole shillings only.
function mpesaAmountFor(array $order): int
{
    return (int) ceil((float) $order["total_amount"]);
}

// Can this order be paid with M-Pesa right now?
function mpesaOrderPayable(array $order): bool
{
    return $order["payment_method"] === "before_delivery"
        && $order["payment_status"] !== "paid"
        && !in_array($order["status"], ["cancelled", "delivered"], true);
}

// Plain-language explanation for a Safaricom result code.
function mpesaFriendlyResult(?int $code, ?string $fallback = null): string
{
    $messages = [
        0    => "The payment was successful.",
        1    => "The M-Pesa balance is not enough for this payment.",
        1001 => "Another M-Pesa request is already in progress on this phone. Please wait a minute and try again.",
        1019 => "The payment request expired. Please try again.",
        1025 => "The request could not be sent to the phone. Please try again.",
        1032 => "The payment was cancelled on the phone.",
        1037 => "The phone did not respond in time. Please try again and enter your PIN when the prompt appears.",
        2001 => "The M-Pesa PIN entered was wrong.",
    ];

    if ($code !== null && isset($messages[$code])) {
        return $messages[$code];
    }

    return $fallback !== null && $fallback !== ""
        ? "The payment was not completed: " . $fallback
        : "The payment was not completed. Please try again.";
}

// One HTTPS call to Safaricom. Returns [httpStatus, decoded JSON or null, error text or null].
function mpesaRequest(string $method, string $url, array $headers, ?string $body = null, ?string $basicAuth = null): array
{
    if (!function_exists("curl_init")) {
        return [0, null, "The PHP curl extension is not enabled."];
    }

    $c = mpesaConfig();
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if (!empty($c["ca_bundle"])) {
        curl_setopt($ch, CURLOPT_CAINFO, (string) $c["ca_bundle"]);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $raw === false ? curl_error($ch) : null;
    curl_close($ch);

    if ($raw === false) {
        return [$status, null, $error];
    }

    $decoded = json_decode((string) $raw, true);

    return [$status, is_array($decoded) ? $decoded : null, null];
}

function mpesaAccessToken(): ?string
{
    $c = mpesaConfig();

    [$status, $data, $error] = mpesaRequest(
        "GET",
        mpesaBaseUrl() . "/oauth/v1/generate?grant_type=client_credentials",
        ["Accept: application/json"],
        null,
        $c["consumer_key"] . ":" . $c["consumer_secret"]
    );

    if ($error !== null || $status !== 200 || empty($data["access_token"])) {
        error_log("HumanMatrix M-Pesa token error: HTTP " . $status . " " . ($error ?? ""));

        return null;
    }

    return (string) $data["access_token"];
}

function mpesaTimestamp(): string
{
    return (new DateTime("now", new DateTimeZone("Africa/Nairobi")))->format("YmdHis");
}

function mpesaPassword(string $timestamp): string
{
    $c = mpesaConfig();

    return base64_encode($c["shortcode"] . $c["passkey"] . $timestamp);
}

// Asks Safaricom to show the PIN prompt on the customer's phone.
// Returns ["ok" => bool, "message" => string, "checkout_id" => ?string, "merchant_id" => ?string].
function mpesaStkPush(string $phone, int $amount, string $reference): array
{
    $c = mpesaConfig();
    $token = mpesaAccessToken();

    if ($token === null) {
        return ["ok" => false, "message" => "We could not reach M-Pesa right now. Please try again shortly.", "checkout_id" => null, "merchant_id" => null];
    }

    $timestamp = mpesaTimestamp();

    $payload = [
        "BusinessShortCode" => $c["shortcode"],
        "Password"          => mpesaPassword($timestamp),
        "Timestamp"         => $timestamp,
        "TransactionType"   => "CustomerPayBillOnline",
        "Amount"            => $amount,
        "PartyA"            => $phone,
        "PartyB"            => $c["shortcode"],
        "PhoneNumber"       => $phone,
        "CallBackURL"       => rtrim((string) $c["callback_base"], "/")
                               . "/payments/mpesa_callback.php?key=" . rawurlencode((string) $c["callback_secret"]),
        "AccountReference"  => mb_substr($reference, 0, 12),
        "TransactionDesc"   => "Order payment",
    ];

    [$status, $data, $error] = mpesaRequest(
        "POST",
        mpesaBaseUrl() . "/mpesa/stkpush/v1/processrequest",
        ["Authorization: Bearer " . $token, "Content-Type: application/json"],
        json_encode($payload)
    );

    if ($error !== null) {
        error_log("HumanMatrix M-Pesa STK push error: " . $error);

        return ["ok" => false, "message" => "We could not reach M-Pesa right now. Please try again shortly.", "checkout_id" => null, "merchant_id" => null];
    }

    if ($status === 200 && ($data["ResponseCode"] ?? "") === "0" && !empty($data["CheckoutRequestID"])) {
        return [
            "ok"          => true,
            "message"     => (string) ($data["CustomerMessage"] ?? "Request sent."),
            "checkout_id" => (string) $data["CheckoutRequestID"],
            "merchant_id" => isset($data["MerchantRequestID"]) ? (string) $data["MerchantRequestID"] : null,
        ];
    }

    error_log("HumanMatrix M-Pesa STK push refused: HTTP " . $status . " " . json_encode($data));

    return ["ok" => false, "message" => "M-Pesa did not accept the request. Please check the phone number and try again.", "checkout_id" => null, "merchant_id" => null];
}

// Asks Safaricom what happened to a request. Returns null while it is still
// being processed, otherwise ["code" => int, "desc" => string].
function mpesaStkQuery(string $checkoutId): ?array
{
    $c = mpesaConfig();
    $token = mpesaAccessToken();

    if ($token === null) {
        return null;
    }

    $timestamp = mpesaTimestamp();

    [$status, $data, $error] = mpesaRequest(
        "POST",
        mpesaBaseUrl() . "/mpesa/stkpushquery/v1/query",
        ["Authorization: Bearer " . $token, "Content-Type: application/json"],
        json_encode([
            "BusinessShortCode" => $c["shortcode"],
            "Password"          => mpesaPassword($timestamp),
            "Timestamp"         => $timestamp,
            "CheckoutRequestID" => $checkoutId,
        ])
    );

    // While the customer has not answered yet, Safaricom replies with an error.
    if ($error !== null || $status !== 200 || !isset($data["ResultCode"])) {
        return null;
    }

    return ["code" => (int) $data["ResultCode"], "desc" => (string) ($data["ResultDesc"] ?? "")];
}

// Records the outcome of a payment request. Safe to call more than once for
// the same request (from the callback and from the status check).
// $meta may hold "receipt" and "amount" from Safaricom's callback.
// Returns "success", "failed" or "unknown" (no such request).
function mpesaApplyResult(PDO $pdo, string $checkoutId, int $resultCode, string $resultDesc, array $meta = []): string
{
    $notify = null;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM mpesa_payments WHERE checkout_request_id = :id FOR UPDATE");
        $stmt->execute([":id" => $checkoutId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $pdo->rollBack();

            return "unknown";
        }

        $now = date("Y-m-d H:i:s");
        $receipt = isset($meta["receipt"]) ? mb_substr((string) $meta["receipt"], 0, 30) : null;

        // Already settled: only fill in a receipt number that arrived later.
        if ($payment["status"] === "success") {
            if ($receipt !== null && $receipt !== "" && empty($payment["mpesa_receipt"])) {
                $pdo->prepare("UPDATE mpesa_payments SET mpesa_receipt = :r, updated_at = :now WHERE id = :id")
                    ->execute([":r" => $receipt, ":now" => $now, ":id" => (int) $payment["id"]]);
            }

            $pdo->commit();

            return "success";
        }

        if ($payment["status"] === "failed" && $resultCode !== 0) {
            $pdo->commit();

            return "failed";
        }

        // A "success" that paid less than the order needs is not accepted.
        if ($resultCode === 0 && isset($meta["amount"]) && (float) $meta["amount"] < (float) $payment["amount"]) {
            $resultCode = 9999;
            $resultDesc = "Amount received (" . $meta["amount"] . ") is less than the amount due (" . $payment["amount"] . ").";
            $notify = ["admin_only", $payment, "M-Pesa amount mismatch",
                "A payment for order " . orderReference((int) $payment["order_id"])
                . " reported a different amount than requested. Check it in M-Pesa before delivering."];
        }

        if ($resultCode === 0) {
            $pdo->prepare("
                UPDATE mpesa_payments
                SET status = 'success', result_code = 0, result_desc = :desc,
                    mpesa_receipt = :receipt, updated_at = :now
                WHERE id = :id
            ")->execute([
                ":desc"    => mb_substr($resultDesc, 0, 255),
                ":receipt" => $receipt !== "" ? $receipt : null,
                ":now"     => $now,
                ":id"      => (int) $payment["id"],
            ]);

            $orderStmt = $pdo->prepare("SELECT id, status, payment_status FROM orders WHERE id = :id FOR UPDATE");
            $orderStmt->execute([":id" => (int) $payment["order_id"]]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if ($order && $order["status"] === "cancelled") {
                // Money arrived for an order that was cancelled meanwhile.
                $notify = ["refund", $payment, "M-Pesa payment on a cancelled order",
                    "Payment " . ($receipt ?: "") . " of KES " . number_format((float) $payment["amount"], 2)
                    . " was received for CANCELLED order " . orderReference((int) $payment["order_id"])
                    . ". It needs to be refunded to the customer."];
            } elseif ($order && $order["payment_status"] !== "paid") {
                $pdo->prepare("UPDATE orders SET payment_status = 'paid', paid_at = :now WHERE id = :id")
                    ->execute([":now" => $now, ":id" => (int) $order["id"]]);

                $notify = ["paid", $payment, "M-Pesa payment received",
                    "M-Pesa payment of KES " . number_format((float) $payment["amount"], 2)
                    . " for order " . orderReference((int) $payment["order_id"]) . " has been confirmed"
                    . ($receipt ? " (receipt " . $receipt . ")." : ".")];
            }

            $final = "success";
        } else {
            $pdo->prepare("
                UPDATE mpesa_payments
                SET status = 'failed', result_code = :code, result_desc = :desc, updated_at = :now
                WHERE id = :id
            ")->execute([
                ":code" => $resultCode,
                ":desc" => mb_substr($resultDesc, 0, 255),
                ":now"  => $now,
                ":id"   => (int) $payment["id"],
            ]);

            $final = "failed";
        }

        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("HumanMatrix M-Pesa result error: " . $exception->getMessage());

        return "unknown";
    }

    // Messages go out after the money record is safely saved.
    if ($notify !== null) {
        [$kind, $payment, $title, $text] = $notify;

        if ($kind === "paid") {
            createNotification(
                $pdo,
                (int) $payment["user_id"],
                "Payment received",
                "Your M-Pesa payment for order " . orderReference((int) $payment["order_id"]) . " has been received. Thank you."
            );
        }

        notifyAdmins($pdo, $title, $text);
    }

    return $final;
}

// Checks a pending request with Safaricom (used when the callback has not
// arrived, for example on localhost). Returns the payment's current status.
function mpesaRefreshPending(PDO $pdo, array $payment): string
{
    if ($payment["status"] !== "pending") {
        return (string) $payment["status"];
    }

    $age = time() - strtotime((string) $payment["created_at"]);
    $lastQuery = !empty($payment["last_query_at"]) ? strtotime((string) $payment["last_query_at"]) : 0;

    // Give the customer a moment to answer, and do not ask Safaricom too often.
    if ($age >= 6 && time() - $lastQuery >= 4) {
        $pdo->prepare("UPDATE mpesa_payments SET last_query_at = :now WHERE id = :id")
            ->execute([":now" => date("Y-m-d H:i:s"), ":id" => (int) $payment["id"]]);

        $result = mpesaStkQuery((string) $payment["checkout_request_id"]);

        if ($result !== null) {
            $outcome = mpesaApplyResult($pdo, (string) $payment["checkout_request_id"], $result["code"], $result["desc"]);

            return $outcome === "unknown" ? "pending" : $outcome;
        }
    }

    // Safaricom never answered: stop waiting after 5 minutes.
    if ($age > 300) {
        $outcome = mpesaApplyResult($pdo, (string) $payment["checkout_request_id"], 1037, "No response from the phone.");

        return $outcome === "unknown" ? "pending" : $outcome;
    }

    return "pending";
}