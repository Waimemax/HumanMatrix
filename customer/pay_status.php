<?php
// Answers the "waiting for payment" page with the payment's current state.
// Save as: customer/pay_status.php
session_start();

header("Content-Type: application/json");
header("Cache-Control: no-store");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Please sign in again."]);
    exit;
}

require_once "../config/database.php";
require_once "../includes/mpesa_helper.php";

$paymentId = filter_var($_GET["payment_id"] ?? "", FILTER_VALIDATE_INT);

if ($paymentId === false || $paymentId < 1) {
    echo json_encode(["status" => "error", "message" => "Unknown payment."]);
    exit;
}

try {
    // Only the customer who made the request can ask about it.
    $stmt = $pdo->prepare("SELECT * FROM mpesa_payments WHERE id = :id AND user_id = :user_id LIMIT 1");
    $stmt->execute([":id" => $paymentId, ":user_id" => (int) $_SESSION["user_id"]]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        echo json_encode(["status" => "error", "message" => "Unknown payment."]);
        exit;
    }

    $status = mpesaRefreshPending($pdo, $payment);

    if ($status !== "pending") {
        $stmt->execute([":id" => $paymentId, ":user_id" => (int) $_SESSION["user_id"]]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: $payment;
    }

    $message = "";

    if ($status === "success") {
        $message = "Payment received. Thank you!";
    } elseif ($status === "failed") {
        $message = mpesaFriendlyResult(
            isset($payment["result_code"]) ? (int) $payment["result_code"] : null,
            (string) ($payment["result_desc"] ?? "")
        );
    }

    echo json_encode([
        "status"  => $status,
        "message" => $message,
        "receipt" => $payment["mpesa_receipt"] ?? null,
    ]);
} catch (PDOException $exception) {
    error_log("HumanMatrix M-Pesa status error: " . $exception->getMessage());
    echo json_encode(["status" => "pending", "message" => ""]);
}