<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];

// Fetch customer details for Razorpay prefill
$stmt_c = $pdo->prepare("SELECT email, phone, name FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$cust_meta = $stmt_c->fetch();

// Fetch active and closed monthly cycles
$stmt = $pdo->prepare("
    SELECT mk.*, s.shop_name, s.shop_category,
    DATEDIFF(CURDATE(), mk.start_date) as days_passed
    FROM monthly_khata mk
    JOIN shop_owners s ON mk.shop_id = s.id
    WHERE mk.customer_id = ?
    ORDER BY mk.status ASC, mk.start_date DESC
");
$stmt->execute([$customer_id]);
$cycles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Dues — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-slate-50 font-[Inter]">
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
    </div>
    <span class="text-[10px] font-black text-blue-700 uppercase tracking-widest bg-blue-50 px-3 py-1 rounded-full">Monthly Subscription Credit</span>
</nav>

<div class="flex">
    <?php include '../includes/customer_sidebar.php'; ?>
    <main class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="mb-10">
            <h1 class="text-3xl font-black text-slate-900">Monthly Dues</h1>
            <p class="text-slate-500 text-sm">Cycles with your trusted local merchants.</p>
            <a href="monthly_khata_history.php" class="text-blue-600 text-xs font-bold uppercase tracking-widest mt-2 inline-block hover:underline"><i class="fas fa-history me-1"></i> View Full History</a>
        </div>


        <?php if(isset($_GET['success'])): ?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-bold mb-8 flex items-center gap-3 animate-pulse">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['success']) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach($cycles as $cycle):
                $is_overdue = ($cycle['days_passed'] >= 30 && $cycle['status'] == 'open');
                $is_paid = ($cycle['status'] == 'closed');
                $base_amount = (float)$cycle['total_amount']; // This is the amount shop expects
                $platform_fee_percent = MONTHLY_PLATFORM_COMMISSION_PERCENT; // Total fee customer pays
                $platform_fee_amount = $base_amount * ($platform_fee_percent / 100);
                $total_payable = $base_amount + $platform_fee_amount; // Total customer pays
            ?>
            <div class="bg-white border-2 <?= $is_overdue ? 'border-red-200 shadow-red-50' : ($is_paid ? 'border-emerald-100' : 'border-slate-100') ?> rounded-[2.5rem] p-6 shadow-xl transition-all">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-lg font-black text-slate-900"><?= htmlspecialchars($cycle['shop_name']) ?></h3>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($cycle['shop_category']) ?></p>
                    </div>
                    <?php if($is_paid): ?>
                        <span class="bg-emerald-50 text-emerald-600 text-[9px] font-black px-3 py-1 rounded-full uppercase">Settled</span>
                    <?php elseif($is_overdue): ?>
                        <span class="bg-red-50 text-red-600 text-[9px] font-black px-3 py-1 rounded-full uppercase animate-pulse">Bill Generated</span>
                    <?php else: ?>
                        <span class="bg-blue-50 text-blue-600 text-[9px] font-black px-3 py-1 rounded-full uppercase">In Progress</span>
                    <?php endif; ?>
                </div>

                <div class="bg-slate-50 rounded-2xl p-5 mb-6 grid grid-cols-1 gap-2">
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">Cycle Started</label>
                        <div class="text-xs font-black text-slate-900"><?= date('d M Y', strtotime($cycle['start_date'])) ?></div>
                    </div>
                    <div class="pt-2 border-t border-slate-200">
                        <div class="flex justify-between items-center text-[10px] font-bold text-slate-500">
                            <span>Shop Bill:</span>
                            <span>₹<?= number_format($base_amount, 2) ?></span> 
                        </div>
                        <div class="flex justify-between items-center text-[10px] font-bold text-slate-500">
                            <span>Platform Fee (<?= $platform_fee_percent ?>%):</span>
                            <span>₹<?= number_format($platform_fee_amount, 2) ?></span> 
                        </div>
                        <div class="flex justify-between items-center mt-2 pt-2 border-t border-dashed border-slate-300">
                            <span class="text-[10px] font-black text-slate-900 uppercase">Total to Pay:</span>
                            <span class="text-lg font-black text-blue-600">₹<?= number_format($total_payable, 2) ?></span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <?php if(!$is_paid && $base_amount > 0): ?>
                        <button onclick="payMonthly(this, <?= $cycle['id'] ?>, <?= $total_payable ?>)" class="w-full bg-slate-900 text-white font-black py-4 rounded-xl text-[10px] uppercase tracking-widest shadow-lg hover:bg-blue-600 transition-all">
                            <i class="fas fa-credit-card me-1"></i> Pay Monthly Bill
                        </button>
                    <?php endif; ?>
                    <a href="generate_monthly_statement.php?id=<?= $cycle['id'] ?>" target="_blank" class="w-full bg-white border-2 border-slate-100 text-slate-600 font-black py-4 rounded-xl text-[10px] uppercase tracking-widest text-center hover:border-blue-500 hover:text-blue-600 transition-all">
                        <i class="fas fa-file-pdf me-1"></i> <?= $is_paid ? 'Download Receipt' : 'View Current List' ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if(empty($cycles)): ?>
                <div class="md:col-span-2 text-center py-20 bg-white border border-dashed border-slate-200 rounded-[3rem]">
                    <i class="fas fa-calendar-times text-4xl text-slate-200 mb-4"></i>
                    <p class="text-slate-400 font-black text-xs uppercase tracking-widest">No monthly cycles active yet</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
// Initialize Cashfree Object globally
const cashfree = Cashfree({ mode: "<?= (CF_MODE === 'PROD') ? 'production' : 'sandbox' ?>" });

// Prefill data handled safely via JSON encode
const PREFILL = {
    name: <?= json_encode($cust_meta['name'] ?? 'Customer') ?>,
    email: <?= json_encode($cust_meta['email'] ?? '') ?>,
    contact: <?= json_encode(substr(preg_replace('/[^0-9]/', '', $cust_meta['phone'] ?? ''), -10)) ?>
};

function notifyPaymentEvent(shopId, amount, event) {
    fetch('ajax_payment_event_notify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ shop_id: shopId, amount: amount, event: event })
    });
}

function payMonthly(btn, monthlyId, totalPayable, platformPay = false) {
    if (!btn.hasAttribute('data-original')) {
        btn.setAttribute('data-original', btn.innerHTML);
    }
    const originalHtml = btn.getAttribute('data-original');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    const bodyParams = new URLSearchParams();
    bodyParams.append('monthly_id', monthlyId);
    bodyParams.append('amount', totalPayable);
    if(platformPay) bodyParams.append('platform_pay', '1');

    fetch('cashfree_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: bodyParams.toString()
    })
    .then(async res => {
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) { throw new Error("Server JSON Error"); }
        
        if (!data.success && data.needs_platform_pay) {
            Swal.fire({
                title: 'Dukandaar Offline Hai',
                html: `<b>${data.shop_name}</b> ka account link nahi hai. Kya aap KhataLink ko pay karna chahte hain?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Pay KhataLink',
                confirmButtonColor: '#2563eb',
            }).then((result) => {
                if (result.isConfirmed) { 
                    payMonthly(btn, monthlyId, totalPayable, true); 
                } else { 
                    notifyPaymentEvent(shopId || (data ? data.shop_id : 0), totalPayable, 'cancel');
                    btn.disabled = false; 
                    btn.innerHTML = originalHtml; 
                }
            });
            return;
        }
        
        if (!data.success) {
            Swal.fire('Error', data.message || 'Payment failed', 'error');
            btn.disabled = false; btn.innerHTML = originalHtml; return;
        }

        // Notify intent
        notifyPaymentEvent(data.shop_id, totalPayable, 'intent');

        // Open Cashfree Checkout
        let checkoutOptions = {
            paymentSessionId: data.payment_session_id,
            redirectTarget: "_self"
        };
        cashfree.checkout(checkoutOptions);
    })
    .catch(err => { 
        Swal.fire('Oops!', err.message, 'error');
        btn.disabled = false; 
        btn.innerHTML = originalHtml; 
    });
}

function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
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
        const title = payload.notification?.title || 'Monthly Bill Update';
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