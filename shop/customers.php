<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id_api = $parts[0] ?? 0;

    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'active';

    $query = "SELECT c.id, c.unique_id, c.name, c.email, sc.added_at,
              COALESCE(SUM(ue.total_remaining), 0) AS total_due,
              COUNT(DISTINCT ue.id) AS total_udhar_entries
              FROM customers c
              JOIN shop_customers sc ON c.id = sc.customer_id
              LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = sc.shop_id AND ue.status = 'open'
              WHERE sc.shop_id = ?";
    $params = [$shop_id_api];
    if ($search) {
        $query .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.unique_id LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    $query .= " GROUP BY c.id ";
    if ($filter === 'active') { $query .= " HAVING total_due > 0 "; }
    $query .= " ORDER BY sc.added_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $customers_list = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'customers' => $customers_list,
    ]);
    exit();
}
// ===== END FLUTTER API =====

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'active'; // 'active' or 'all'

// Pagination Settings
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch customers for the current shop
$query = "
    SELECT 
        c.id, 
        c.unique_id, 
        c.name, 
        c.email, 
        c.truecaller_verified,
        sc.added_at,
        COALESCE(SUM(ue.total_remaining), 0) AS total_due,
        COUNT(DISTINCT ue.id) AS total_udhar_entries
    FROM 
        customers c
    JOIN 
        shop_customers sc ON c.id = sc.customer_id
    LEFT JOIN 
        udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = sc.shop_id AND ue.status = 'open'
    WHERE 
        sc.shop_id = ?
";
$params = [$shop_id];

if ($search) {
    $query .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.unique_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " GROUP BY c.id ";

if ($filter === 'active') {
    $query .= " HAVING total_due > 0 ";
}

$query .= " ORDER BY sc.added_at DESC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Get total customers for the current shop
$total_customers_stmt = $pdo->prepare("SELECT COUNT(*) FROM shop_customers WHERE shop_id = ?");
$total_customers_stmt->execute([$shop_id]);
$total_customers = $total_customers_stmt->fetchColumn();

// Get total due amount for the current shop
$total_due_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = ? AND status = 'open'");
$total_due_stmt->execute([$shop_id]);
$total_shop_due = $total_due_stmt->fetchColumn();

// Get total udhar entries for the current shop
$total_udhar_entries_stmt = $pdo->prepare("SELECT COUNT(*) FROM udhar_entries WHERE shop_id = ? AND status = 'open' AND total_remaining > 0");
$total_udhar_entries_stmt->execute([$shop_id]);
$total_udhar_entries = $total_udhar_entries_stmt->fetchColumn();

// Total filtered results for pagination
$count_query = "SELECT c.id FROM customers c JOIN shop_customers sc ON c.id = sc.customer_id LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = sc.shop_id AND ue.status = 'open' WHERE sc.shop_id = ?";
$count_params = [$shop_id];
if ($search) {
    $count_query .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.unique_id LIKE ?)";
    $count_params[] = "%$search%"; $count_params[] = "%$search%"; $count_params[] = "%$search%";
}
$count_query .= " GROUP BY c.id";
if ($filter === 'active') { $count_query .= " HAVING COALESCE(SUM(ue.total_remaining), 0) > 0"; }

