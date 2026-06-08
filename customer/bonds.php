<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php'; 

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];
$error = ''; $success = $_GET['success'] ?? '';

// Fetch Aggregated Bond Stats for Customer
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT shop_id) as total_shops,
        SUM(amount) as total_bond_val,
        SUM(paid_amount) as total_paid_val,
        SUM(amount - paid_amount + fine_amount) as total_rem_val
    FROM bonds 
    WHERE customer_id = ?
");
$stats_stmt->execute([$customer_id]);
$bond_stats = $stats_stmt->fetch();

// Fetch customer details for Razorpay prefill
$stmt_c = $pdo->prepare("SELECT email, phone FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$cust_meta = $stmt_c->fetch();

// Fetch Bonds linked to this customer
$stmt = $pdo->prepare("
    SELECT b.*, s.shop_name, s.upi_id,
    (SELECT COUNT(*) FROM bond_warnings WHERE bond_id = b.id) as warning_count
    FROM bonds b
    JOIN shop_owners s ON b.shop_id = s.id
    WHERE b.customer_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$customer_id]);
$bonds = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Active Bonds — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Cashfree SDK v3 -->
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <!-- SweetAlert2 for Premium Popups -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        /* Responsive visibility */
        .desktop-table { display: none; }
        @media (min-width: 1024px) {
            .desktop-table { display: block; }
            .mobile-cards { display: none; }
        }

        /* Progress bar */
        .progress-track {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
            border-radius: 99px;
            transition: width 0.5s ease;
        }

        /* Bond card */
        .bond-card {
            background: white;
            border-radius: 20px;
            border: 1.5px solid #f1f5f9;
            overflow: hidden;
            box-shadow: 0 1px 8px rgba(0,0,0,0.04);
        }

        /* Action buttons */
        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
        }
        .btn-pay { background: #2563eb; color: white; flex: 1.5; }
        .btn-pdf { background: #f8fafc; color: #64748b; border: 1.5px solid #e2e8f0; flex: 1; }

        /* Status badges */
        .badge { font-size: 9px; font-weight: 800; padding: 3px 10px; border-radius: 999px; text-transform: uppercase; }
        .badge-active  { background: #eff6ff; color: #2563eb; }
        .badge-closed  { background: #f0fdf4; color: #16a34a; }
        .badge-overdue { background: #fef2f2; color: #dc2626; }

        .warn-dot { width: 8px; height: 8px; border-radius: 50%; }
        .warn-dot.active { background: #ef4444; }
        .warn-dot.inactive { background: #e2e8f0; }
    </style>
</head>
<body class="bg-slate-50">

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-14 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-7"></a>
    </div>
    <span class="text-[10px] font-black text-blue-700 uppercase tracking-widest">My Legal Commitments</span>
</nav>

<div class="min-h-[calc(100vh-64px)]">
    <main class="flex-1 px-4 py-6 md:px-8 md:py-8 max-w-screen-xl mx-auto w-full">
        
        <div class="mb-6">
            <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Legal Bonds</h1>
            <p class="text-slate-500 text-sm mt-0.5">Review your credit agreements and repayment progress.</p>
        </div>

        <!-- Bond Summary Dashboard -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-8">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Active Shops</div>
                <div class="text-xl font-black text-slate-900"><?= number_format($bond_stats['total_shops'] ?? 0) ?></div>
                <div class="w-full h-1 bg-blue-100 rounded-full mt-2 overflow-hidden"><div class="h-full bg-blue-600 w-1/3"></div></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Loan Value</div>
                <div class="text-xl font-black text-slate-900">₹<?= number_format($bond_stats['total_bond_val'] ?? 0, 0) ?></div>
                <div class="text-[8px] font-bold text-slate-400 mt-1 uppercase">Principal amount</div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-1">Total Cleared</div>
                <div class="text-xl font-black text-emerald-600">₹<?= number_format($bond_stats['total_paid_val'] ?? 0, 0) ?></div>
                <?php 
                    $perc = ($bond_stats['total_bond_val'] > 0) ? ($bond_stats['total_paid_val'] / $bond_stats['total_bond_val']) * 100 : 0;
                ?>
                <div class="text-[8px] font-bold text-emerald-500 mt-1 uppercase"><?= number_format($perc, 1) ?>% Repaid</div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm border-b-4 border-b-slate-900">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Balance</div>
                <div class="text-xl font-black text-slate-900">₹<?= number_format($bond_stats['total_rem_val'] ?? 0, 0) ?></div>
                <div class="text-[8px] font-bold text-slate-400 mt-1 uppercase">Outstanding</div>
            </div>
        </div>

        <?php if($success): ?>
        <div class="mb-6 bg-emerald-50 text-emerald-600 p-4 rounded-2xl border border-emerald-100 font-bold text-xs flex items-center gap-2">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <!-- =================== MOBILE CARDS =================== -->
        <div class="mobile-cards flex flex-col gap-4">
            <?php if(empty($bonds)): ?>
            <div class="p-12 text-center text-slate-400 italic text-sm bg-white rounded-3xl border border-slate-100">No active bonds found.</div>
            <?php endif; ?>

            <?php foreach($bonds as $b): 
                $initial_val = (float)($b['initial_paid'] ?? 0);
                $total_bond  = (float)$b['amount'];
                $paid_so_far = (float)$b['paid_amount'];
                $remaining_at_start = max(0, $total_bond - $initial_val);
                $kist_val = ($b['installment_count'] > 0 && $remaining_at_start > 0) ? ($remaining_at_start / $b['installment_count']) : 0;
                $progress = ($total_bond > 0) ? min(($paid_so_far / $total_bond) * 100, 100) : 0;
                $extra_paid = max(0, $paid_so_far - $initial_val);
                $kists_done = ($kist_val > 0) ? min((int)floor(($extra_paid + ($kist_val * 0.1)) / $kist_val), (int)$b['installment_count']) : 0;
                $rem_bal = max(0, $total_bond - $paid_so_far + $b['fine_amount']);
                
                // ── FIX 2: Use BOND_PLATFORM_COMMISSION_PERCENT constant, not hardcoded 1.01 ──
                $kist_total_with_fee = $kist_val * (1 + (BOND_PLATFORM_COMMISSION_PERCENT / 100)); // Customer pays this

                $status_class = ($b['status']==='closed' ? 'badge-closed' : ($b['status']==='overdue' ? 'badge-overdue' : 'badge-active'));

                // ── Restrict "Pay Kist" visibility based on installment cycle ──
                $show_pay_button = ($b['status'] != 'closed');
                // Restriction removed for testing: Show button always if status is not closed
            ?>
            <div class="bond-card">
                <div class="px-4 pt-4 pb-3 flex items-start justify-between border-b border-slate-50">
                    <div class="min-w-0 pr-2">
                        <div class="text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($b['shop_name']) ?></div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase mt-0.5">Bond #<?= str_pad($b['id'], 5, '0', STR_PAD_LEFT) ?></div>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        <span class="badge <?= $status_class ?>"><?= htmlspecialchars($b['status']) ?></span>
                        <div class="text-[10px] font-bold text-slate-400"><?= date('d M Y', strtotime($b['due_date'])) ?></div>
                    </div>
                </div>

                <div class="p-4 bg-slate-50/50">
                    <div class="flex justify-between items-center mb-1">
                        <div>
                            <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Bond Value</div>
                            <div class="text-lg font-black text-slate-900 leading-tight">₹<?= number_format($total_bond, 2) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[9px] font-black text-emerald-500 uppercase tracking-widest">You Paid</div>
                            <div class="text-base font-black text-emerald-600 leading-tight">₹<?= number_format($paid_so_far, 2) ?></div>
                        </div>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width:<?= $progress ?>%"></div>
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <span class="text-[10px] font-bold text-blue-600"><?= $kists_done ?>/<?= $b['installment_count'] ?> Cleared</span>
                        <span class="text-[10px] font-bold text-blue-500"><?= max(0, (int)$b['installment_count'] - $kists_done) ?> Pending Kist</span>
                    </div>
                    <div class="mt-2 pt-2 border-t border-slate-200 flex flex-col gap-1">
                        <div class="flex justify-between text-[9px] font-bold text-slate-500">
                            <span>Base Installment:</span>
                            <span>₹<?= number_format($kist_val, 2) ?></span> 
                        </div>
                        <div class="flex justify-between text-[9px] font-bold text-slate-500">
                            <span>Platform Fee (<?= BOND_PLATFORM_COMMISSION_PERCENT ?>%):</span>
                            <span>₹<?= number_format($kist_val * (BOND_PLATFORM_COMMISSION_PERCENT / 100), 2) ?></span>
                        </div>
                        <div class="flex justify-between text-[10px] font-black text-blue-600 uppercase">
                            <span>Total to Pay:</span>
                            <span>₹<?= number_format($kist_total_with_fee, 2) ?></span>
                        </div>
                    </div>
                </div>

                <div class="p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest mr-1">Notices</span>
                        <?php for($i=1;$i<=3;$i++): ?>
                        <div class="warn-dot <?= ($i<=$b['warning_count'])?'active':'inactive' ?>"></div>
                        <?php endfor; ?>
                    </div>

                    <div class="flex gap-2">
                        <?php if($show_pay_button): ?>
                            <button onclick="startCashfreePayment(this, <?= $b['id'] ?>, null, null, false)" class="action-btn btn-pay">
                                <i class="fas fa-credit-card"></i> Pay Kist
                            </button>
                        <?php endif; ?>
                        <a href="../shop/export_bond.php?id=<?= $b['id'] ?>" target="_blank" class="action-btn btn-pdf">
                            <i class="fas fa-file-pdf"></i> Receipt
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </main>
</div>

<script>
// Initialize Cashfree Production
const cashfree = Cashfree({ mode: "<?= (CF_MODE === 'PROD') ? 'production' : 'sandbox' ?>" });

function notifyPaymentEvent(shopId, amount, event) {
    fetch('ajax_payment_event_notify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ shop_id: shopId, amount: amount, event: event })
    });
}

async function startCashfreePayment(btn, bondId = null, shopId = null, monthlyId = null, platformPay = false) {
    if (!btn.hasAttribute('data-original')) {
        btn.setAttribute('data-original', btn.innerHTML);
    }
    const originalHtml = btn.getAttribute('data-original');

    function resetBtn() {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';

    const bodyParams = new URLSearchParams();
    if(bondId) bodyParams.append('bond_id', bondId);
    if(shopId) bodyParams.append('shop_id', shopId);
    if(monthlyId) bodyParams.append('monthly_id', monthlyId);
    if(platformPay === true) bodyParams.append('platform_pay', '1');

    // 1. Create Order via Backend
    fetch('cashfree_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: bodyParams.toString()
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error("Invalid Server Response");
        }
    })
    .then(data => {
        if (!data.success && data.needs_platform_pay === true) {
            // Reset button FIRST so user can see it's back to normal during popup
            resetBtn();
            Swal.fire({
                title: '<span class="text-blue-600">Dukandaar Offline Hai</span>',
                html: `<div class="text-sm font-medium text-slate-600"><b>${data.shop_name}</b> ne setup nahi kiya hai. Kya aap <b>KhataLink Platform</b> ko pay karna chahte hain?</div>`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-shield-check"></i> Pay to KhataLink',
                cancelButtonText: 'Nahi, Cancel',
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                customClass: {
                    popup: 'rounded-[2.5rem] p-8',
                    confirmButton: 'rounded-xl font-black uppercase text-[10px] px-6 py-3 mx-1',
                    cancelButton: 'rounded-xl font-black uppercase text-[10px] px-6 py-3 mx-1'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    startCashfreePayment(btn, bondId, shopId, monthlyId, true);
                } else {
                    notifyPaymentEvent(shopId || data.shop_id, data.amount, 'cancel');
                }
            });
            return;
        }
        if (!data.success) {
            Swal.fire({ title: "Payment Error", text: data.message || "Order creation failed.", icon: "error" });
            resetBtn();
            return;
        }

        // Notify Shop about the attempt
        notifyPaymentEvent(shopId || data.shop_id, data.amount, 'intent');

        // Open Cashfree Checkout
        let checkoutOptions = {
            paymentSessionId: data.payment_session_id,
            redirectTarget: "_self"
        };
        cashfree.checkout(checkoutOptions);
    })
    .catch(err => {
        console.error(err);
        // Only real network/JSON parse errors reach here
        Swal.fire({ title: 'Request Failed', text: 'Server se connection nahi ho paaya. Internet check karein.', icon: 'error', confirmButtonColor: '#2563eb' });
        resetBtn();
    });
}

</script>

<!-- Firebase Professional Notifications -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
    if (!firebase.apps.length) {
        firebase.initializeApp({
            apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
            authDomain: "khatalink-63041.firebaseapp.com",
            projectId: "khatalink-63041",
            messagingSenderId: "905429197043",
            appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
        });
    }
    const messaging = firebase.messaging();
    async function syncToken() {
        try {
            const registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            await messaging.getToken({ 
                vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                serviceWorkerRegistration: registration
            });
        } catch (e) { console.error("FCM Sync Error:", e); }
    }
    syncToken();
    messaging.onMessage((payload) => {
        const title = payload.notification?.title || 'Bond Update';
        const body = payload.notification?.body || '';
        const image = payload.notification?.image;
        if (Notification.permission === "granted") {
            const options = {
                body: body,
                icon: '../assets/favicon.png'
            };
            if (image) {
                options.image = image;
            }
            const n = new Notification(title, options);
            n.onclick = function() { window.focus(); this.close(); };
        }
    });
</script>
</body>
</html>