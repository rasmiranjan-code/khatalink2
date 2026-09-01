<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$shop_id_filter = (int)($_GET['shop_id'] ?? 0);
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-29 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch all shops for the filter dropdown
$all_shops = $pdo->query("SELECT id, shop_name FROM shop_owners ORDER BY shop_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Base WHERE clause for queries using prepared statements
$params = [];
$shop_where_clause = "";
if ($shop_id_filter) {
    $shop_where_clause = " AND o.shop_id = ? ";
    $params[] = $shop_id_filter;
}

// 1. Revenue Analytics
$revenue_query = "
    SELECT DATE(o.created_at) as order_date, SUM(o.total_amount) as daily_revenue
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.order_status != 'cancelled' $shop_where_clause
    GROUP BY order_date
    ORDER BY order_date ASC";
$stmt_revenue = $pdo->prepare($revenue_query);
$stmt_revenue->execute(array_merge([$start_date, $end_date], $params));
$revenue_data = $stmt_revenue->fetchAll(PDO::FETCH_ASSOC);

$revenue_labels = [];
$revenue_values = [];
$date_map = [];
$period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));

foreach($revenue_data as $row) {
    $date_map[$row['order_date']] = $row['daily_revenue'];
}
foreach ($period as $date) {
    $d = $date->format('Y-m-d');
    $revenue_labels[] = $date->format('d M');
    $revenue_values[] = (float)($date_map[$d] ?? 0);
}

