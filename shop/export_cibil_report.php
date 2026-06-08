<?php
session_start();
require_once '../includes/db.php';

// ✅ Step 1: Token + Session Auth - Flutter + Website dono support
$shop_id = 0;
// Header se token - Flutter API calls ke liye
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
// URL se token - PDF download ke liye kyunki header nahi jaata
if (empty($token) && isset($_GET['token'])) {
    $token = $_GET['token'];
}

if (!empty($token)) {
    // Method 1: Flutter Token Auth
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
} elseif (isset($_SESSION['shop_id'])) {
    // Method 2: Website Session Auth
    $shop_id = (int)$_SESSION['shop_id'];
}

if ($shop_id <= 0) {
    die("Unauthorized: Please login first");
}

$customer_uid = trim($_GET['unique_id']?? '');
if (empty($customer_uid)) { die("Customer ID required"); }

// ✅ Step 2: Fetch Data - Reuse logic from API
$stmt_c = $pdo->prepare("SELECT id, name, unique_id, created_at FROM customers WHERE unique_id =?");
$stmt_c->execute([$customer_uid]);
$customer = $stmt_c->fetch();
if (!$customer) { die("Customer Not Found"); }
$cid = $customer['id'];

// Aggregates - Udhar Entries
$u = $pdo->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total, SUM(total_paid) as paid FROM udhar_entries WHERE customer_id =?");
$u->execute([$cid]);
$u_data = $u->fetch();

// Aggregates - Bonds
$b = $pdo->prepare("SELECT COUNT(*) as count, SUM(amount) as total, SUM(paid_amount) as paid FROM bonds WHERE customer_id =?");
$b->execute([$cid]);
$b_data = $b->fetch();

// Aggregates - Monthly Khata
$m = $pdo->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total, SUM(paid_amount) as paid FROM monthly_khata WHERE customer_id =?");
$m->execute([$cid]);
$m_data = $m->fetch();

// ✅ Step 3: Calculate Trust Score
$total_borrowed = (float)$u_data['total'] + (float)$b_data['total'] + (float)$m_data['total'];
$total_paid = (float)$u_data['paid'] + (float)$b_data['paid'] + (float)$m_data['paid'];
$score = ($total_borrowed > 0)? round(($total_paid / $total_borrowed) * 100) : 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trust Report - <?= htmlspecialchars($customer['unique_id'])?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1e293b; }
       .report-card { max-width: 800px; margin: 0 auto; border: 1px solid #e2e8f0; padding: 40px; position: relative; }
       .header { display: flex; justify-content: space-between; border-bottom: 3px solid #0f172a; padding-bottom: 20px; margin-bottom: 30px; }
       .score-circle { width: 100px; height: 100px; border-radius: 50%; border: 8px solid #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 900; }
       .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
       .stat-box { background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; }
       .label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; }
       .value { font-size: 18px; font-weight: 900; color: #0f172a; }
       .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-30deg); font-size: 80px; opacity: 0.03; pointer-events: none; }
        @media print {.no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #4F46E5; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer;">
            Print Report
        </button>
    </div>
    <div class="report-card">
        <div class="watermark">KHATALINK VERIFIED</div>
        <div class="header">
            <div>
                <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" style="height: 40px;">
                <h1 style="margin:10px 0 0 0; font-size: 20px;">Customer Credit Assessment</h1>
            </div>
            <div class="score-circle" style="border-color: <?= $score > 70? '#10b981' : '#ef4444'?>;">
                <?= $score?>
            </div>
        </div>

        <div class="grid">
            <div class="stat-box">
                <div class="label">Customer Name</div>
                <div class="value"><?= htmlspecialchars($customer['name'])?></div>
                <div style="font-size:11px; color:#64748b;">ID: <?= htmlspecialchars($customer['unique_id'])?></div>
            </div>
            <div class="stat-box">
                <div class="label">Report Generated</div>
                <div class="value"><?= date('d M Y, h:i A')?></div>
            </div>
        </div>

        <h3 style="font-size:12px; text-transform:uppercase; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px;">Financial Summary (Across Network)</h3>
        <div class="grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stat-box">
                <div class="label">Total Borrowed</div>
                <div class="value">₹<?= number_format($total_borrowed, 2)?></div>
            </div>
            <div class="stat-box">
                <div class="label">Total Repaid</div>
                <div class="value" style="color:#059669;">₹<?= number_format($total_paid, 2)?></div>
            </div>
            <div class="stat-box">
                <div class="label">Active Dues</div>
                <div class="value" style="color:#dc2626;">₹<?= number_format($total_borrowed - $total_paid, 2)?></div>
            </div>
        </div>

        <table style="width:100%; border-collapse:collapse; margin-top:20px;">
            <thead>
                <tr style="text-align:left; font-size:11px; color:#64748b; border-bottom:2px solid #0f172a;">
                    <th style="padding:10px 0;">Category</th>
                    <th>Total Accounts</th>
                    <th style="text-align:right;">Repayment Rate</th>
                </tr>
            </thead>
            <tbody style="font-size:13px;">
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:15px 0;">Ledger Udhar</td>
                    <td><?= $u_data['count']?> Entries</td>
                    <td style="text-align:right; font-weight:700;"><?= $u_data['total'] > 0? round(($u_data['paid'] / $u_data['total']) * 100) : 100?>%</td>
                </tr>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:15px 0;">Legal Bonds</td>
                    <td><?= $b_data['count']?> Bonds</td>
                    <td style="text-align:right; font-weight:700;"><?= $b_data['total'] > 0? round(($b_data['paid'] / $b_data['total']) * 100) : 100?>%</td>
                </tr>
                <tr>
                    <td style="padding:15px 0;">Monthly Subscription</td>
                    <td><?= $m_data['count']?> Cycles</td>
                    <td style="text-align:right; font-weight:700;"><?= $m_data['total'] > 0? round(($m_data['paid'] / $m_data['total']) * 100) : 100?>%</td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:40px; padding:20px; background:#eff6ff; border-radius:10px; font-size:12px;">
            <strong>System Advisory:</strong> <?= $score > 75? 'Trusted Borrower. Low risk of default.' : 'Moderate to High risk detected. Exercise caution with large credit limits.'?>
        </div>

        <div style="margin-top:60px; text-align:center; font-size:10px; color:#94a3b8;">
            This report is for internal business verification only. Generated by KhataLink AI.
        </div>
    </div>
</body>
</html>