<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

// ===== AUTHENTICATION LAYER (App & Web) =====
$customer_id = 0;
$is_api = false;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    header('Content-Type: application/json');
    $token      = get_auth_token();
    $parts      = verify_secure_token($token);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = $_SESSION['customer_id'] ?? 0;
}

if (!$customer_id) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$order_id = (int)($_GET['id'] ?? 0);

if ($order_id <= 0) {
    header("Location: Groceries_orders.php");
    exit();
}

// 1. Fetch Order details with Shop info
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.shop_category, s.full_address as shop_address, s.average_rating, s.total_ratings_count,
           (SELECT COUNT(*) FROM shop_ratings sr WHERE sr.order_id = o.id) as has_rated
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE o.id = ? AND o.customer_id = ?
");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if (!$order) {
    die("Order not found or access denied.");
}

// 2. Fetch Order Items (Moved up to fix Undefined variable error)
$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll();

// For API requests, return JSON order details + items
if ($is_api) {
    exit(json_encode([
        'success' => true,
        'order'   => $order,
        'items'   => $items
    ]));
}
function getStatusColor(string $s): string {
    $colors = [
        'pending'   => 'bg-amber-50 text-amber-600 border-amber-100',
        'accepted'  => 'bg-blue-50 text-blue-600 border-blue-100',
        'packing'   => 'bg-indigo-50 text-indigo-600 border-indigo-100',
        'assigned'  => 'bg-sky-50 text-sky-600 border-sky-100',
        'picked_up' => 'bg-purple-50 text-purple-600 border-purple-100',
        'delivered' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
        'cancelled' => 'bg-red-50 text-red-600 border-red-100'
    ];
    return $colors[$s] ?? 'bg-slate-50 text-slate-600 border-slate-100';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details #<?= $order_id ?> — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900 pb-10">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Groceries_orders.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight">Order Details</h1>
</nav>

<main class="p-4 md:p-8 max-w-3xl mx-auto">

    <!-- Status Header -->
    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm mb-6 text-center">
        <div class="inline-block px-4 py-1.5 rounded-full border font-black text-[10px] uppercase tracking-widest mb-4 <?= getStatusColor($order['order_status']) ?>">
            <?= str_replace('_', ' ', $order['order_status']) ?>
        </div>
        <h2 class="text-2xl font-black text-slate-900 leading-none mb-2">Order #ORD-<?= $order_id ?></h2>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></p>
        
        <?php if($order['order_status'] === 'delivered' && !$order['has_rated']): ?>
            <button onclick="openRatingModal(<?= $order['shop_id'] ?>, <?= $order_id ?>)" class="mt-6 bg-amber-500 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-amber-100">Rate Shop</button>
        <?php endif; ?>

        <?php if($order['order_status'] !== 'delivered' && $order['order_status'] !== 'cancelled'): ?>
            <div class="mt-6">
                <a href="Groceries_order_tracking.php?order_id=<?= $order_id ?>" class="bg-slate-900 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-slate-200">Track Live Order</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Shop & Address Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-[2rem] p-6 border border-slate-100 shadow-sm">
            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Store Details</h3>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center text-lg"><i class="fas fa-store"></i></div>
                <div>
                    <?php if($order['average_rating'] > 0): ?>
                    <p class="text-[9px] font-bold text-amber-500 mb-1"><i class="fas fa-star me-1"></i> <?= number_format($order['average_rating'], 1) ?> (<?= $order['total_ratings_count'] ?> ratings)</p>
                    <?php endif; ?>
                    <h4 class="text-sm font-black text-slate-900"><?= htmlspecialchars($order['shop_name']) ?></h4>
                    <p class="text-[10px] font-bold text-slate-400 truncate"><?= htmlspecialchars($order['shop_address']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-[2rem] p-6 border border-slate-100 shadow-sm">
            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Delivery Address</h3>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-lg"><i class="fas fa-map-pin"></i></div>
                <div class="min-w-0">
                    <h4 class="text-sm font-black text-slate-900"><?= htmlspecialchars($order['delivery_name']) ?></h4>
                    <p class="text-[10px] font-bold text-slate-400 truncate"><?= htmlspecialchars($order['delivery_apartment_house'] . ', ' . $order['delivery_village']) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Items List -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 bg-slate-50/50 border-b border-slate-100">
            <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-widest">Order Summary</h3>
        </div>
        <div class="divide-y divide-slate-50">
            <?php foreach($items as $it): ?>
            <div class="p-5 flex justify-between items-center">
                <div>
                    <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($it['item_name']) ?></div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase"><?= (float)$it['quantity'] ?> <?= $it['unit'] ?> × ₹<?= number_format($it['price_per_unit'], 2) ?></div>
                </div>
                <div class="text-sm font-black text-slate-900">₹<?= number_format($it['total_price'], 2) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Final Bill Card -->
    <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl">
        <div class="space-y-3 mb-6">
            <div class="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-widest">
                <span>Items Subtotal</span>
                <span>₹<?= number_format($order['net_to_shop'], 2) ?></span>
            </div>
            <div class="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-widest">
                <span>Delivery Fee</span>
                <span class="text-blue-400">₹<?= number_format($order['delivery_fee'], 2) ?></span>
            </div>
            <?php 
                // Re-calculating Platform Fee for display if not stored explicitly
                $platform_fee = $order['total_amount'] - $order['net_to_shop'] - $order['delivery_fee'];
                if($platform_fee > 0):
            ?>
            <div class="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-widest">
                <span>Platform Charges (3%)</span>
                <span>₹<?= number_format($platform_fee, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="pt-4 border-t border-white/10 flex justify-between items-center">
                <span class="text-sm font-black uppercase tracking-widest">Grand Total</span>
                <span class="text-3xl font-black">₹<?= number_format($order['total_amount'], 2) ?></span>
            </div>
        </div>
        
        <div class="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/5">
            <div class="flex items-center gap-3">
                <i class="fas fa-credit-card text-slate-400"></i>
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-300">Payment Mode</span>
            </div>
            <span class="text-[10px] font-black uppercase bg-blue-500 text-white px-3 py-1 rounded-lg"><?= htmlspecialchars($order['payment_mode']) ?></span>
        </div>

        <?php if($order['order_status'] === 'delivered'): ?>
            <div class="mt-6">
                <a href="export_order_receipt.php?order_id=<?= $order_id ?>" target="_blank" class="w-full bg-emerald-600 text-white flex items-center justify-center py-4 rounded-xl font-black text-[10px] uppercase tracking-widest gap-2">
                    <i class="fas fa-file-invoice"></i> Download Receipt (PDF)
                </a>
            </div>
        <?php endif; ?>
    </div>

    <footer class="text-center py-10">
        <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.3em]">KhataLink Marketplace Verified</p>
    </footer>

</main>

<!-- Rating Modal -->
<div id="ratingModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl">
        <h3 class="text-xl font-black text-slate-900 mb-2">Rate your Experience</h3>
        <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-6">How was the service and quality?</p>
        <div class="flex justify-center gap-3 mb-8">
            <?php for($i=1; $i<=5; $i++): ?>
                <button onclick="setRating(<?= $i ?>)" class="star-btn text-3xl text-slate-200 hover:scale-110 transition-transform" data-value="<?= $i ?>">
                    <i class="fas fa-star"></i>
                </button>
            <?php endfor; ?>
        </div>
        <textarea id="ratingComment" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 text-sm font-medium focus:bg-white focus:border-amber-500 outline-none transition-all mb-6" rows="3" placeholder="Any comments? (Optional)"></textarea>
        <div class="flex gap-3">
            <button onclick="closeRatingModal()" class="flex-1 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Cancel</button>
            <button onclick="submitRating()" class="flex-1 bg-amber-500 text-white py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-amber-100">Submit Rating</button>
        </div>
    </div>
</div>

<script>
let currentRating = 0;
let ratingShopId = 0;
let ratingOrderId = 0;

function openRatingModal(shopId, orderId) {
    ratingShopId = shopId;
    ratingOrderId = orderId;
    document.getElementById('ratingModal').classList.replace('hidden', 'flex');
}

function closeRatingModal() {
    document.getElementById('ratingModal').classList.replace('flex', 'hidden');
    currentRating = 0;
    document.querySelectorAll('.star-btn').forEach(btn => btn.classList.replace('text-amber-400', 'text-slate-200'));
    document.getElementById('ratingComment').value = '';
}

function setRating(val) {
    currentRating = val;
    document.querySelectorAll('.star-btn').forEach(btn => {
        const btnVal = parseInt(btn.dataset.value);
        btn.classList.toggle('text-amber-400', btnVal <= val);
        btn.classList.toggle('text-slate-200', btnVal > val);
    });
}

async function submitRating() {
    if(currentRating === 0) return Swal.fire('Wait!', 'Please select a star rating.', 'warning');
    const comment = document.getElementById('ratingComment').value.trim();
    try {
        const res = await fetch('ajax_submit_shop_rating.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ shop_id: ratingShopId, order_id: ratingOrderId, rating: currentRating, comment })
        });
        const data = await res.json();
        if(data.success) {
            Swal.fire('Thank You!', data.message, 'success').then(() => location.reload());
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    } catch(e) { Swal.fire('Error', 'Could not connect to server.', 'error'); }
}
</script>
</body>
</html>
