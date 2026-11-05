<?php
// Shared by customer/invoice.php and admin/invoice.php.
// Builds the printable invoice / delivery note for one order.

function invoiceEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Loads an order with its customer and items. Pass $userId to limit the
// lookup to one customer's own orders; pass null for an administrator.
function loadInvoiceData(PDO $pdo, int $orderId, ?int $userId): ?array
{
    $sql = "
        SELECT o.id, o.order_number, o.facility_name, o.contact_phone, o.delivery_address,
               o.notes, o.payment_method, o.payment_status, o.paid_at, o.total_amount,
               o.status, o.created_at,
               u.full_name AS customer_name, u.email AS customer_email
        FROM orders o
        INNER JOIN users u ON u.id = o.user_id
        WHERE o.id = :id
    ";
    $params = [":id" => $orderId];

    if ($userId !== null) {
        $sql .= " AND o.user_id = :user_id";
        $params[":user_id"] = $userId;
    }

    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return null;
    }

    $itemStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(i.product_name, ''), p.name) AS name,
               i.unit_price, i.quantity, i.subtotal
        FROM order_items i
        LEFT JOIN products p ON p.id = i.product_id
        WHERE i.order_id = :order_id
        ORDER BY i.id ASC
    ");
    $itemStmt->execute([":order_id" => $orderId]);

    $deliveryStmt = $pdo->prepare("
        SELECT dispatch_date, estimated_delivery, delivered_date
        FROM deliveries WHERE order_id = :order_id LIMIT 1
    ");
    $deliveryStmt->execute([":order_id" => $orderId]);

    return [
        "order"    => $order,
        "items"    => $itemStmt->fetchAll(PDO::FETCH_ASSOC),
        "delivery" => $deliveryStmt->fetch(PDO::FETCH_ASSOC) ?: null,
    ];
}

function renderInvoicePage(array $data, string $backUrl, string $backLabel): void
{
    $order = $data["order"];
    $items = $data["items"];
    $delivery = $data["delivery"];

    $reference = "HM-" . str_pad((string) $order["id"], 6, "0", STR_PAD_LEFT);
    $e = "invoiceEscape";
    $cancelled = $order["status"] === "cancelled";
    $paid = $order["payment_status"] === "paid";
    $method = $order["payment_method"] === "before_delivery" ? "Pay before delivery" : "Pay upon delivery";
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= $e($reference) ?> - Human Matrix</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f6; font-family: Arial, Helvetica, sans-serif; color: #1f2933; font-size: 14px; }
        .toolbar { max-width: 820px; margin: 16px auto 0; padding: 0 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .toolbar button { background: #2476a8; color: #fff; border: 0; padding: 10px 18px; border-radius: 6px; cursor: pointer; font: inherit; }
        .toolbar a { color: #2476a8; }
        .sheet { position: relative; max-width: 820px; margin: 16px auto; background: #fff; padding: 36px; border: 1px solid #dde3ea; }
        .head { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; border-bottom: 3px solid #2476a8; padding-bottom: 14px; margin-bottom: 20px; }
        .brand { font-size: 24px; font-weight: bold; color: #2476a8; margin: 0; }
        .sub { color: #6b7785; margin: 2px 0 0; }
        .doc-title { text-align: right; }
        .doc-title h1 { margin: 0; font-size: 20px; letter-spacing: 1px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .grid h3 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: #6b7785; letter-spacing: 1px; }
        .grid p { margin: 0 0 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th { background: #f4f7fb; text-align: left; padding: 8px; border-bottom: 2px solid #dde3ea; }
        td { padding: 8px; border-bottom: 1px solid #e5eaf0; }
        .num { text-align: right; }
        .total td { border-bottom: 0; font-size: 16px; font-weight: bold; }
        .pay { margin-bottom: 20px; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; color: #fff; font-weight: bold; font-size: 12px; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 40px; }
        .sign div { border-top: 1px solid #1f2933; padding-top: 6px; color: #475569; }
        .stamp { position: absolute; top: 140px; right: 40px; transform: rotate(-12deg); border: 4px solid #b91c1c; color: #b91c1c; padding: 4px 18px; font-size: 28px; font-weight: bold; opacity: .7; }
        .foot { margin-top: 28px; text-align: center; color: #6b7785; font-size: 12px; }
        @media (max-width: 600px) { .grid, .sign { grid-template-columns: 1fr; } .sheet { padding: 20px; } }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { border: 0; margin: 0; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <a href="<?= $e($backUrl) ?>">&larr; <?= $e($backLabel) ?></a>
</div>

<div class="sheet">
    <?php if ($cancelled): ?><div class="stamp">CANCELLED</div><?php endif; ?>

    <div class="head">
        <div>
            <p class="brand">Human Matrix</p>
            <p class="sub">Medical equipment ordering and delivery</p>
        </div>
        <div class="doc-title">
            <h1>INVOICE / DELIVERY NOTE</h1>
            <p class="sub">No. <strong><?= $e($reference) ?></strong></p>
            <p class="sub">Date: <?= $e(date("d M Y", strtotime($order["created_at"]))) ?></p>
        </div>
    </div>

    <div class="grid">
        <div>
            <h3>Customer</h3>
            <p><strong><?= $e($order["customer_name"]) ?></strong></p>
            <p><?= $e($order["customer_email"]) ?></p>
            <p><?= $e($order["contact_phone"]) ?></p>
        </div>
        <div>
            <h3>Deliver to</h3>
            <p><strong><?= $e($order["facility_name"] ?? "") ?></strong></p>
            <p><?= nl2br($e($order["delivery_address"])) ?></p>
            <?php if (!empty($delivery["estimated_delivery"])): ?>
                <p>Expected: <?= $e(date("d M Y", strtotime($delivery["estimated_delivery"]))) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th class="num">Unit price (KSh)</th>
                <th class="num">Qty</th>
                <th class="num">Amount (KSh)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td><?= (int) $index + 1 ?></td>
                    <td><?= $e($item["name"] ?? "") ?></td>
                    <td class="num"><?= number_format((float) $item["unit_price"], 2) ?></td>
                    <td class="num"><?= (int) $item["quantity"] ?></td>
                    <td class="num"><?= number_format((float) $item["subtotal"], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total">
                <td colspan="4" class="num">Total</td>
                <td class="num">KSh <?= number_format((float) $order["total_amount"], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="pay">
        <strong>Payment:</strong> <?= $e($method) ?> &middot;
        <span class="badge" style="background:<?= $paid ? "#15803d" : "#b45309" ?>;"><?= $paid ? "PAID" : "UNPAID" ?></span>
        <?php if ($paid && !empty($order["paid_at"])): ?>
            <span>(confirmed <?= $e(date("d M Y", strtotime($order["paid_at"]))) ?>)</span>
        <?php endif; ?>
        <br>
        <strong>Order status:</strong> <?= $e(ucfirst(str_replace("_", " ", $order["status"]))) ?>
        <?php if (!empty($order["notes"])): ?>
            <br><strong>Notes:</strong> <?= $e($order["notes"]) ?>
        <?php endif; ?>
    </div>

    <p><strong>Goods received in good condition.</strong></p>

    <div class="sign">
        <div>Delivered by (name &amp; signature)</div>
        <div>Received by (name, signature, stamp &amp; date)</div>
    </div>

    <p class="foot">Thank you for ordering with Human Matrix.</p>
</div>

</body>
</html>
<?php
}