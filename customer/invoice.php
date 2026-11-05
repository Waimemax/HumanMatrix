<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/invoice.php";

$orderId = filter_var($_GET["id"] ?? "", FILTER_VALIDATE_INT);

if ($orderId === false || $orderId < 1) {
    header("Location: orders.php");
    exit;
}

try {
    // Only the customer who placed the order may print it.
    $data = loadInvoiceData($pdo, $orderId, (int) $_SESSION["user_id"]);
} catch (PDOException $exception) {
    error_log("HumanMatrix customer invoice error: " . $exception->getMessage());
    $data = null;
}

if ($data === null) {
    header("Location: orders.php");
    exit;
}

renderInvoicePage($data, "order.php?id=" . $orderId, "Back to my order");