// 2. User Growth Analytics
if ($shop_id_filter) {
    // New customers for a specific shop
    $customer_growth_query = "SELECT DATE(added_at) as join_date, COUNT(*) as new_users FROM shop_customers WHERE shop_id = ? AND DATE(added_at) BETWEEN ? AND ? GROUP BY join_date ORDER BY join_date ASC";
    $stmt_cg = $pdo->prepare($customer_growth_query);
    $stmt_cg->execute([$shop_id_filter, $start_date, $end_date]);
    $customer_growth = $stmt_cg->fetchAll(PDO::FETCH_KEY_PAIR);
    $shop_growth = []; // Not applicable when a shop is selected
} else {
    // Platform-wide user growth
    $customer_growth = $pdo->query("SELECT DATE(created_at) as join_date, COUNT(*) as new_users FROM customers WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' GROUP BY join_date ORDER BY join_date ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $shop_growth = $pdo->query("SELECT DATE(created_at) as join_date, COUNT(*) as new_shops FROM shop_owners WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' GROUP BY join_date ORDER BY join_date ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$growth_labels = [];
$customer_values = [];
$shop_values = [];
foreach ($period as $date) {
    $d = $date->format('Y-m-d');
    $growth_labels[] = $date->format('d M');
    $customer_values[] = (int)($customer_growth[$d] ?? 0);
    if (!$shop_id_filter) {
        $shop_values[] = (int)($shop_growth[$d] ?? 0);
    }
}

// 3. Top Performing Shops (by revenue)
$top_shops = $pdo->query("
    SELECT s.shop_name, SUM(o.total_amount) as total_revenue
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id " . ($shop_id_filter ? "WHERE o.shop_id = $shop_id_filter" : "WHERE o.order_status != 'cancelled' AND DATE(o.created_at) BETWEEN '$start_date' AND '$end_date'") . "
    GROUP BY o.shop_id
    ORDER BY total_revenue DESC
    LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);
$top_shop_labels = array_column($top_shops, 'shop_name');
$top_shop_values = array_column($top_shops, 'total_revenue');

// 4. Top Customers by Spending
$top_customers_query = "
    SELECT c.name, SUM(o.total_amount) as total_spent
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.order_status != 'cancelled' AND DATE(o.created_at) BETWEEN ? AND ? $shop_where_clause
    GROUP BY o.customer_id
    ORDER BY total_spent DESC
    LIMIT 5";
$stmt_tc = $pdo->prepare($top_customers_query);
$stmt_tc->execute(array_merge([$start_date, $end_date], $params));
$top_customers = $stmt_tc->fetchAll(PDO::FETCH_ASSOC);
$top_customer_labels = array_column($top_customers, 'name');
$top_customer_values = array_column($top_customers, 'total_spent');

// 5. Top Selling Products
$top_products_query = "
    SELECT ip.name as product_name, SUM(oi.quantity) as total_sold
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN inventory_products ip ON oi.product_id = ip.id
    WHERE o.order_status != 'cancelled' AND DATE(o.created_at) BETWEEN ? AND ? $shop_where_clause
    GROUP BY oi.product_id, ip.name
    ORDER BY total_sold DESC
    LIMIT 5";
$stmt_tp = $pdo->prepare($top_products_query);
$stmt_tp->execute(array_merge([$start_date, $end_date], $params));
$top_products = $stmt_tp->fetchAll(PDO::FETCH_ASSOC);
$top_product_labels = array_column($top_products, 'product_name');
$top_product_values = array_column($top_products, 'total_sold');

// 6. Order Status Distribution (Pie Chart)
$order_status_query = "SELECT order_status, COUNT(*) as count FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? $shop_where_clause GROUP BY order_status";
$stmt_os = $pdo->prepare($order_status_query);
$os_params = [$start_date, $end_date];
if ($shop_id_filter) $os_params[] = $shop_id_filter;
$stmt_os->execute($os_params);

$order_status_data = $stmt_os->fetchAll(PDO::FETCH_KEY_PAIR);
$pie_labels = array_keys($order_status_data);
$pie_values = array_values($order_status_data);

// 7. Lowest Performing Shops / Products
if ($shop_id_filter) {
    $lowest_title = "Lowest Selling Products";
    $lowest_query = "
        SELECT ip.name as name, SUM(oi.quantity) as count
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN inventory_products ip ON oi.product_id = ip.id
        WHERE o.order_status != 'cancelled' AND o.shop_id = ? AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY oi.product_id, ip.name
        ORDER BY count ASC
        LIMIT 5";
} else {
    $lowest_title = "Lowest Performing Shops";
    $lowest_query = "
    SELECT s.shop_name as name, COUNT(o.id) as count
    FROM shop_owners s
    LEFT JOIN orders o ON s.id = o.shop_id AND o.order_status != 'cancelled' AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY o.shop_id
    ORDER BY count ASC
    LIMIT 5";
}
$stmt_lo = $pdo->prepare($lowest_query);
$lowest_params = $shop_id_filter ? [$shop_id_filter, $start_date, $end_date] : [$start_date, $end_date];
$stmt_lo->execute($lowest_params);
$lowest_data = $stmt_lo->fetchAll(PDO::FETCH_ASSOC);
$lowest_labels = array_column($lowest_data, 'name');
$lowest_values = array_column($lowest_data, 'count');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Analytics — KhataLink Admin</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        .kl-navbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .layout { display: flex; min-height: calc(100vh - 64px); }
        .sidebar { width: 260px; background: #fff; border-right: 1px solid #e2e8f0; padding: 20px 12px; flex-shrink: 0; position: sticky; top: 64px; height: calc(100vh - 64px); overflow-y: auto; }
        .sidebar-section-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: 1.5px; text-transform: uppercase; padding: 0 10px; margin: 20px 0 6px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; color: #64748b; font-size: 13.5px; font-weight: 500; text-decoration: none; transition: all .15s; }
        .nav-link .nav-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; background: #f8fafc; color: #94a3b8; }
        .nav-link:hover { background: #f8fafc; color: #1e40af; }
        .nav-link.active { background: #eff6ff; color: #1d4ed8; font-weight: 600; }
        .nav-link.active .nav-icon { background: #dbeafe; color: #2563eb; }
        .main { flex: 1; padding: 28px 32px; overflow-x: hidden; }
        .page-title { font-size: 26px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 4px; }
        .page-subtitle { font-size: 14px; color: #64748b; margin-bottom: 24px; }
        .kl-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .card-title { font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px; }
        .chart-wrap { position: relative; height: 280px; }
        .filter-bar { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <?php include '../includes/admin_sidebar.php'; ?>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <div class="page-header">
            <div class="page-title">Data Analytics</div>
            <div class="page-subtitle">Key business performance metrics and trends.</div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1 fw-bold small">Shop:</label>
                    <select name="shop_id" class="form-select form-select-sm">
                        <option value="0">All Shops (Platform Wide)</option>
                        <?php foreach($all_shops as $shop): ?>
                            <option value="<?= $shop['id'] ?>" <?= ($shop_id_filter == $shop['id']) ? 'selected' : '' ?>><?= htmlspecialchars($shop['shop_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label mb-1 fw-bold small">Start Date:</label><input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control form-control-sm"></div>
                <div class="col-md-3"><label class="form-label mb-1 fw-bold small">End Date:</label><input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control form-control-sm"></div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-dark w-100">Apply Filters</button>
                    <a href="data_analytics.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
            <div class="text-center mt-3">
                <a href="export_analytics_pdf.php?shop_id=<?= $shop_id_filter ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" target="_blank" class="btn btn-sm btn-primary">
                    <i class="fas fa-file-pdf me-1"></i> Download PDF
                </a>
        </div>

        <div class="row">
            <!-- Revenue Chart -->
            <div class="col-lg-12">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-chart-line text-primary"></i> Revenue Trend</div>
                    <div class="chart-wrap">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- User Growth Chart -->
            <div class="col-lg-6">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-users text-success"></i> New Users</div>
                    <div class="chart-wrap">
                        <canvas id="userGrowthChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Order Status Pie Chart -->
            <div class="col-lg-6">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-pie-chart text-info"></i> Order Status Distribution</div>
                    <div class="chart-wrap">
                        <canvas id="orderStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Customers Chart -->
            <div class="col-lg-6">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-crown text-primary"></i> Top 5 Customers by Spending</div>
                    <div class="chart-wrap">
                        <canvas id="topCustomersChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Products Chart -->
            <div class="col-lg-6">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-box-open text-success"></i> Top 5 Selling Products (by Qty)</div>
                    <div class="chart-wrap">
                        <canvas id="topProductsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Lowest Performing Chart -->
            <div class="col-lg-6">
                <div class="kl-card">
                    <div class="card-title"><i class="fas fa-arrow-down text-danger"></i> <?= $lowest_title ?></div>
                    <div class="chart-wrap">
                        <canvas id="lowestPerformingChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 } } }
    }
};

// Revenue Chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($revenue_labels) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= json_encode($revenue_values) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.1)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#2563eb',
            pointRadius: 4
        }]
    },
    options: chartOptions
});

// User Growth Chart
new Chart(document.getElementById('userGrowthChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($growth_labels) ?>,
        datasets: [
            {
                label: 'New Customers',
                data: <?= json_encode($customer_values) ?>,
                backgroundColor: 'rgba(5, 150, 105, 0.8)',
                borderRadius: 4
            },
            <?php if(!$shop_id_filter): ?>
            {
                label: 'New Shops',
                data: <?= json_encode($shop_values) ?>,
                backgroundColor: 'rgba(217, 119, 6, 0.8)',
                borderRadius: 4
            }
            <?php endif; ?>
        ]
    },
    options: {
        ...chartOptions,
        plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } },
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { stacked: true, grid: { color: '#f1f5f9' } }
        }
    }
});

