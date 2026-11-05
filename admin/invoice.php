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
require_once "../includes/invoice.php";

$orderId = filter_var($_GET["id"] ?? "", FILTER_VALIDATE_INT);

if ($orderId === false || $orderId < 1) {
    header("Location: orders.php");
    exit;
}

try {
    $data = loadInvoiceData($pdo, $orderId, null);
} catch (PDOException $exception) {
    error_log("HumanMatrix admin invoice error: " . $exception->getMessage());
    $data = null;
}

if ($data === null) {
    header("Location: orders.php");
    exit;
}

renderInvoicePage($data, "order_view.php?id=" . $orderId, "Back to the order");