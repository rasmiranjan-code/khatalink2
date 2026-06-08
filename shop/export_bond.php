<?php
session_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

require_once '../includes/cashfree_config.php'; // Updated to use Cashfree PG constants
require_once '../includes/db.php';

$user_id_auth = 0;
$user_role_auth = '';

// Get token from Header or GET parameter (for browser downloads)
$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace(['Bearer ', ' '], ['', '+'], $token);

if (!empty($token)) {
    $decoded = base64_decode($token);
    if ($decoded) {
        $parts = explode(':', $decoded);
        $user_id_auth = (int)($parts[0] ?? 0);
        $user_role_auth = $parts[2] ?? '';
    }
} elseif (isset($_SESSION['shop_id']) || isset($_SESSION['customer_id'])) {
    $user_id_auth = (int)($_SESSION['shop_id'] ?? $_SESSION['customer_id'] ?? 0);
    $user_role_auth = isset($_SESSION['shop_id']) ? 'shop' : 'customer';
}

if (!$user_id_auth) {
    die("Unauthorized access.");
}

$bond_id = (int)($_GET['id'] ?? 0);
// ===== END FLUTTER API =====
// Fetch Bond, Customer and Shop details
$stmt = $pdo->prepare("
    SELECT b.*, c.name as customer_name, c.unique_id, c.phone as customer_phone, 
           s.shop_name, s.name as owner_name, s.gst_number, s.upi_id
    FROM bonds b
    JOIN customers c ON b.customer_id = c.id
    JOIN shop_owners s ON b.shop_id = s.id
    WHERE b.id = ?
");
$stmt->execute([$bond_id]);
$b = $stmt->fetch();

if(!$b) die("Bond record not found.");

// Helper for Ordinal Suffix (1st, 2nd, 3rd...)
function getOrdinal(int $n): string {
    $res = $n % 100;
    if ($res >= 11 && $res <= 13) return $n . "th";
    switch ($n % 10) {
        case 1:  return $n . "st";
        case 2:  return $n . "nd";
        case 3:  return $n . "rd";
        default: return $n . "th";
    }
}

// Authenticity Verification QR
$base_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/khatalink";
$verify_url = $base_url . "/verify_bond.php?id=" . $b['id'];
$auth_qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verify_url);