// Order Status Pie Chart
new Chart(document.getElementById('orderStatusChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode($pie_labels) ?>,
        datasets: [{
            data: <?= json_encode($pie_values) ?>,
            backgroundColor: ['#2563eb', '#059669', '#dc2626', '#f59e0b', '#64748b'],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    font: { size: 11, weight: '600' },
                    padding: 15,
                    usePointStyle: true,
                    pointStyle: 'circle'
                }
            }
        }
    }
});

// Top Customers Chart
new Chart(document.getElementById('topCustomersChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($top_customer_labels) ?>,
        datasets: [{
            label: 'Total Revenue',
            data: <?= json_encode($top_customer_values) ?>,
            backgroundColor: [
                'rgba(245, 158, 11, 0.8)',
                'rgba(245, 158, 11, 0.7)',
                'rgba(245, 158, 11, 0.6)',
                'rgba(245, 158, 11, 0.5)',
                'rgba(245, 158, 11, 0.4)'
            ],
            borderColor: '#d97706',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return ' Revenue: ₹' + context.raw.toLocaleString('en-IN');
                    }
                }
            }
        },
        scales: {
            x: {
                grid: { color: '#f1f5f9' },
                ticks: {
                    callback: function(value) {
                        return '₹' + (value / 1000) + 'k';
                    }
                }
            },
            y: { grid: { display: false } }
        }
    }
});

// Top Products Chart
new Chart(document.getElementById('topProductsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($top_product_labels) ?>,
        datasets: [{
            label: 'Total Quantity Sold',
            data: <?= json_encode($top_product_values) ?>,
            backgroundColor: [
                'rgba(5, 150, 105, 0.8)',
                'rgba(5, 150, 105, 0.7)',
                'rgba(5, 150, 105, 0.6)',
                'rgba(5, 150, 105, 0.5)',
                'rgba(5, 150, 105, 0.4)'
            ],
            borderColor: '#047857',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        ...chartOptions,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return ' Units Sold: ' + context.raw.toLocaleString('en-IN');
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false } },
            y: { grid: { color: '#f1f5f9' } }
        }
    }
});

// Lowest Performing Chart
new Chart(document.getElementById('lowestPerformingChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($lowest_labels) ?>,
        datasets: [{
            label: 'Count',
            data: <?= json_encode($lowest_values) ?>,
            backgroundColor: [
                'rgba(220, 38, 38, 0.4)',
                'rgba(220, 38, 38, 0.5)',
                'rgba(220, 38, 38, 0.6)',
                'rgba(220, 38, 38, 0.7)',
                'rgba(220, 38, 38, 0.8)'
            ],
            borderColor: '#b91c1c',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return ' Count: ' + context.raw.toLocaleString('en-IN');
                    }
                }
            }
        },
        scales: {
            x: { grid: { color: '#f1f5f9' } },
            y: { grid: { display: false } }
        }
    }
});

</script>

</body>
</html>