$total_filtered_stmt = $pdo->prepare("SELECT COUNT(*) FROM ($count_query) AS t");
$total_filtered_stmt->execute($count_params);
$total_filtered = $total_filtered_stmt->fetchColumn();
$total_pages = ceil($total_filtered / $limit);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Customers — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-900 font-[Inter]">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">

    <?php include '../includes/shop_sidebar.php'; ?>

    <!-- Main -->
    <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">My Customers</h1>
            <p class="text-slate-500 text-sm">View and manage all customers linked with your shop.</p>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-8">
            <div class="bg-white border border-slate-200 rounded-xl sm:rounded-2xl p-2 sm:p-5 group transition-all hover:shadow-lg">
                <div class="w-7 h-7 sm:w-10 sm:h-10 bg-emerald-50 text-emerald-600 rounded-lg sm:rounded-xl flex items-center justify-center text-[10px] sm:text-sm mb-1 sm:mb-3">
                    <i class="fas fa-users"></i>
                </div>
                <div class="text-slate-500 text-[8px] sm:text-xs font-semibold mb-0 sm:mb-1 uppercase tracking-wider truncate">Customers</div>
                <div class="text-sm sm:text-2xl font-black text-slate-900 tracking-tight"><?= $total_customers ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl sm:rounded-2xl p-2 sm:p-5 group transition-all hover:shadow-lg">
                <div class="w-7 h-7 sm:w-10 sm:h-10 bg-red-50 text-red-600 rounded-lg sm:rounded-xl flex items-center justify-center text-[10px] sm:text-sm mb-1 sm:mb-3">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div class="text-slate-500 text-[8px] sm:text-xs font-semibold mb-0 sm:mb-1 uppercase tracking-wider truncate">Receivables</div>
                <div class="text-sm sm:text-2xl font-black text-red-600 tracking-tight">₹<?= number_format($total_shop_due, 0) ?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl sm:rounded-2xl p-2 sm:p-5 group transition-all hover:shadow-lg">
                <div class="w-7 h-7 sm:w-10 sm:h-10 bg-blue-50 text-blue-600 rounded-lg sm:rounded-xl flex items-center justify-center text-[10px] sm:text-sm mb-1 sm:mb-3">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="text-slate-500 text-[8px] sm:text-xs font-semibold mb-0 sm:mb-1 uppercase tracking-wider truncate">Active Khata</div>
                <div class="text-sm sm:text-2xl font-black text-slate-900 tracking-tight"><?= $total_udhar_entries ?></div>
            </div>
        </div>

        <!-- Search Bar -->
        <form method="GET" class="bg-white border border-slate-200 rounded-[2rem] p-4 md:p-6 mb-8 flex flex-col md:flex-row gap-4 items-end">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <div class="flex-1 w-full">
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Filter by Name/ID</label>
                <input type="text" name="search" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm focus:bg-white focus:border-blue-500 outline-none transition-all"
                    placeholder="Search name, email or unique ID..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="flex gap-2 w-full md:w-auto">
                <button type="submit" class="flex-1 md:flex-none bg-slate-900 text-white font-bold px-8 py-3.5 rounded-2xl hover:bg-blue-600 active:scale-95 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-search text-xs"></i> Filter
                </button>
                <a href="customers.php" class="flex-1 md:flex-none bg-slate-100 text-slate-600 font-bold px-6 py-3.5 rounded-2xl hover:bg-slate-200 active:scale-95 transition-all text-center">
                    Reset
                </a>
            </div>
        </form>

        <!-- Filter Toggles -->
        <div class="flex items-center gap-2 mb-6 bg-slate-200/50 p-1.5 rounded-2xl w-fit">
            <a href="?filter=active&search=<?= urlencode($search) ?>" class="px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all <?= $filter === 'active' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-700' ?>">
                Active Dues
            </a>
            <a href="?filter=all&search=<?= urlencode($search) ?>" class="px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all <?= $filter === 'all' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-700' ?>">
                All Customers
            </a>
        </div>

        <!-- Customers Table -->
        <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm shadow-slate-200/50">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 uppercase tracking-tight">
                    <i class="fas fa-list text-emerald-600"></i> Customer Index
                </h3>
                <div class="flex items-center gap-3">
                    <a href="export_customers.php?filter=<?= $filter ?>&search=<?= urlencode($search) ?>" class="text-[10px] font-black text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-lg uppercase tracking-widest hover:bg-emerald-600 hover:text-white transition-all">
                        <i class="fas fa-file-excel mr-1"></i> Export Excel
                    </a>
                    <div class="text-[10px] font-black text-blue-600 bg-blue-50 px-2.5 py-1 rounded-lg uppercase tracking-widest">
                        <?= count($customers) ?> Matched
                    </div>
                </div>
            </div>

            <?php if(count($customers) > 0): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden sm:table-cell">#</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest min-w-[120px]">Customer</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden lg:table-cell">Identity</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center hidden sm:table-cell">Entries</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Due</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($customers as $i => $c): ?>
                        <tr class="hover:bg-slate-50 transition-colors whitespace-nowrap">
                            <td class="px-6 py-4 text-slate-400 font-bold text-xs hidden sm:table-cell"><?= ($offset + $i + 1) ?></td>
                            <td class="px-6 py-4 min-w-0">
                                <div class="flex items-center gap-2 sm:gap-3">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 bg-indigo-50 text-indigo-600 rounded-lg sm:rounded-xl flex items-center justify-center text-xs sm:text-sm font-black shadow-sm shrink-0">
                                        <?= strtoupper(substr($c['name'],0,1)) ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-1">
                                            <div class="text-[11px] sm:text-sm font-black text-slate-900 tracking-tight truncate max-w-[100px] sm:max-w-none"><?= htmlspecialchars($c['name']) ?></div>
                                            <?php if($c['truecaller_verified']): ?>
                                                <i class="fas fa-check-circle text-blue-500 text-[10px]" title="Identity Verified by Truecaller"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[9px] font-bold text-slate-400 lg:hidden truncate italic tracking-tighter"><?= htmlspecialchars($c['unique_id']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 hidden lg:table-cell">
                                <div class="bg-slate-100 text-slate-600 text-[10px] font-black px-2 py-1 rounded-md uppercase inline-block"><?= htmlspecialchars($c['unique_id']) ?></div>
                            </td>
                            <td class="px-6 py-4 text-center hidden sm:table-cell">
                                <span class="bg-blue-50 text-blue-700 text-[10px] font-black px-2 py-1 rounded-full uppercase"><?= $c['total_udhar_entries'] ?> Bills</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <?php if($c['total_due'] > 0): ?>
                                <span class="text-red-600 font-black text-[11px] sm:text-sm tracking-tight">₹<?= number_format($c['total_due'], 0) ?></span>
                                <?php else: ?>
                                <span class="text-emerald-600 font-black text-[9px] uppercase tracking-tight italic">✓ Paid</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="customer_details.php?customer_id=<?= $c['id'] ?>" class="inline-flex items-center justify-center w-7 h-7 sm:w-auto sm:h-auto sm:px-4 sm:py-2 bg-slate-900 text-white text-[10px] font-black rounded-lg sm:rounded-xl hover:bg-blue-600 transition-all uppercase tracking-widest shadow-lg shadow-slate-200 active:scale-90">
                                    <span class="hidden sm:inline">Open</span> <i class="fas fa-chevron-right text-[8px] sm:ml-2"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination UI -->
            <?php if($total_pages > 1): ?>
            <div class="p-4 border-t border-slate-100 flex justify-center">
                <nav class="flex items-center gap-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&filter=<?= $filter ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"><i class="fas fa-chevron-left text-xs"></i></a>
                    <?php endif; ?>
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter=<?= $filter ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-sm font-bold <?= $i == $page ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-100' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&filter=<?= $filter ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"><i class="fas fa-chevron-right text-xs"></i></a>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="text-center py-20 bg-white border border-slate-200 rounded-[2.5rem] shadow-sm">
                <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
                    <i class="fas fa-users"></i>
                </div>
                <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs mb-2">No Customers Found</h3>
                <p class="text-slate-400 text-xs font-medium">
                    <?php if($search): ?>
                        No customers match your search. Try a different query or <a href="customers.php" class="text-blue-600 font-bold hover:underline">reset filters</a>.
                    <?php else: ?>
                        You haven't added any customers yet. <a href="add_customer.php" class="text-blue-600 font-bold hover:underline">Add your first customer</a>.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">
        © <?= date('Y') ?> KhataLink — Premium Digital Ledger
    </div>
</footer>

<script>
// Sidebar toggle functions (matching shop_sidebar.php)
function openSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    if (sidebar) { sidebar.classList.add('open'); sidebar.classList.remove('hidden'); }
    if (overlay) { overlay.classList.remove('hidden'); setTimeout(() => overlay.classList.add('opacity-100'), 10); }
}
function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    if (sidebar) { sidebar.classList.remove('open'); }
    if (overlay) { overlay.classList.remove('opacity-100'); setTimeout(() => overlay.classList.add('hidden'), 300); }
}
</script>

</body>
</html>