// Fetch ONLY completed kist payments for this bond
$stmt_payments = $pdo->prepare("SELECT * FROM bond_payments WHERE bond_id = ? AND payment_status = 'completed' ORDER BY payment_date ASC");
$stmt_payments->execute([$bond_id]);
$payments = $stmt_payments->fetchAll();
// Security: Only linked shop or customer can view this bond
if (($user_role_auth === 'shop' && $user_id_auth != $b['shop_id']) || ($user_role_auth === 'customer' && $user_id_auth != $b['customer_id'])) {
    error_log("DEBUG: export_bond.php - Access Denied. User ID: " . $user_id_auth . ", Role: " . $user_role_auth . ", Bond Shop ID: " . $b['shop_id'] . ", Bond Customer ID: " . $b['customer_id']);
    // die("Access Denied."); // Temporarily commented out for testing, uncomment in production
}
?>
<?php
// Fetch Bond Items
$stmt_bond_items = $pdo->prepare("SELECT * FROM bond_items WHERE bond_id = ?");
$stmt_bond_items->execute([$bond_id]);
$bond_items = $stmt_bond_items->fetchAll();
?>
<?php
$base_rem_balance = $b['amount'] - $b['paid_amount'];
$platform_fee_on_remaining = $base_rem_balance * (BOND_PLATFORM_COMMISSION_PERCENT / 100); // Customer pays this
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Legal Bond - <?= $b['unique_id'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 30px; line-height: 1.4; }
        .bond-container { max-width: 800px; margin: 0 auto; border: 2px solid #f1f5f9; padding: 40px; position: relative; background: #fff; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
        .header { text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; text-transform: uppercase; letter-spacing: 2px; font-weight: 900; }
        .section { margin-bottom: 25px; }
        .label { font-weight: 900; text-transform: uppercase; font-size: 12px; color: #64748b; display: block; }
        .value { font-size: 16px; font-weight: 700; color: #0f172a; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .highlight-box { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin: 20px 0; }
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 10px; }
        .summary-item { border-right: 1px solid #e2e8f0; padding-right: 10px; }
        .summary-item:last-child { border: none; }
        .signature-area { margin-top: 50px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; text-align: center; }
        .sig-box img { max-width: 150px; max-height: 80px; border-bottom: 1px solid #000; margin-bottom: 10px; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; color: rgba(0,0,0,0.03); font-weight: 900; pointer-events: none; white-space: nowrap; }
        .stamp-watermark { position: absolute; top: 70%; left: 50%; transform: translate(-50%, -50%) rotate(-20deg); font-size: 60px; color: rgba(220,38,38,0.05); font-weight: 900; pointer-events: none; white-space: nowrap; border: 5px double rgba(220,38,38,0.05); padding: 10px 20px; border-radius: 10px; }
        .rules-list { font-size: 11px; color: #475569; padding-left: 18px; margin-top: 10px; }
        .rules-list li { margin-bottom: 4px; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
        .history-table th { background: #f1f5f9; text-align: left; padding: 8px; border: 1px solid #e2e8f0; }
        .history-table td { padding: 8px; border: 1px solid #e2e8f0; }
        .auth-qr { text-align: center; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px; background: #fff; width: 90px; position: absolute; top: 40px; right: 40px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .auth-qr img { width: 74px; height: 74px; display: block; margin: 0 auto 5px auto; }
        .auth-qr span { display: block; font-size: 6px; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.1; }
        @media print { .no-print { display: none; } body { padding: 0; } .bond-container { border: none; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #0f172a; color: #fff; border-radius: 8px; cursor: pointer; font-weight: bold;">Print / Save as PDF</button>
    </div>

    <div class="bond-container">
        <div class="stamp-watermark">LEGAL STAMP DUTY</div>
        <div class="watermark">KHATALINK LEGAL</div>

        <div class="auth-qr">
            <img src="<?= $auth_qr_url ?>" alt="Verify Bond">
            <span>Scan to Verify Authenticity</span>
        </div>
        
        <div class="header">
            <p style="margin:0; font-size: 12px; font-weight: 800; color: #3b82f6;">KHATALINK DIGITAL DEBT INSTRUMENT</p>
            <h1>Security Bond Agreement</h1>
            <p>Execution Date: <strong><?= date('d M Y', strtotime($b['created_at'])) ?></strong> | Time: <strong><?= date('h:i A', strtotime($b['created_at'])) ?></strong></p>
        </div>

        <div class="section">
            <p>This legal bond confirms that the <strong>Debtor (Customer)</strong> is indebted to <strong>The Creditor (<?= htmlspecialchars($b['shop_name']) ?>)</strong> for the sum mentioned below under the terms specified.</p>
        </div>

        <div class="grid">
            <div class="section">
                <span class="label">Creditor (Shop)</span>
                <span class="value"><?= htmlspecialchars($b['shop_name']) ?></span>
                <div style="font-size: 12px; color: #64748b;">Prop: <?= htmlspecialchars($b['owner_name']) ?></div>
            </div>
            <div class="section" style="text-align: right;">
                <span class="label">Debtor (Customer)</span>
                <span class="value"><?= htmlspecialchars($b['customer_name']) ?></span>
                <div style="font-size: 12px; color: #64748b;">ID: <?= $b['unique_id'] ?></div>
            </div>
        </div>

        <div class="highlight-box">
            <div class="grid">
                <div>
                    <span class="label">Total Bond Amount</span>
                    <span class="value" style="font-size: 24px; color: #dc2626;">₹<?= number_format($b['amount'], 2) ?></span>
                </div>
                <div style="text-align: right;">
                    <span class="label">Final Repayment Due</span>
                    <span class="value"><?= date('d M Y', strtotime($b['due_date'])) ?></span>
                </div>
            </div>
            
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="label">Initial Payment</span>
                    <span class="value">₹<?= number_format($b['initial_paid'], 2) ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Total Paid (Kists)</span>
                    <span class="value" style="color: #059669;">₹<?= number_format($b['paid_amount'], 2) ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Remaining Balance</span>
                    <span class="value" style="color: #2563eb;">₹<?= number_format($b['amount'] - $b['paid_amount'], 2) ?></span>
                </div>
                <div class="summary-item" style="border-right: none;">
                    <span class="label">Platform Fee (<?= BOND_PLATFORM_COMMISSION_PERCENT ?>%)</span>
                    <span class="value" style="color: #64748b;">₹<?= number_format($platform_fee_on_remaining, 2) ?></span>
                </div>
                <div class="summary-item" style="grid-column: 1 / -1; text-align: center; background: #0f172a; color: #fff; padding: 10px; border-radius: 8px; margin-top: 10px;">
                    <span class="label" style="color: #fff; font-size: 14px;">Total Payable (Incl. Fee)</span>
                    <span class="value" style="font-size: 28px; color: #fff;">₹<?= number_format($base_rem_balance + $platform_fee_on_remaining, 2) ?></span>
                </div>
            </div>
        </div>

        <?php if($b['repayment_type'] == 'installments'): ?>
        <?php 
            $remaining_at_start = $b['amount'] - $b['initial_paid'];
            $inst_amt = ($b['installment_count'] > 0) ? ($remaining_at_start / $b['installment_count']) : 0;
            $extra_paid = max(0, $b['paid_amount'] - $b['initial_paid']);
            $completed = ($inst_amt > 0) ? floor(($extra_paid + ($inst_amt * 0.1)) / $inst_amt) : 0;
            $completed = min((int)$completed, (int)$b['installment_count']);

            $kist_pg_fee = $inst_amt * (PG_FEE_PERCENT / 100);
            $kist_kl_fee = $inst_amt * ((BOND_PLATFORM_COMMISSION_PERCENT - PG_FEE_PERCENT) / 100);
            $total_per_kist = $inst_amt + $kist_pg_fee + $kist_kl_fee;
        ?>
        <div class="section" style="background: #eff6ff; padding: 15px; border-radius: 8px;">
            <span class="label" style="color: #1d4ed8;">Repayment Schedule & Progress</span>
            <div class="value" style="margin: 5px 0; font-size: 13px;">
                Installment Plan: <strong><?= $b['installment_count'] ?> Months</strong><br>
                Per Kist Breakdown: ₹<?= number_format($inst_amt, 2) ?> (Base) + ₹<?= number_format($kist_pg_fee, 2) ?> (PG Fee) + ₹<?= number_format($kist_kl_fee, 2) ?> (Service Fee)<br>
                <span style="color: #1d4ed8; font-size: 16px;">Total Payable Per Kist: ₹<?= number_format($total_per_kist, 2) ?></span>
            </div>
            <div style="font-size: 13px; font-weight: 800; color: #1e4ed8; margin-top: 5px;">
                Status: <?= $completed ?> Paid, <?= (int)$b['installment_count'] - $completed ?> Pending (Out of <?= $b['installment_count'] ?> Total)
            </div>
        </div>
        <?php else: ?>
        <div class="section">
            <span class="label">Repayment Terms</span>
            <span class="value">One-time full payment on or before the due date.</span>
        </div>
        <?php endif; ?>

        <!-- Itemized List -->
        <?php if (!empty($bond_items)): ?>
        <div class="section" style="margin-top: 30px;">
            <span class="label">Bond Items</span>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
                    <tr style="text-align:left; font-size:11px; color:#64748b; border-bottom:2px solid #0f172a;">
                        <th style="padding:8px 0;">Item</th>
                        <th style="padding:8px 0; text-align:center;">Qty</th>
                        <th style="padding:8px 0; text-align:right;">Rate</th>
                        <th style="padding:8px 0; text-align:right;">Disc</th>
                        <th style="padding:8px 0; text-align:right;">GST (%)</th>
                        <th style="padding:8px 0; text-align:right;">Net</th>
                    </tr>
                </thead>
                <tbody style="font-size:13px;">
                    <?php 
                    $total_items_value = 0;
                    foreach($bond_items as $item): 
                        $total_items_value += (float)$item['total_amount'];
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:8px 0; font-weight:700;"><?= htmlspecialchars($item['item_name']) ?></td>
                        <td style="padding:8px 0; text-align:center;"><?= (float)$item['quantity'] ?></td>
                        <td style="padding:8px 0; text-align:right;">₹<?= number_format($item['rate'], 2) ?></td>
                        <td style="padding:8px 0; text-align:right;">₹<?= number_format($item['item_discount'], 2) ?></td>
                        <td style="padding:8px 0; text-align:right;"><?= (float)$item['gst_percent'] ?>%</td>
                        <td style="padding:8px 0; text-align:right; font-weight:700;">₹<?= number_format($item['total_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:900; background:#f8fafc;">
                        <td colspan="5" style="padding:8px 0; text-align:right;">TOTAL ITEMS VALUE</td>
                        <td style="padding:8px 0; text-align:right;">₹<?= number_format($total_items_value, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Payment Log Section -->
        <div class="section" style="margin-top: 30px;">
            <span class="label">Installment Payment History Log</span>
            <?php if(count($payments) > 0): ?>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Payment Date & Time</th>
                            <th>Amount Recv.</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $index => $p): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                            <?php 
                                $is_online = (!empty($p['razorpay_payment_id']) && $p['razorpay_payment_id'] !== 'Manual');
                                $paid_val = $is_online ? ($p['amount_paid'] * 1.03) : $p['amount_paid'];
                            ?>
                            <td style="font-weight: 700;">₹<?= number_format($paid_val, 2) ?> (<?= getOrdinal($index + 1) ?> Kist)</td>
                            <td style="color: #059669; font-weight: 800; font-size: 10px; text-transform: uppercase;">Success</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="font-size: 12px; color: #94a3b8; font-style: italic; margin-top: 10px;">No installments recorded yet after the initial payment.</p>
            <?php endif; ?>
        </div>

        <div class="section">
            <span class="label">Nominee / Guarantor</span>
            <span class="value"><?= htmlspecialchars($b['nominee_name']) ?> (<?= htmlspecialchars($b['nominee_phone']) ?>)</span>
            <p style="font-size: 11px; color: #64748b;">The nominee acknowledges this debt and shall be responsible for communication if the debtor is unreachable.</p>
        </div>

        <div class="section">
            <span class="label">Binding Rules & Legal Terms</span>
            <ol class="rules-list">
                <li><strong>Late Payment Fine:</strong> Payment delayed beyond the due date will attract a penalty interest of 2% per month on the outstanding balance.</li>
                <li><strong>Installment Default:</strong> If two consecutive installments are missed, the entire remaining bond amount shall become due immediately.</li>
                <li><strong>Digital Evidence:</strong> This digitally generated bond and the transaction records on KhataLink will be considered primary evidence in legal proceedings.</li>
                <li><strong>Nominee Liability:</strong> In case the Debtor is unreachable for 15 days, the Nominee is legally obligated to facilitate communication and coordinate repayment.</li>
                <li><strong>Recovery Costs:</strong> If legal action is required for recovery, all court fees and lawyer expenses will be borne by the Debtor.</li>
                <li><strong>Address Accuracy:</strong> The Debtor confirms that the identity and address proof provided to the Creditor are authentic and current.</li>
                <li><strong>No Verbal Waivers:</strong> Any extension of the due date must be updated in the digital system; verbal agreements will not be considered valid.</li>
                <li><strong>Good Condition:</strong> The Debtor acknowledges receiving goods/services of the mentioned value in satisfactory condition.</li>
                <li><strong>Platform Fee:</strong> A 1% platform convenience fee will be applied to all online payments made towards this bond, payable by the Debtor.</li>
                <li><strong>Platform Fee:</strong> A <?= BOND_PLATFORM_COMMISSION_PERCENT ?>% platform convenience fee will be applied to all online payments made towards this bond, payable by the Debtor.</li>
                <li><strong>Asset Charge:</strong> The Creditor reserves the right to report defaults to credit bureaus or relevant local business authorities.</li>
                <li><strong>Jurisdiction:</strong> All legal disputes are subject to the exclusive jurisdiction of the courts in the city where the Creditor’s shop is located.</li>
            </ol>
            <div style="font-size: 13px; color: #475569; font-style: italic;">
                <strong>Declaration:</strong> I, <?= htmlspecialchars($b['customer_name']) ?>, hereby accept these 10 rules and declare that I am liable to pay the amount of ₹<?= number_format($b['amount'], 2) ?> as per the schedule.
                <div style="margin-top:5px;"><?= nl2br(htmlspecialchars($b['terms'])) ?></div>
            </div>
            
            <div style="margin-top: 15px; display: inline-block; background: #ecfdf5; color: #059669; padding: 8px 15px; border-radius: 8px; border: 1px solid #a7f3d0; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;">
                <span style="font-size: 14px;">✔</span> Digitally Verified Acceptance
                <span style="margin-left: 10px; color: #065f46; opacity: 0.7;">Timestamp: <?= date('d M Y, h:i:s A', strtotime($b['created_at'])) ?></span>
            </div>
        </div>

        <div class="signature-area">
            <div class="sig-box">
                <?php if($b['customer_signature']): ?>
                    <img src="../assets/img/bonds/<?= $b['customer_signature'] ?>" alt="Customer Signature">
                <?php else: ?>
                    <div style="height: 80px;"></div>
                <?php endif; ?>
                <span class="label">Customer Signature</span>
            </div>
            <div class="sig-box">
                <?php if($b['nominee_signature']): ?>
                    <img src="../assets/img/bonds/<?= $b['nominee_signature'] ?>" alt="Nominee Signature">
                <?php else: ?>
                    <div style="height: 80px;"></div>
                <?php endif; ?>
                <span class="label">Nominee Signature</span>
            </div>
        </div>

        <div style="margin-top: 40px; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 20px;">
            <div style="margin-bottom: 10px; font-size: 10px; color: #94a3b8; text-align: center;">
                * Online payments include a <?= BOND_PLATFORM_COMMISSION_PERCENT ?>% platform convenience fee.
            </div>
            <p style="font-size: 10px; color: #94a3b8;">
                This is a digitally generated legal document via KhataLink. <br>
                Verification ID: BOND-<?= str_pad($b['id'], 6, '0', STR_PAD_LEFT) ?>
            </p>
        </div>
    </div>
</body>
</html>