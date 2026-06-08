<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
require_once '../includes/db.php';
require_once '../includes/notification_service.php'; // Added for inventory alerts

$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');
$decoded = base64_decode($token);
$parts = explode(':', $decoded);
$shop_id = (int)($parts[0]?? 0);

if (!$shop_id) die(json_encode(['success' => false, 'message' => 'Unauthorized']));

$name = trim($_POST['name']?? '');
$primary_unit = $_POST['primary_unit']?? 'NOS';
$sale_price = (float)($_POST['sale_price']?? 0);
$purchase_price = (float)($_POST['purchase_price']?? 0);
$tax_included = (int)($_POST['tax_included']?? 1);
$opening_stock = (float)($_POST['opening_stock']?? 0);
$low_stock_alert = (float)($_POST['low_stock_alert']?? 0);
$hsn_code = trim($_POST['hsn_code']?? '');
$gst_percent = (float)($_POST['gst_percent']?? 0);
$barcode = trim($_POST['barcode']?? '');
$product_id = (int)($_POST['product_id']?? 0);
$description = trim($_POST['description'] ?? '');
$mfg_date = !empty($_POST['mfg_date']) ? $_POST['mfg_date'] : null;
$exp_date = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;
$product_categories = $_POST['product_category'] ?? ['Other'];
$product_category_str = is_array($product_categories) ? implode(',', $product_categories) : $product_categories;

if (empty($name)) die(json_encode(['success' => false, 'message' => 'Product name required']));

$photo = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
    $upload_dir = '../assets/img/products/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $photo = uniqid(). '.'. $ext;
    move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir. $photo);
}

if ($product_id > 0) {
    // UPDATE
    $stmt_curr = $pdo->prepare("SELECT photo FROM inventory_products WHERE id =? AND shop_id =?");
    $stmt_curr->execute([$product_id, $shop_id]);
    $curr_prod = $stmt_curr->fetch();

    $sql = "UPDATE inventory_products SET name=?, primary_unit=?, sale_price=?, purchase_price=?, tax_included=?, opening_stock=?, low_stock_alert=?, current_stock=?, hsn_code=?, gst_percent=?, barcode=?, description=?, mfg_date=?, exp_date=?, product_category=?, last_updated_at=NOW()";
    $params = [$name, $primary_unit, $sale_price, $purchase_price, $tax_included, $opening_stock, $low_stock_alert, $opening_stock, $hsn_code, $gst_percent, $barcode, $description, $mfg_date, $exp_date, $product_category_str];

    if ($photo) {
        if ($curr_prod['photo'] && file_exists('../assets/img/products/'. $curr_prod['photo'])) {
            unlink('../assets/img/products/'. $curr_prod['photo']);
        }
        $sql.= ", photo=?";
        $params[] = $photo;
    } elseif (isset($_POST['remove_photo'])) {
        if ($curr_prod['photo'] && file_exists('../assets/img/products/'. $curr_prod['photo'])) {
            unlink('../assets/img/products/'. $curr_prod['photo']);
        }
        $sql.= ", photo=NULL";
    }

    $sql.= " WHERE id=? AND shop_id=?";
    $params[] = $product_id;
    $params[] = $shop_id;
    $pdo->prepare($sql)->execute($params); // Execute update

    checkInventoryAlert($pdo, $shop_id, $product_id); // Check alert after update
} else {
    // INSERT
    $stmt = $pdo->prepare("INSERT INTO inventory_products
        (shop_id, name, photo, primary_unit, sale_price, purchase_price, tax_included, opening_stock, low_stock_alert, current_stock, hsn_code, gst_percent, barcode, description, mfg_date, exp_date, product_category)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$shop_id, $name, $photo, $primary_unit, $sale_price, $purchase_price, $tax_included, $opening_stock, $low_stock_alert, $opening_stock, $hsn_code, $gst_percent, $barcode, $description, $mfg_date, $exp_date, $product_category]); // Execute insert
    $new_product_id = $pdo->lastInsertId();
    checkInventoryAlert($pdo, $shop_id, $new_product_id); // Check alert after insert
}

echo json_encode(['success' => true, 'message' => 'Product saved successfully']);
?>