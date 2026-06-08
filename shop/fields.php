<?php
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

    $customer_id_api = (int)($_GET['customer_id'] ?? 0);

    if (!$shop_id_api || !$customer_id_api) { echo json_encode(['success' => false, 'message' => 'Unauthorized or missing parameters.']); exit(); }

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = trim($data['action'] ?? '');
        $field_id = (int)($data['field_id'] ?? 0);
        $field_name = trim($data['field_name'] ?? '');

        if ($action === 'add' && !empty($field_name)) {
            $stmt = $pdo->prepare("INSERT INTO shop_fields (shop_id, customer_id, field_name) VALUES (?, ?, ?)");
            $success = $stmt->execute([$shop_id_api, $customer_id_api, $field_name]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Field added!' : 'Failed to add field.']);
        } elseif ($action === 'toggle' && $field_id > 0) {
            $stmt = $pdo->prepare("UPDATE shop_fields SET is_active = NOT is_active WHERE id = ? AND shop_id = ? AND customer_id = ?");
            $success = $stmt->execute([$field_id, $shop_id_api, $customer_id_api]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Field status toggled!' : 'Failed to toggle status.']);
        } elseif ($action === 'delete' && $field_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM shop_fields WHERE id = ? AND shop_id = ? AND customer_id = ?");
            $success = $stmt->execute([$field_id, $shop_id_api, $customer_id_api]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Field deleted!' : 'Failed to delete field.']);
        } elseif ($action === 'clear_all') {
            $stmt = $pdo->prepare("DELETE FROM shop_fields WHERE shop_id = ? AND customer_id = ?");
            $success = $stmt->execute([$shop_id_api, $customer_id_api]);
            echo json_encode(['success' => $success, 'message' => $success ? 'All fields cleared!' : 'Failed to clear fields.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        }
        exit();
    }

    // Handle GET requests: Fetch fields
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("SELECT id, field_name, is_active FROM shop_fields WHERE shop_id = ? AND customer_id = ? ORDER BY field_order ASC, created_at DESC");
        $stmt->execute([$shop_id_api, $customer_id_api]);
        $fields = $stmt->fetchAll();

        $stmt_cust = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $stmt_cust->execute([$customer_id_api]);
        $target_customer = $stmt_cust->fetch();

        echo json_encode([
            'success' => true,
            'customer_name' => $target_customer['name'] ?? 'Unknown',
            'fields' => $fields
        ]);
        exit();
    }
}
// ===== END FLUTTER API =====

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

if(!isset($_GET['customer_id'])) {
    header("Location: customers.php");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$customer_id = (int)$_GET['customer_id'];
$error = '';
$success = '';

// Fetch customer details for the header
$stmt_c = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$target_customer = $stmt_c->fetch();

if(!$target_customer) {
    header("Location: customers.php");
    exit();
}

// Add new field
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $field_name = trim($_POST['field_name']);
    if(empty($field_name)) {
        $error = "Field name cannot be empty.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO shop_fields (shop_id, customer_id, field_name) VALUES (?, ?, ?)");
        $stmt->execute([$shop_id, $customer_id, $field_name]);
        $success = "Field added successfully!";
    }
}

// Toggle Status
if(isset($_GET['toggle'])) {
    $field_id = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE shop_fields SET is_active = NOT is_active WHERE id = ? AND shop_id = ? AND customer_id = ?")
        ->execute([$field_id, $shop_id, $customer_id]);
    header("Location: fields.php?customer_id=" . $customer_id);
    exit();
}

// Delete Field
if(isset($_GET['delete'])) {
    $field_id = (int)$_GET['delete'];
    $success_delete = $pdo->prepare("DELETE FROM shop_fields WHERE id = ? AND shop_id = ? AND customer_id = ?")
        ->execute([$field_id, $shop_id, $customer_id]);
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success_delete, 'message' => $success_delete ? 'Field removed.' : 'Failed to remove.']);
        exit();
    } else {
        $success = "Field removed successfully!";
    }
}

// Clear All Fields for this customer
if(isset($_GET['clear_all'])) {
    $success_clear = $pdo->prepare("DELETE FROM shop_fields WHERE shop_id = ? AND customer_id = ?")
        ->execute([$shop_id, $customer_id]);
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success_clear, 'message' => $success_clear ? 'All fields cleared.' : 'Failed to clear.']);
        exit();
    } else {
        $success = "All custom fields for this customer have been removed.";
    }
}

