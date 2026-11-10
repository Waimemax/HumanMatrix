<?php
// Pay for an order with M-Pesa.  Save as: customer/pay_mpesa.php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/database.php";
require_once "../includes/mpesa_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$userId = (int) $_SESSION["user_id"];
$error = "";
$order = null;
$payment = null;
$mode = "form";   // form | waiting | unavailable | notfound

$paymentId = filter_var($_GET["payment_id"] ?? "", FILTER_VALIDATE_INT);
$orderId = filter_var($_GET["order_id"] ?? ($_POST["order_id"] ?? ""), FILTER_VALIDATE_INT);

try {
    if ($paymentId !== false && $paymentId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM mpesa_payments WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute([":id" => $paymentId, ":user_id" => $userId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($payment) {
            $orderId = (int) $payment["order_id"];
            $mode = "waiting";
        }
    }

    if ($orderId === false || $orderId < 1) {
        $mode = "notfound";
    } else {
        // Only the customer who placed the order can pay for it.
        $stmt = $pdo->prepare("
            SELECT id, facility_name, contact_phone, total_amount, status,
                   payment_method, payment_status
            FROM orders WHERE id = :id AND user_id = :user_id LIMIT 1
        ");
        $stmt->execute([":id" => $orderId, ":user_id" => $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$order) {
            $mode = "notfound";
        } elseif ($mode === "form" && !mpesaEnabled()) {
            $mode = "unavailable";
        }
    }

    if ($mode === "form" && $order && $_SERVER["REQUEST_METHOD"] === "POST") {
        $token = $_POST["csrf_token"] ?? "";

        if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
            $error = "Invalid form submission. Please refresh the page and try again.";
        } elseif (!mpesaOrderPayable($order)) {
            $error = $order["payment_status"] === "paid"
                ? "This order has already been paid."
                : "This order cannot be paid with M-Pesa.";
        } else {
            $phone = mpesaNormalizePhone((string) ($_POST["phone"] ?? ""));

            if ($phone === null) {
                $error = "Enter a valid Safaricom number, such as 0712 345 678.";
            } else {
                // A request sent in the last 90 seconds is still waiting: go back to it.
                $recent = $pdo->prepare("
                    SELECT id FROM mpesa_payments
                    WHERE order_id = :order_id AND status = 'pending' AND created_at >= :since
                    ORDER BY id DESC LIMIT 1
                ");
                $recent->execute([":order_id" => $orderId, ":since" => date("Y-m-d H:i:s", time() - 90)]);
                $existingId = $recent->fetchColumn();

                // No more than 5 requests for one order per hour.
                $count = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE order_id = :order_id AND created_at >= :since");
                $count->execute([":order_id" => $orderId, ":since" => date("Y-m-d H:i:s", time() - 3600)]);

                if ($existingId) {
                    header("Location: pay_mpesa.php?payment_id=" . (int) $existingId);
                    exit;
                }

                if ((int) $count->fetchColumn() >= 5) {
                    $error = "Too many payment attempts for this order. Please wait an hour or contact HumanMatrix.";
                } else {
                    $amount = mpesaAmountFor($order);
                    $reference = "HM-" . str_pad((string) $orderId, 6, "0", STR_PAD_LEFT);
                    $push = mpesaStkPush($phone, $amount, $reference);

                    if (!$push["ok"]) {
                        $error = $push["message"];
                    } else {
                        $now = date("Y-m-d H:i:s");
                        $insert = $pdo->prepare("
                            INSERT INTO mpesa_payments
                                (order_id, user_id, phone, amount, merchant_request_id,
                                 checkout_request_id, status, created_at, updated_at)
                            VALUES
                                (:order_id, :user_id, :phone, :amount, :merchant,
                                 :checkout, 'pending', :now, :now2)
                        ");
                        $insert->execute([
                            ":order_id" => $orderId,
                            ":user_id"  => $userId,
                            ":phone"    => $phone,
                            ":amount"   => $amount,
                            ":merchant" => $push["merchant_id"],
                            ":checkout" => $push["checkout_id"],
                            ":now"      => $now,
                            ":now2"     => $now,
                        ]);

                        header("Location: pay_mpesa.php?payment_id=" . (int) $pdo->lastInsertId());
                        exit;
                    }
                }
            }
        }
    }
} catch (PDOException $exception) {
    error_log("HumanMatrix M-Pesa page error: " . $exception->getMessage());
    $error = "Something went wrong. Please try again.";
}

if (!$order && $mode !== "notfound") {
    $mode = "notfound";
}

$reference = $order ? "HM-" . str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT) : "";
$amountDue = $order ? mpesaAmountFor($order) : 0;
$phoneValue = $_SERVER["REQUEST_METHOD"] === "POST"
    ? (string) ($_POST["phone"] ?? "")
    : (string) ($order["contact_phone"] ?? "");

$boxStyle = "max-width:520px; padding:20px; border:1px solid #e5eaf0; border-radius:10px; background:#fff;";
$inputStyle = "width:100%; padding:10px; border:1px solid #ccd5df; border-radius:6px; font:inherit; box-sizing:border-box;";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay with M-Pesa | HumanMatrix</title>
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>

<header class="catalogue-header">
    <a class="catalogue-brand" href="dashboard.php">
        Human<span>Matrix</span>
    </a>

    <nav class="catalogue-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="product.php">Equipment</a>
        <a href="orders.php">My Orders</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="catalogue-main">
    <section class="catalogue-section">

        <?php if ($mode === "notfound"): ?>
            <div class="catalogue-message">
                <h3>Order not found</h3>
                <p><a href="orders.php">Back to my orders</a></p>
            </div>

        <?php elseif ($mode === "unavailable"): ?>
            <div class="catalogue-message">
                <h3>M-Pesa is not available right now</h3>
                <p>Please contact HumanMatrix to arrange payment.
                   <a href="order.php?id=<?= (int) $orderId ?>">Back to the order</a></p>
            </div>

        <?php elseif ($mode === "waiting" && $payment): ?>
            <div class="catalogue-section-heading">
                <div>
                    <h2>Pay with M-Pesa</h2>
                    <p>Order <?= e($reference) ?> &middot; KSh <?= number_format((float) $payment["amount"], 2) ?></p>
                </div>
            </div>

            <div style="<?= $boxStyle ?>">
                <div id="state-waiting">
                    <h3 style="margin-top:0;">Check your phone</h3>
                    <p>
                        An M-Pesa prompt was sent to <strong><?= e($payment["phone"]) ?></strong>.
                        Enter your M-Pesa PIN to approve KSh <?= number_format((float) $payment["amount"], 2) ?>.
                    </p>
                    <p style="color:#475569;">Waiting for confirmation&hellip; <span id="dots"></span></p>
                </div>

                <div id="state-success" style="display:none;">
                    <h3 style="margin-top:0; color:#15803d;">Payment received</h3>
                    <p id="success-text">Thank you. Your payment has been confirmed.</p>
                    <p><a href="order.php?id=<?= (int) $order["id"] ?>">View my order</a></p>
                </div>

                <div id="state-failed" style="display:none;">
                    <h3 style="margin-top:0; color:#b91c1c;">Payment not completed</h3>
                    <p id="failed-text"></p>
                    <p>
                        <a href="pay_mpesa.php?order_id=<?= (int) $order["id"] ?>">Try again</a> &middot;
                        <a href="order.php?id=<?= (int) $order["id"] ?>">Back to my order</a>
                    </p>
                </div>
            </div>

            <script>
                (function () {
                    var url = "pay_status.php?payment_id=<?= (int) $payment["id"] ?>";
                    var waiting = document.getElementById("state-waiting");
                    var success = document.getElementById("state-success");
                    var failed = document.getElementById("state-failed");
                    var dots = document.getElementById("dots");
                    var tick = 0;
                    var timer = null;

                    function show(el) {
                        waiting.style.display = "none";
                        success.style.display = "none";
                        failed.style.display = "none";
                        el.style.display = "block";
                    }

                    function check() {
                        fetch(url, { credentials: "same-origin", cache: "no-store" })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (data.status === "success") {
                                    clearInterval(timer);
                                    if (data.receipt) {
                                        document.getElementById("success-text").textContent =
                                            "Thank you. M-Pesa receipt: " + data.receipt;
                                    }
                                    show(success);
                                } else if (data.status === "failed" || data.status === "error") {
                                    clearInterval(timer);
                                    document.getElementById("failed-text").textContent =
                                        data.message || "The payment was not completed.";
                                    show(failed);
                                }
                            })
                            .catch(function () { /* try again on the next tick */ });
                    }

                    timer = setInterval(function () {
                        tick++;
                        dots.textContent = new Array((tick % 3) + 2).join(".");
                        check();
                    }, 3000);
                    check();
                })();
            </script>

        <?php else: ?>
            <div class="catalogue-section-heading">
                <div>
                    <h2>Pay with M-Pesa</h2>
                    <p>Order <?= e($reference) ?> &middot; <?= e($order["facility_name"] ?? "") ?></p>
                </div>
            </div>

            <?php if (!mpesaOrderPayable($order)): ?>
                <div class="catalogue-message">
                    <?= $order["payment_status"] === "paid"
                        ? "This order has already been paid. Thank you."
                        : "This order cannot be paid with M-Pesa." ?>
                    <a href="order.php?id=<?= (int) $order["id"] ?>">Back to the order</a>
                </div>
            <?php else: ?>
                <div style="<?= $boxStyle ?>">
                    <p style="margin-top:0;">
                        Amount to pay: <strong>KSh <?= number_format($amountDue, 2) ?></strong>
                    </p>

                    <?php if ((float) $order["total_amount"] !== (float) $amountDue): ?>
                        <p><small>M-Pesa takes whole shillings, so the amount is rounded up.</small></p>
                    <?php endif; ?>

                    <?php if ($error !== ""): ?>
                        <div class="catalogue-message" role="alert" style="border-left:4px solid #b91c1c;"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="pay_mpesa.php?order_id=<?= (int) $order["id"] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>">

                        <label for="phone" style="display:block; margin:8px 0 4px; font-weight:bold;">M-Pesa phone number</label>
                        <input type="tel" id="phone" name="phone" required maxlength="20"
                               placeholder="0712 345 678" value="<?= e($phoneValue) ?>" style="<?= $inputStyle ?>">
                        <small>You will get a prompt on this phone to enter your M-Pesa PIN.</small>

                        <p style="margin:16px 0 0;">
                            <button type="submit">Send M-Pesa request</button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <p style="margin-top:16px;"><a href="order.php?id=<?= (int) $order["id"] ?>">&larr; Back to the order</a></p>
        <?php endif; ?>

    </section>
</main>

<footer class="catalogue-footer">
    <p>&copy; <?= date("Y") ?> HumanMatrix. Medical equipment made accessible.</p>
</footer>

</body>
</html>