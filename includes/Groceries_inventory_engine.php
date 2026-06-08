<?php
/**
 * KhataLink Groceries - Atomic Inventory Engine
 * Handles row-level locking for POS and Marketplace sync.
 */

function groceries_reserve_stock(PDO $pdo, int $product_id, float $qty): bool {
    $is_already_in_tx = $pdo->inTransaction();
    try {
        if (!$is_already_in_tx) $pdo->beginTransaction();

        // Row level lock: Select current stock strictly
        $stmt = $pdo->prepare("SELECT current_stock, reserved_stock FROM inventory_products WHERE id = ? FOR UPDATE");
        $stmt->execute([$product_id]);
        $p = $stmt->fetch();

        if ($p && ($p['current_stock'] - $p['reserved_stock']) >= $qty) {
            $pdo->prepare("UPDATE inventory_products SET reserved_stock = reserved_stock + ? WHERE id = ?")
                ->execute([$qty, $product_id]);
            if (!$is_already_in_tx) $pdo->commit();
            return true;
        }
        if (!$is_already_in_tx && $pdo->inTransaction()) $pdo->rollBack();
        return false;
    } catch (Exception $e) {
        if (!$is_already_in_tx && $pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

function groceries_commit_stock(PDO $pdo, int $product_id, float $qty): bool {
    // Deduct from both current and reserved
    $stmt = $pdo->prepare("
        UPDATE inventory_products 
        SET current_stock = current_stock - ?, 
            reserved_stock = GREATEST(0, reserved_stock - ?) 
        WHERE id = ? AND current_stock >= ?
    ");
    return $stmt->execute([$qty, $qty, $product_id, $qty]);
}

function groceries_release_stock(PDO $pdo, int $product_id, float $qty): bool {
    $stmt = $pdo->prepare("UPDATE inventory_products SET reserved_stock = GREATEST(0, reserved_stock - ?) WHERE id = ?");
    return $stmt->execute([$qty, $product_id]);
}

function groceries_add_stock_back(PDO $pdo, int $product_id, float $qty): bool {
    $stmt = $pdo->prepare("UPDATE inventory_products SET current_stock = current_stock + ? WHERE id = ?");
    return $stmt->execute([$qty, $product_id]);
}

/**
 * Updates the Marketplace cache table for a specific product
 */
function groceries_update_cache(PDO $pdo, int $product_id) {
    $stmt = $pdo->prepare("
        REPLACE INTO Groceries_product_marketplace_cache 
        (product_id, shop_id, name, sale_price, primary_unit, image_thumb_path, image_hero_path, shop_latitude, shop_longitude, pincode, current_stock, description, mfg_date, exp_date, product_category)
        SELECT p.id, p.shop_id, p.name, p.sale_price, p.primary_unit, p.image_thumb_path, p.image_hero_path, s.latitude, s.longitude, s.pincode, p.current_stock, p.description, p.mfg_date, p.exp_date, p.product_category
        FROM inventory_products p
        JOIN shop_owners s ON p.shop_id = s.id
        WHERE p.id = ? AND p.is_marketplace_visible = 1 AND s.is_verified = 1
    ");
    $stmt->execute([$product_id]);
}