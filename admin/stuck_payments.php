<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Handle Settlement Toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['settle_id'])) {
    $id = (int)$_POST['settle_id'];
    $type = $_POST['settle_type'];
    $status = (int)$_POST['status'];

    try {
        if ($type == 'Bond') {
            $pdo->prepare("UPDATE bond_payments SET is_settled_manually = ? WHERE id = ?")->execute([$status, $id]);
        } elseif ($type == 'Monthly') {
            $pdo->prepare("UPDATE monthly_khata SET is_settled_manually = ? WHERE id = ?")->execute([$status, $id]);
        } elseif ($type == 'Ledger') {
            $pdo->prepare("UPDATE payment_requests SET is_settled_manually = ? WHERE id = ?")->execute([$status, $id]);
        }
        header("Location: stuck_payments.php?success=Settlement status updated.");
        exit();
    } catch (Exception $e) {
        header("Location: stuck_payments.php?error=Failed to update status.");
        exit();
    }
}

// Fetch Stuck/Razorpay Payments across all modules
$query = "
    (SELECT 'Bond' as type, bp.id, bp.amount_paid as amount, bp.payment_date as date, bp.razorpay_payment_id, bp.razorpay_order_id, s.shop_name, s.rzp_account_id, bp.is_settled_manually, c.name as customer_name, s.bank_acc_no, s.bank_ifsc
     FROM bond_payments bp 
     JOIN bonds b ON bp.bond_id = b.id 
     JOIN shop_owners s ON b.shop_id = s.id 
     JOIN customers c ON b.customer_id = c.id
     WHERE bp.razorpay_payment_id IS NOT NULL)
    UNION ALL
    (SELECT 'Monthly' as type, mk.id, mk.paid_amount as amount, mk.created_at as date, mk.razorpay_payment_id, mk.razorpay_order_id, s.shop_name, s.rzp_account_id, mk.is_settled_manually, c.name as customer_name, s.bank_acc_no, s.bank_ifsc
     FROM monthly_khata mk 
     JOIN shop_owners s ON mk.shop_id = s.id 
     JOIN customers c ON mk.customer_id = c.id
     WHERE mk.razorpay_payment_id IS NOT NULL AND mk.status = 'closed')
    UNION ALL
    (SELECT 'Ledger' as type, pr.id, pr.amount as amount, pr.created_at as date, pr.razorpay_payment_id, pr.razorpay_order_id, s.shop_name, s.rzp_account_id, pr.is_settled_manually, c.name as customer_name, s.bank_acc_no, s.bank_ifsc
     FROM payment_requests pr 
     JOIN shop_owners s ON pr.shop_id = s.id 
     JOIN customers c ON pr.customer_id = c.id
     WHERE pr.razorpay_payment_id IS NOT NULL AND pr.status = 'approved')
    ORDER BY date DESC
";

$stmt = $pdo->query($query);
$payments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stuck Payments Monitoring — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .kl-navbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .sidebar { width: 250px; background: #fff; border-right: 1px solid #e2e8f0; padding: 24px 16px; position: sticky; top: 64px; height: calc(100vh - 64px); }
        .sidebar-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: 1.5px; text-transform: uppercase; padding: 0 12px; margin-top: 20px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 10px; color: #64748b; font-size: 14px; text-decoration: none; }
        .nav-link:hover, .nav-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }
        .main { flex: 1; padding: 32px; }
        .kl-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; }
        .table th { font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; padding: 15px 20px; }
        .table td { padding: 15px 20px; vertical-align: middle; font-size: 14px; }
        .badge-module { font-size: 10px; font-weight: 800; padding: 4px 8px; border-radius: 5px; }
        .module-Bond { background: #fef3c7; color: #92400e; }
        .module-Monthly { background: #dcfce7; color: #065f46; }
        .module-Ledger { background: #e0e7ff; color: #3730a3; }
        .acc-missing { color: #dc2626; font-weight: 800; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<nav class="kl-navbar">
    <a href="../index.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" style="height: 45px;"></a>
    <span class="fw-bold text-dark">Admin Control Panel</span>
</nav>

<div class="layout">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="main">
        <div class="mb-4">
            <h1 class="fw-black h3 mb-1">Stuck Payments Monitoring</h1>
            <p class="text-muted small">Verify payments that might have failed to transfer automatically to shop linked accounts.</p>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>

        <div class="kl-card">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Shop / Customer</th>
                            <th>Amount</th>
                            <th>Settlement Bank Details</th>
                            <th>Verification</th>
                            <th>Settlement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $p): 
                            $is_target_missing = (empty($p['rzp_account_id']) || strlen($p['rzp_account_id']) < 10);
                        ?>
                        <tr>
                            <td><span class="badge-module module-<?= $p['type'] ?>"><?= $p['type'] ?></span></td>
                            <td>
                                <div class="fw-bold"><?= htmlspecialchars($p['shop_name']) ?></div>
                                <div class="text-muted small">Paid by: <?= htmlspecialchars($p['customer_name']) ?></div>
                            </td>
                            <td>
                                <div class="fw-black text-dark">₹<?= number_format($p['amount'], 2) ?></div>
                                <div class="text-muted" style="font-size: 10px;"><?= date('d M Y, h:i A', strtotime($p['date'])) ?></div>
                            </td>
                            <td>
                                <?php if($is_target_missing): ?>
                                    <span class="acc-missing"><i class="fas fa-hand-holding-usd"></i> PLATFORM PAY</span>
                                    <div class="mt-2 p-2 bg-slate-50 rounded border border-dashed border-slate-200">
                                        <div class="fw-bold" style="font-size: 11px;">Acc: <?= $p['bank_acc_no'] ?: 'N/A' ?></div>
                                        <div class="text-muted" style="font-size: 10px;">IFSC: <?= $p['bank_ifsc'] ?: 'N/A' ?></div>
                                    </div>
                                <?php else: ?>
                                    <code class="text-primary small"><?= $p['rzp_account_id'] ?></code>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-muted" style="font-size: 10px;">Order: <span class="text-dark fw-bold"><?= $p['razorpay_order_id'] ?></span></div>
                                <div class="text-muted" style="font-size: 10px;">Pay ID: <span class="text-success fw-bold"><?= $p['razorpay_payment_id'] ?></span></div>
                            </td>
                            <td>
                                <?php if($p['is_settled_manually']): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="settle_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="settle_type" value="<?= $p['type'] ?>">
                                        <input type="hidden" name="status" value="0">
                                        <button type="submit" class="btn btn-sm btn-success fw-bold text-uppercase" style="font-size: 9px;">
                                            <i class="fas fa-check-double"></i> Settled
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="settle_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="settle_type" value="<?= $p['type'] ?>">
                                        <input type="hidden" name="status" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger fw-bold text-uppercase" style="font-size: 9px;">
                                            Mark Settled
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($payments)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted italic">No Razorpay transactions found yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Help box -->
        <div class="mt-5 p-4 bg-white border border-slate-200 rounded-2xl">
            <h5 class="fw-black h6 text-primary mb-3">Admin Settlement Guide</h5>
            <ol class="small text-muted mb-0">
                <li class="mb-2">Agar koi shopkeeper onboarding se pehle payment leta hai, toh transfer admin account mein hi reh jata hai.</li>
                <li class="mb-2">Aise cases mein, admin ko apna Razorpay dashboard check karke payment confirm karni chahiye.</li>
                <li>Manual payment karne ke baad "Mark Settled" par click karein taaki records maintain rahein.</li>
            </ol>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>