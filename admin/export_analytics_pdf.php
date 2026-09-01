<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['admin_id'])) {
    die("Unauthorized access.");
}

$shop_id_filter = (int)($_GET['shop_id'] ?? 0);
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-29 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch all shops for the filter dropdown (needed for shop name in report title)
$all_shops = $pdo->query("SELECT id, shop_name FROM shop_owners ORDER BY shop_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filter_shop_name = "All Shops (Platform Wide)";
if ($shop_id_filter) {
    $stmt_shop = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $stmt_shop->execute([$shop_id_filter]);
    $shop_info = $stmt_shop->fetch(PDO::FETCH_ASSOC);
    if ($shop_info) {
        $filter_shop_name = htmlspecialchars($shop_info['shop_name']);
    }
}


// Base WHERE clause for queries
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
foreach($revenue_data as $row) {
    $date_map[$row['order_date']] = $row['daily_revenue'];
}
$period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
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
$top_shops = [];
if (!$shop_id_filter) {
    $top_shops = $pdo->query("
        SELECT s.shop_name, SUM(o.total_amount) as total_revenue
        FROM orders o
        JOIN shop_owners s ON o.shop_id = s.id
        WHERE o.order_status != 'cancelled' AND DATE(o.created_at) BETWEEN '$start_date' AND '$end_date'
        GROUP BY o.shop_id
        ORDER BY total_revenue DESC
        LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
}
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
$lowest_data = [];
$lowest_title = "";
if ($shop_id_filter) {
    $lowest_title = "Lowest Selling Products for " . $filter_shop_name;
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
    $lowest_title = "Lowest Performing Shops (by Order Count)";
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
    <title>Analytics Report - KhataLink Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; padding: 40px; color: #1e293b; line-height: 1.5; }
        .report-container { max-width: 800px; margin: 0 auto; border: 1px solid #e2e8f0; padding: 30px; border-radius: 10px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header img { height: 50px; margin-bottom: 15px; }
        .header h1 { font-size: 24px; font-weight: 900; margin: 0; color: #0f172a; }
        .header p { font-size: 12px; color: #64748b; margin: 5px 0 0; }
        .section-title { font-size: 16px; font-weight: 800; color: #2563eb; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-top: 30px; margin-bottom: 15px; }
        .chart-wrap { position: relative; height: 250px; margin-bottom: 30px; page-break-inside: avoid; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: left; font-size: 12px; }
        th { background-color: #f8fafc; font-weight: 700; color: #475569; }
        .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .summary-box h3 { font-size: 14px; font-weight: 700; margin-bottom: 10px; color: #0f172a; }
        .summary-box p { font-size: 12px; margin: 5px 0; }
        .footer { text-align: center; margin-top: 40px; font-size: 10px; color: #94a3b8; }
        .no-print { text-align: right; margin-bottom: 20px; }
        .no-print button { padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
    <div class="report-container">
        <div class="header">
            <img src="../assets/favicon.png" alt="KhataLink Logo">
            <h1>KhataLink Data Analytics Report</h1>
            <p>Generated for: <?= $filter_shop_name ?></p>
            <p>Date: <?= date('d M Y H:i:s') ?></p>
        </div>

        <div class="section-title">Revenue Trend</div>
        <div class="chart-wrap"><canvas id="revenueChart"></canvas></div>

        <div class="section-title">Revenue Trend (Last 30 Days)</div>
        <table>
            <thead>
                <tr><th>Date</th><th>Revenue (₹)</th></tr>
            </thead>
            <tbody>
                <?php foreach(array_combine($revenue_labels, $revenue_values) as $label => $value): ?>
                    <tr><td><?= $label ?></td><td><?= number_format($value, 2) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">User Growth</div>
        <div class="chart-wrap"><canvas id="userGrowthChart"></canvas></div>

        <div class="section-title">User Growth (Last 30 Days)</div>
        <table>
            <thead>
                <tr><th>Date</th><th>New Customers</th><?php if(!$shop_id_filter): ?><th>New Shops</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach($growth_labels as $index => $label): ?>
                    <tr>
                        <td><?= $label ?></td>
                        <td><?= $customer_values[$index] ?></td>
                        <?php if(!$shop_id_filter): ?><td><?= $shop_values[$index] ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Order Status Distribution</div>
        <div class="chart-wrap" style="height: 300px;"><canvas id="orderStatusChart"></canvas></div>

        <?php if (!$shop_id_filter && !empty($top_shops)): ?>
        <div class="section-title">Top 5 Shops by Revenue</div>
        <table>
            <thead>
                <tr><th>Shop Name</th><th>Total Revenue (₹)</th></tr>
            </thead>
            <tbody>
                <?php foreach($top_shops as $shop): ?>
                    <tr><td><?= htmlspecialchars($shop['shop_name']) ?></td><td><?= number_format($shop['total_revenue'], 2) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="section-title">Top 5 Customers by Spending</div>
        <table>
            <thead>
                <tr><th>Customer Name</th><th>Total Spent (₹)</th></tr>
            </thead>
            <tbody>
                <?php foreach($top_customers as $customer): ?>
                    <tr><td><?= htmlspecialchars($customer['name']) ?></td><td><?= number_format($customer['total_spent'], 2) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Top 5 Selling Products (by Quantity)</div>
        <table>
            <thead>
                <tr><th>Product Name</th><th>Quantity Sold</th></tr>
            </thead>
            <tbody>
                <?php foreach($top_products as $product): ?>
                    <tr><td><?= htmlspecialchars($product['product_name']) ?></td><td><?= number_format($product['total_sold']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Order Status Distribution</div>
        <table>
            <thead>
                <tr><th>Status</th><th>Count</th></tr>
            </thead>
            <tbody>
                <?php foreach($order_status_data as $status => $count): ?>
                    <tr><td><?= htmlspecialchars(ucfirst($status)) ?></td><td><?= number_format($count) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title"><?= $lowest_title ?></div>
        <table>
            <thead>
                <tr><th><?= $shop_id_filter ? 'Product Name' : 'Shop Name' ?></th><th>Count</th></tr>
            </thead>
            <tbody>
                <?php foreach($lowest_data as $item): ?>
                    <tr><td><?= htmlspecialchars($item['name']) ?></td><td><?= number_format($item['count']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            © <?= date('Y') ?> KhataLink Admin Panel. All rights reserved.
        </div>
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
            tension: 0.4
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
                backgroundColor: 'rgba(5, 150, 105, 0.7)',
                borderRadius: 4
            },
            <?php if(!$shop_id_filter): ?>
            {
                label: 'New Shops',
                data: <?= json_encode($shop_values) ?>,
                backgroundColor: 'rgba(217, 119, 6, 0.7)',
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
                position: 'right',
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

// We can add the other charts here as well if needed

// This is a trick to make sure charts are rendered before printing
setTimeout(() => {
    // If you want to automatically trigger print dialog
    // window.print(); 
}, 1000); // 1 second delay

</script>

</body>
</html>