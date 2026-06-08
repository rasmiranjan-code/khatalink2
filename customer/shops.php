<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/cashfree_config.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id_api = $parts[0] ?? 0;

    $query = "SELECT s.id as shop_id, s.shop_name, s.shop_category, s.upi_id, s.name as owner_name,
              (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as current_due,
              (SELECT COUNT(*) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as open_entries
              FROM shop_owners s
              JOIN shop_customers sc ON s.id = sc.shop_id
              WHERE sc.customer_id = ? ORDER BY current_due DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$customer_id_api, $customer_id_api, $customer_id_api]);
    $shops_list = $stmt->fetchAll();

    echo json_encode(['success' => true, 'shops' => $shops_list]);
    exit();
}
// ===== END FLUTTER API =====

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];

// Fetch customer details for Razorpay prefill
$stmt_c = $pdo->prepare("SELECT email, phone, name FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$cust_meta = $stmt_c->fetch();

// Fetch shops and the dues for this customer
$query = "
    SELECT 
        s.id as shop_id, 
        s.shop_name, 
        s.shop_category, 
        s.upi_id,
        s.name as owner_name,
        (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as current_due,
        (SELECT COUNT(*) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') as open_entries
    FROM 
        shop_owners s
    JOIN 
        shop_customers sc ON s.id = sc.shop_id
    WHERE 
        sc.customer_id = ?
    ORDER BY 
        current_due DESC, s.shop_name ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$customer_id, $customer_id, $customer_id]);
$shops = $stmt->fetchAll();

// Total Due calculation for the stat card
$total_due_all = array_sum(array_column($shops, 'current_due'));
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>My Shops — KhataLink</title>
        <link rel="icon" type="image/png" href="../assets/favicon.png">
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <!-- SweetAlert2 for Beautiful Popups -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            body {
                font-family: 'Inter', sans-serif;
            }
            
            .glass-banner {
                background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
                position: relative;
                overflow: hidden;
            }
            
            .glass-banner::before {
                content: '';
                position: absolute;
                top: -50%;
                left: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(circle, rgba(255, 255, 255, 0.03) 0%, transparent 70%);
                pointer-events: none;
            }
        </style>
    </head>

    <body class="bg-slate-50 text-slate-900">
        <!-- Navbar -->
        <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="flex items-center">
                    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
                </a>
                <!-- Cashfree SDK v3 -->
                <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden sm:flex items-center gap-2 bg-red-50 border border-red-100 text-red-700 text-[11px] font-black px-4 py-2 rounded-full uppercase tracking-wider shadow-sm">
                    <i class="fas fa-wallet"></i> Total Due: ₹
                    <?= number_format($total_due_all, 2) ?>
                </div>
            </div>
        </nav>
        <!-- Main Layout (Full Width) -->
        <div class="min-h-[calc(100vh-64px)]">
            <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
                <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-xl">
                    <h1 class="text-2xl md:text-3xl font-black tracking-tight mb-1">My Registered Shops</h1>
                    <p class="text-slate-400 text-sm">View your balance across every shop you deal with.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all group">
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Linked Shops</div>
                        <div class="text-2xl font-black text-slate-900">
                            <?= count($shops) ?>
                        </div>
                    </div>
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 hover:shadow-lg transition-all group">
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Outstanding</div>
                        <div class="text-2xl font-black text-red-600">₹
                            <?= number_format($total_due_all, 0) ?>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if($shops): foreach($shops as $shop): ?>
                    <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 hover:border-blue-500 hover:shadow-xl transition-all flex flex-col group">
                        <div class="flex justify-between items-start mb-6">
                            <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-xl group-hover:bg-blue-600 group-hover:text-white transition-all duration-500">
                                <i class="fas fa-store"></i>
                            </div>
                            <span class="text-[10px] font-black px-3 py-1 rounded-full uppercase tracking-widest <?= $shop['current_due'] == 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' ?>">
                            <?= $shop['open_entries'] ?> Entries
                        </span>
                        </div>
                        <h3 class="text-lg font-black text-slate-900 group-hover:text-blue-600 transition-colors">
                            <?= htmlspecialchars($shop['shop_name']) ?>
                        </h3>
                        <span class="text-[10px] font-black text-blue-500 uppercase tracking-widest"><?= htmlspecialchars($shop['shop_category']) ?></span>
                        <div class="mt-6 pt-6 border-t border-slate-50">
                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Current Balance Due</div>
                            <div class="text-2xl font-black tracking-tight <?= $shop['current_due'] == 0 ? 'text-emerald-500' : 'text-red-600' ?>">₹
                                <?= number_format($shop['current_due'], 2) ?>
                            </div>
                        </div>
                        <div class="mt-6 flex flex-col gap-2">
                            <?php if($shop['current_due'] > 0): ?>
                                <button onclick="startCashfreePayment(this, null, <?= $shop['shop_id'] ?>, null, false)" class="bg-blue-600 text-white text-[10px] font-black py-3 rounded-xl uppercase tracking-widest text-center hover:bg-blue-700 transition-all shadow-lg shadow-blue-100">
                                    <i class="fas fa-credit-card me-1"></i> Pay Balance Online
                                </button>
                            <?php endif; ?>
                            <a href="generate_shop_statement.php?shop_id=<?= $shop['shop_id'] ?>" target="_blank" class="bg-indigo-50 text-indigo-600 text-[10px] font-black py-3 rounded-xl uppercase tracking-widest text-center border border-indigo-100 hover:bg-white transition-all">
                                <i class="fas fa-file-pdf me-1"></i> Download Statement
                            </a>
                            <a href="ledger.php?shop=<?= urlencode($shop['shop_name']) ?>" class="bg-slate-50 text-slate-600 text-[10px] font-black py-3 rounded-xl uppercase tracking-widest text-center border border-slate-200 hover:bg-white transition-all">
                                <i class="fas fa-history me-1"></i> View History
                            </a>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="col-span-full text-center py-20 bg-white border border-slate-200 rounded-[2.5rem]">
                        <i class="fas fa-store-slash text-4xl text-slate-200 mb-4"></i>
                        <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs">No shops linked yet</h3>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <footer class="bg-white border-t border-slate-200 py-6 text-center">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">©
                <?= date('Y') ?> KhataLink — Premium Digital Ledger</div>
        </footer>

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

function startCashfreePayment(btn, bondId = null, shopId = null, customAmount = null, monthlyId = null, platformPay = false) {
    if (!btn.hasAttribute('data-original')) {
        btn.setAttribute('data-original', btn.innerHTML);
    }
    const originalHtml = btn.getAttribute('data-original');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Initializing...';

    const bodyParams = new URLSearchParams();
    if(bondId) bodyParams.append('bond_id', bondId);
    if(shopId) bodyParams.append('shop_id', shopId);
    if(monthlyId) bodyParams.append('monthly_id', monthlyId);
    if(customAmount) bodyParams.append('amount', customAmount);
    if(platformPay === true) bodyParams.append('platform_pay', '1');

    fetch('cashfree_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: bodyParams.toString()
    })
    .then(async res => {
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error("Invalid Server Response (Non-JSON)");
        }
        
        if (!data.success && data.needs_platform_pay) {
            Swal.fire({
                title: '<span class="text-blue-600">Dukandaar Offline Hai</span>',
                html: `<div class="text-sm font-medium text-slate-600"><b>${data.shop_name}</b> ne online payment setup nahi kiya hai.<br><br>Kya aap <b>KhataLink Platform</b> ko pay karna chahte hain?<br><small class="text-slate-400">Hum aapka paisa dukandaar ko manually transfer kar denge.</small></div>`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-shield-check"></i> Pay to KhataLink',
                cancelButtonText: 'Nahi, Cancel',
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                customClass: {
                    popup: 'rounded-[2.5rem] p-8 shadow-2xl',
                    confirmButton: 'rounded-xl font-black uppercase tracking-widest text-[10px] px-6 py-3',
                    cancelButton: 'rounded-xl font-black uppercase tracking-widest text-[10px] px-6 py-3'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    startCashfreePayment(btn, bondId, shopId, customAmount, monthlyId, true);
                } else {
                    notifyPaymentEvent(shopId, customAmount, 'cancel');
                    btn.disabled = false; btn.innerHTML = originalHtml;
                }
            });
            return;
        }

        if (!data.success) throw new Error(data.message);

        // Open Cashfree Checkout
        let checkoutOptions = {
            paymentSessionId: data.payment_session_id,
            redirectTarget: "_self" // Verify page par return karega jo order payload mein hai
        };
        cashfree.checkout(checkoutOptions);
    })
    .catch(err => { 
        Swal.fire({
            title: 'Request Failed',
            text: 'Connection error or invalid response: ' + err.message,
            icon: 'error',
            confirmButtonColor: '#2563eb'
        });
        btn.disabled = false; 
        btn.innerHTML = originalHtml; 
    });
}

</script>
    </body>

    </html>