// Fetch fields
$stmt = $pdo->prepare("SELECT * FROM shop_fields WHERE shop_id = ? AND customer_id = ? ORDER BY field_order ASC, created_at DESC");
$stmt->execute([$shop_id, $customer_id]);
$fields = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Fields — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/style/style.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; }
        .kl-navbar { background:#fff; border-bottom:1px solid #e2e8f0; padding:0 32px; height:64px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
        .kl-logo img { height: 40px; }
        .shop-badge { display:flex; align-items:center; gap:8px; background:#eff6ff; border:1px solid #bfdbfe; color:#2563eb; font-size:13px; font-weight:600; padding:6px 14px; border-radius:8px; }
        .btn-logout { display:flex; align-items:center; gap:6px; background:#fef2f2; border:1px solid #fecaca; color:#dc2626; font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px; text-decoration:none; transition:all 0.2s; }
        .btn-logout:hover { background:#dc2626; color:#fff; }
        .layout { display:flex; min-height:calc(100vh - 64px); }
        .sidebar { width:250px; background:#fff; border-right:1px solid #e2e8f0; padding:24px 16px; flex-shrink:0; position:sticky; top:64px; height:calc(100vh - 64px); overflow-y:auto; }
        .sidebar-label { font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:1.5px; text-transform:uppercase; padding:0 12px; margin-bottom:8px; margin-top:20px; }
        .sidebar-label:first-child { margin-top:0; }
        .nav-link { display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; color:#64748b; font-size:14px; font-weight:500; text-decoration:none; transition:all 0.2s; margin-bottom:2px; }
        .nav-link i { width:18px; font-size:15px; text-align:center; }
        .nav-link:hover { background:#eff6ff; color:#2563eb; }
        .nav-link.active { background:#eff6ff; color:#2563eb; font-weight:600; }
        .main { flex:1; padding:32px; }
        .page-title { font-size:26px; font-weight:800; color:#0f172a; letter-spacing:-0.5px; margin-bottom:4px; }
        .page-subtitle { font-size:14px; color:#64748b; margin-bottom:28px; }
        .kl-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; margin-bottom: 24px; }
        .card-title { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px; }
        .kl-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 7px; }
        .kl-input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 11px 16px; font-size: 14px; color: #0f172a; outline: none; transition: all 0.2s; }
        .kl-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
        .btn-add { background: #2563eb; color: #fff; border: none; border-radius: 10px; padding: 11px 24px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-add:hover { background: #1d4ed8; }
        .status-badge { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px; }
        .status-active { background: #ecfdf5; color: #059669; }
        .status-inactive { background: #fef2f2; color: #dc2626; }
        .kl-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .kl-table thead th { background: #f8fafc; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        .kl-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 22px; cursor: pointer; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 998; }
        @media (max-width: 992px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; z-index: 999; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .mobile-menu-btn { display: flex; }
            .main { padding: 20px 16px; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="kl-navbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <button class="mobile-menu-btn" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="kl-logo">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink">
        </a>
    </div>
    <div class="shop-badge">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="layout">
    <?php include '../includes/shop_sidebar.php'; ?>

    <div class="main">
        <a href="customer_details.php?customer_id=<?= $customer_id ?>" class="text-decoration-none small mb-2 d-inline-block">
            <i class="fas fa-arrow-left"></i> Back to <?= htmlspecialchars($target_customer['name']) ?>
        </a>
        <div class="page-title">Fields for <?= htmlspecialchars($target_customer['name']) ?></div>
        <div class="page-subtitle">Add custom categories specifically for this customer</div>

        <?php if($error): ?>
            <div class="alert alert-danger border-0 shadow-sm" style="border-radius:12px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success border-0 shadow-sm" style="border-radius:12px;"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-4">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-plus-circle text-primary"></i> Add New Field</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="kl-label">Field Name</label>
                            <input type="text" name="field_name" class="kl-input" placeholder="e.g. Milk, Eggs, Rent" required>
                        </div>
                        <button type="submit" class="btn-add w-100 justify-content-center">
                            <i class="fas fa-save"></i> Save Field
                        </button>
                    </form>
                </div>

                <div class="alert alert-info border-0" style="border-radius:12px; font-size:13px; line-height:1.6;">
                    <i class="fas fa-info-circle me-1"></i> <strong>Note:</strong> 
                    Fields added here will appear when you create a new Udhar entry. 
                    You can temporarily disable fields without deleting them.
                </div>
            </div>

            <div class="col-lg-8">
                <div class="kl-card p-0 overflow-hidden">
                    <div class="card-title px-4 pt-4 mb-0 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list text-success"></i> Your Custom Fields</span>
                        <?php if(count($fields) > 0): ?>
                            <a href="fields.php?customer_id=<?= $customer_id ?>&clear_all=1" 
                               class="btn btn-sm btn-outline-danger px-3 fw-bold" 
                               onclick="return confirm('WARNING: This will permanently delete ALL custom fields for this customer. This cannot be undone. Continue?')">
                                <i class="fas fa-trash-alt me-1"></i> Clear All
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if(count($fields) > 0): ?>
                        <table class="kl-table">
                            <thead>
                                <tr>
                                    <th>Field Name</th>
                                    <th>Status</th>
                                    <th>Added On</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($fields as $f): ?>
                                    <tr>
                                        <td style="font-weight:600; color:#0f172a;">
                                            <?= htmlspecialchars($f['field_name']) ?>
                                        </td>
                                        <td>
                                            <?php if($f['is_active']): ?>
                                                <span class="status-badge status-active">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small">
                                            <?= date('d M Y', strtotime($f['created_at'])) ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="fields.php?toggle=<?= $f['id'] ?>" 
                                                   class="btn btn-sm <?= $f['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                   title="<?= $f['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="fas <?= $f['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                                </a>
                                                <a href="fields.php?delete=<?= $f['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger ms-1"
                                                   onclick="return confirm('Deleting this field will also remove all records associated with it in past udhar entries. Continue?')"
                                                   title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-sliders-h fa-3x mb-3 opacity-25"></i>
                            <p>No custom fields added yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('show');
}
</script>
</body>
</html>