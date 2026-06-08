-- KhataLink Database
-- ==================

CREATE DATABASE IF NOT EXISTS khatalink;
USE khatalink;

-- ==================
-- TABLE 1: customers
-- ==================
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unique_id VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15) NOT NULL,
    password VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    full_address TEXT DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    upi_id VARCHAR(100) DEFAULT NULL,
    gst_number VARCHAR(20) DEFAULT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    verification_token VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =====================
-- TABLE 2: shop_owners
-- =====================
CREATE TABLE shop_owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    shop_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    shop_category VARCHAR(100) NOT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    profile_image VARCHAR(255) DEFAULT NULL,
    full_address TEXT DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    upi_id VARCHAR(100) DEFAULT NULL,
    rzp_account_id VARCHAR(100) DEFAULT NULL, -- New field for Razorpay Connected Account ID
    is_online TINYINT(1) DEFAULT 1,
    open_time TIME DEFAULT '09:00:00',
    close_time TIME DEFAULT '21:00:00',
    override_until DATETIME DEFAULT NULL,
    gst_number VARCHAR(20) DEFAULT NULL,
    verification_token VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ==========================
-- TABLE 2a: delivery_partners
-- ==========================
CREATE TABLE IF NOT EXISTS delivery_partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15) NOT NULL,
    password VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    aadhaar_photo VARCHAR(255) NOT NULL, -- Mandatory Aadhaar card photo
    full_address TEXT DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_verified TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ========================
-- TABLE 3: orders
-- ========================
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    shop_id INT NOT NULL,
    delivery_boy_id INT DEFAULT NULL, -- Jo final approve karega
    order_status ENUM('pending', 'accepted', 'assigned', 'picked_up', 'delivered', 'cancelled') DEFAULT 'pending',
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_to_shop DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_mode ENUM('COD', 'Online') DEFAULT 'COD',
    pincode VARCHAR(10) DEFAULT NULL, -- Location matching ke liye
    delivery_name VARCHAR(100) DEFAULT NULL,
    delivery_phone VARCHAR(15) DEFAULT NULL,
    delivery_email VARCHAR(100) DEFAULT NULL,
    delivery_district VARCHAR(100) DEFAULT NULL,
    delivery_block VARCHAR(100) DEFAULT NULL,
    delivery_village VARCHAR(100) DEFAULT NULL,
    delivery_apartment_house VARCHAR(255) DEFAULT NULL,
    pickup_code VARCHAR(6) DEFAULT NULL, -- Shop to Delivery Boy
    delivery_code VARCHAR(6) DEFAULT NULL, -- Customer to Delivery Boy (DCC)
    handover_code VARCHAR(6) DEFAULT NULL, -- Delivery Boy to Shop (SCC)
    code_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE
);

-- ========================
-- TABLE 3: shop_customers
-- (Shop aur Customer link)
-- ========================
CREATE TABLE shop_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    show_gst TINYINT(1) DEFAULT 0,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- 2. Order Line Items (Hybrid: Inventory + Custom)
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT DEFAULT NULL, -- Null if it's a manual/custom item
    item_name VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    price_per_unit DECIMAL(10,2) DEFAULT 0.00,
    total_price DECIMAL(10,2) DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);



-- ======================
-- TABLE 4: shop_fields
-- (Har shop ke custom fields)
-- ======================
CREATE TABLE shop_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT DEFAULT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- ========================
-- TABLE 5: udhar_entries
-- (Har baar ka naya udhar)
-- ========================
CREATE TABLE udhar_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_remaining DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_percentage DECIMAL(5,2) DEFAULT 0,
    entry_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('open','closed') DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- =======================
-- 3. Delivery Assignments Tracking (Accept/Transfer Logic)
-- =======================
CREATE TABLE IF NOT EXISTS delivery_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    delivery_boy_id INT NOT NULL,
    assignment_status ENUM('pending', 'accepted', 'rejected_transferred') DEFAULT 'pending',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME DEFAULT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_boy_id) REFERENCES delivery_partners(id) ON DELETE CASCADE
);

-- =======================
-- 4. Delivery Boy Wallet & Cash Handling
-- =======================
CREATE TABLE IF NOT EXISTS delivery_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_boy_id INT NOT NULL,
    order_id INT NOT NULL,
    cash_collected DECIMAL(10,2) DEFAULT 0.00, -- Full order value from customer
    commission_earned DECIMAL(10,2) DEFAULT 0.00, -- Delivery fee part
    net_payable_to_shop DECIMAL(10,2) DEFAULT 0.00, -- Amount to return to shop
    is_handed_over TINYINT(1) DEFAULT 0, -- Status of SCC verification
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_boy_id) REFERENCES delivery_partners(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- =======================
-- TABLE 6: udhar_items
-- (Kya kya liya field wise)
-- =======================
CREATE TABLE udhar_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    field_id INT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entry_id) REFERENCES udhar_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES shop_fields(id) ON DELETE CASCADE
);

-- ==========================
-- TABLE 7: payment_history
-- ==========================
CREATE TABLE payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    remaining_after DECIMAL(10,2) NOT NULL,
    payment_mode VARCHAR(50) DEFAULT 'Cash',
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entry_id) REFERENCES udhar_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- =================
-- TABLE 8: reports
-- =================
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    entry_id INT NOT NULL,
    message TEXT NOT NULL,
    reply TEXT DEFAULT NULL,
    replied_at DATETIME DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (entry_id) REFERENCES udhar_entries(id) ON DELETE CASCADE
);

-- =================
-- TABLE 9: expenses
-- =================
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE
);

-- =================
-- TABLE 10: visitors
-- =================
CREATE TABLE IF NOT EXISTS visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_date DATE NOT NULL,
    visit_time DATETIME DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    page VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ========================
-- OPTIMIZATION INDEXES (For 1Cr+ Records)
-- ========================

-- For faster login and search
CREATE INDEX idx_order_pincode ON orders(pincode);
CREATE INDEX idx_order_status ON orders(order_status);
CREATE INDEX idx_assignment_status ON delivery_assignments(assignment_status);
CREATE INDEX idx_ledger_handover ON delivery_ledger(is_handed_over);
CREATE INDEX idx_customers_search ON customers(name, email, unique_id);
CREATE INDEX idx_shop_search ON shop_owners(shop_name, email);

-- For faster customer listing and udhar tracking
CREATE INDEX idx_shop_cust_lookup ON shop_customers(shop_id, customer_id);
CREATE INDEX idx_udhar_lookup ON udhar_entries(shop_id, customer_id, status);
CREATE INDEX idx_udhar_items_entry ON udhar_items(entry_id);

-- For faster payment tracking
CREATE INDEX idx_payments_lookup ON payment_history(shop_id, customer_id, payment_date);
CREATE INDEX idx_expenses_lookup ON expenses(shop_id, expense_date);

-- For visitor analytics optimization
CREATE INDEX idx_visitors_lookup ON visitors(visit_date, ip_address, page);

-- ==========================
-- TABLE 11: payment_modes
-- ==========================
CREATE TABLE IF NOT EXISTS payment_modes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    icon TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1
);

-- ==========================
-- TABLE 12: payment_requests
-- ==========================
CREATE TABLE IF NOT EXISTS payment_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_mode VARCHAR(50) NOT NULL,
    screenshot VARCHAR(255) DEFAULT NULL,
    razorpay_order_id VARCHAR(100) DEFAULT NULL,
    razorpay_payment_id VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    is_settled_manually TINYINT(1) DEFAULT 0, -- For admin to mark if transferred manually
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- ==========================
-- TABLE 13: inventory_products
-- ==========================
CREATE TABLE IF NOT EXISTS inventory_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    primary_unit VARCHAR(20) DEFAULT 'NOS',
    sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_included TINYINT(1) DEFAULT 1,
    opening_stock DECIMAL(10,2) DEFAULT 0,
    low_stock_alert DECIMAL(10,2) DEFAULT 0,
    current_stock DECIMAL(10,2) DEFAULT 0,
    hsn_code VARCHAR(20) DEFAULT NULL,
    gst_percent DECIMAL(5,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE
);

-- Seed initial icons with SVGs from payment.php
INSERT INTO payment_modes (name, icon) VALUES 
('Cash', '<svg class="h-5 w-auto inline-block align-middle" viewBox="0 0 122.88 110.58" xmlns="http://www.w3.org/2000/svg"><path d="M27.49,100.48V71.36h13.1c5.55,0.99,11.1,4.01,16.65,7.5h10.17c4.61,0.28,7.02,4.94,2.54,8.01c-3.56,2.62-8.27,2.47-13.1,2.04c-3.33-0.17-3.47,4.3,0,4.32c1.21,0.09,2.52-0.19,3.66-0.19c6.02-0.01,10.98-1.16,14.01-5.91l1.52-3.56l15.13-7.5c7.57-2.49,12.96,5.42,7.37,10.94c-10.96,7.97-22.2,14.53-33.69,19.83c-8.35,5.08-16.7,4.9-25.04,0L27.49,100.48z M38.07,35.01l48.75,12.5l-7.13,26.6l-48.75-12.5L38.07,35.01z M60.99,46.74c4.33,1.16,6.89,5.59,5.73,9.92c-1.16,4.33-5.6,6.89-9.92,5.73c-4.33-1.16-6.89-5.59-5.73-9.92C52.22,48.16,56.68,45.59,60.99,46.74z M45.54,41.35l31.6,7.91c-0.58,2.16,0.71,4.4,2.88,4.98l-2.81,10.52c-2.16-0.58-4.4,0.71-4.98,2.88l-31.61-7.91c0.58-2.16-0.71-4.4-2.88-4.98l2.81-10.52C42.72,44.81,44.96,43.52,45.54,41.35z M122.88,42.02H98.67V7.18h24.21V42.02z M94.72,10.11v29.11h-13.1c-5.55-0.99-11.1-4.01-16.65-7.5H54.8c-4.6-0.28-7.02-4.94-2.54-8.01c3.56-2.62,8.27-2.47,13.1-2.03c3.33,0.17,3.47-4.31,0-4.32c-1.21-0.09-2.52,0.19-3.66,0.19c-6.02,0.01-10.98,1.16-14.01,5.91l-1.52,3.56l-15.13,7.5c-7.57,2.49-12.96-5.42-7.37-10.94C34.63,15.6,45.86,9.04,57.36,3.74c8.35-5.08,16.7-4.9,25.04,0L94.72,10.11z M0,68.57h23.55v34.84H0V68.57z"/></svg>'),
('PhonePe', '<svg class="h-5 w-auto inline-block align-middle" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><circle cx="-25.926" cy="41.954" r="29.873" fill="#5f259f" transform="rotate(-76.714 -48.435 5.641) scale(8.56802)"/><path d="M372.164 189.203c0-10.008-8.576-18.593-18.584-18.593h-34.323l-78.638-90.084c-7.154-8.577-18.592-11.439-30.03-8.577l-27.17 8.577c-4.292 1.43-5.723 7.154-2.862 10.007l85.8 81.508H136.236c-4.293 0-7.154 2.861-7.154 7.154v14.292c0 10.016 8.585 18.592 18.592 18.592h20.015v68.639c0 51.476 27.17 81.499 72.931 81.499 14.292 0 25.739-1.431 40.03-7.146v45.753c0 12.87 10.016 22.886 22.885 22.886h20.015c4.293 0 8.577-4.293 8.577-8.586V210.648h32.893c4.292 0 7.145-2.861 7.145-7.145v-14.3zM280.65 312.17c-8.576 4.292-20.015 5.723-28.591 5.723-22.886 0-34.324-11.438-34.324-37.176v-68.639h62.915v100.092z" fill="#fff" fill-rule="nonzero"/></svg>'),
('GPay', '<svg class="h-5 w-auto inline-block align-middle" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(.6782 0 0 .6782 1.226 120.628)"><path d="M552 0H200C90 0 0 90 0 200s90 200 200 200h352c110 0 200-90 200-200S662 0 552 0z" fill="#fff" fill-rule="nonzero"/><path d="M552 16.2c24.7 0 48.7 4.9 71.3 14.5 21.9 9.3 41.5 22.6 58.5 39.5 16.9 16.9 30.2 36.6 39.5 58.5 9.6 22.6 14.5 46.6 14.5 71.3 0 24.7-4.9 48.7-14.5 71.3-9.3 21.9-22.6 41.5-39.5 58.5-16.9 16.9-36.6 30.2-58.5 39.5-22.6 9.6-46.6 14.5-71.3 14.5H200c-24.7 0-48.7-4.9-71.3-14.5-21.9-9.3-41.5-22.6-58.5-39.5-16.9-16.9-30.2-36.6-39.5-58.5-9.6-22.6-14.5-46.6-14.5-71.3 0-24.7 4.9-48.7 14.5-71.3 9.3-21.9 22.6-41.5 39.5-58.5 16.9-16.9 36.6-30.2 58.5-39.5 22.6-9.6 46.6-14.5 71.3-14.5h352M552 0H200C90 0 0 90 0 200s90 200 200 200h352c110 0 200-90 200-200S662 0 552 0z" fill="#3c4043" fill-rule="nonzero"/><g fill-rule="nonzero"><g fill="#3c4043"><path d="M358.6 214.1v60.6h-19.2V125.3h50.9c12.9 0 23.9 4.3 32.9 12.9 9.2 8.6 13.8 19.1 13.8 31.5 0 12.7-4.6 23.2-13.8 31.7-8.9 8.5-19.9 12.7-32.9 12.7h-31.7zm0-70.4v52.1h32.1c7.6 0 14-2.6 19-7.7 5.1-5.1 7.7-11.3 7.7-18.3 0-6.9-2.6-13-7.7-18.1-5-5.3-11.3-7.9-19-7.9h-32.1v-.1zM487.2 169.1c14.2 0 25.4 3.8 33.6 11.4 8.2 7.6 12.3 18 12.3 31.2v63h-18.3v-14.2h-.8c-7.9 11.7-18.5 17.5-31.7 17.5-11.3 0-20.7-3.3-28.3-10-7.6-6.7-11.4-15-11.4-25 0-10.6 4-19 12-25.2 8-6.3 18.7-9.4 32-9.4 11.4 0 20.8 2.1 28.1 6.3v-4.4c0-6.7-2.6-12.3-7.9-17-5.3-4.7-11.5-7-18.6-7-10.7 0-19.2 4.5-25.4 13.6l-16.9-10.6c9.3-13.5 23.1-20.2 41.3-20.2zm-24.8 74.2c0 5 2.1 9.2 6.4 12.5 4.2 3.3 9.2 5 14.9 5 8.1 0 15.3-3 21.6-9 6.3-6 9.5-13 9.5-21.1-6-4.7-14.3-7.1-25-7.1-7.8 0-14.3 1.9-19.5 5.6-5.3 3.9-7.9 8.6-7.9 14.1zM637.5 172.4l-64 147.2h-19.8l23.8-51.5-42.2-95.7h20.9l30.4 73.4h.4l29.6-73.4h20.9z"/></g><path d="M282.23 202c0-6.26-.56-12.25-1.6-18.01h-80.48v33l46.35.01c-1.88 10.98-7.93 20.34-17.2 26.58v21.41h27.59c16.11-14.91 25.34-36.95 25.34-62.99z" fill="#4285f4"/><path d="M229.31 243.58c-7.68 5.18-17.57 8.21-29.14 8.21-22.35 0-41.31-15.06-48.1-35.36h-28.46v22.08c14.1 27.98 43.08 47.18 76.56 47.18 23.14 0 42.58-7.61 56.73-20.71l-27.59-21.4z" fill="#34a853"/><path d="M149.39 200.05c0-5.7.95-11.21 2.68-16.39v-22.08h-28.46c-5.83 11.57-9.11 24.63-9.11 38.47s3.29 26.9 9.11 38.47l28.46-22.08a51.657 51.657 0 01-2.68-16.39z" fill="#fabb05"/><path d="M200.17 148.3c12.63 0 23.94 4.35 32.87 12.85l24.45-24.43c-14.85-13.83-34.21-22.32-57.32-22.32-33.47 0-62.46 19.2-76.56 47.18l28.46 22.08c6.79-20.3 25.75-35.36 48.1-35.36z" fill="#e94235"/></g></g></svg>'),
('Paytm', '<img src="../assets/svgs/paytm.svg" style="height:1rem;width:auto;vertical-align:middle;">'),
('UPI', '<svg class="h-5 w-auto inline-block align-middle" viewBox="0 0 122.88 45.88" xmlns="http://www.w3.org/2000/svg"><g><polygon fill="#0E8635" points="114.56,0.06 122.88,16.61 105.38,33.17 107.46,25.66 117.03,16.61 112.48,7.56 114.56,0.06"/><polygon fill="#E97208" points="108.71,0.06 117.03,16.61 99.52,33.17 108.71,0.06"/><path fill="#66686C" d="M1.28,39.45h0.97l-0.9,3.75c-0.13,0.56-0.11,0.98,0.08,1.26c0.18,0.28,0.53,0.42,1.03,0.42 c0.5,0,0.9-0.14,1.22-0.42c0.32-0.28,0.54-0.7,0.68-1.26l0.9-3.75h0.98L5.31,43.3c-0.2,0.84-0.56,1.46-1.07,1.88 c-0.51,0.42-1.18,0.62-2.01,0.62c-0.83,0-1.4-0.21-1.71-0.62c-0.31-0.41-0.36-1.04-0.16-1.88L1.28,39.45z M94.04,33.03 h-6.58L96.61,0h6.58L94.04,33.03z M39.34,30.96c-0.36,1.3-1.56,2.22-2.91,2.22H2.5c-0.93,0-1.62-0.32-2.07-0.94 c-0.45-0.63-0.55-1.41-0.28-2.34L8.42,0.09l6.58,0L7.62,26.72h26.33l7.39-26.63l6.58,0L39.34,30.96z M90.63,1.04c-0.45-0.63-1.16-0.94-2.11-0.94l-36.17,0l-1.78,6.48l32.9,0l-1.92,6.91H55.22v-0.02h-6.58l-5.46,19.72l6.58,0 l3.66-13.23h29.58c0.93,0,1.8-0.31,2.61-0.94c0.81-0.63,1.35-1.41,1.6-2.34l3.66-13.23C91.17,2.46,91.08,1.67,90.63,1.04z M117.8,45.63l1.48-6.18h3.36l-0.2,0.85h-2.38l-0.37,1.55h2.38l-0.21,0.88h-2.38l-0.48,2h2.38l-0.21,0.9 H117.8z"/></g></svg>'),
('Card', '<svg class="h-5 w-auto inline-block align-middle" viewBox="0 0 512 385.414" xmlns="http://www.w3.org/2000/svg"><path fill="#3B95D9" d="M26.217 0h382.258c14.366 0 26.16 11.803 26.16 26.158V264.76c0 14.364-11.796 26.16-26.16 26.16H26.217c-14.384 0-26.16-11.776-26.16-26.16V26.158C.057 11.798 11.859 0 26.217 0z"/><path fill="#42A6F1" d="M26.216 7.674h382.26c10.166 0 18.484 8.356 18.484 18.484v238.603c0 10.128-8.356 18.483-18.484 18.483H26.216c-10.128 0-18.483-8.317-18.483-18.483V26.158c0-10.166 8.317-18.484 18.483-18.484z"/><path fill="#4D5471" d="M0 56.192h434.691v74.811H0z"/><path fill="#D54C3D" d="M103.585 94.494H485.84c7.197 0 13.737 2.948 18.471 7.682l.47.515c4.467 4.71 7.219 11.051 7.219 17.961v238.602c0 14.364-11.796 26.16-26.16 26.16H103.585c-14.383 0-26.16-11.777-26.16-26.16V120.652c0-7.167 2.939-13.697 7.679-18.449l.049-.048c4.749-4.728 11.273-7.661 18.432-7.661z"/><path fill="#ED5444" d="M103.585 102.168H485.84c10.167 0 18.484 8.356 18.484 18.484v238.602c0 10.128-8.356 18.484-18.484 18.484H103.585c-10.128 0-18.484-8.317-18.484-18.484V120.652c0-10.167 8.317-18.484 18.484-18.484z"/><path fill="#F8D14A" d="M126.406 283.827a8.33 8.33 0 110-16.661h167.77a8.33 8.33 0 010 16.661h-167.77zm242.263-26.394c12.433 0 23.464 5.995 30.363 15.254 6.9-9.259 17.932-15.254 30.367-15.254 20.9 0 37.845 16.944 37.845 37.844 0 20.902-16.945 37.846-37.845 37.846-12.435 0-23.467-5.997-30.367-15.256-6.899 9.259-17.93 15.256-30.363 15.256-20.903 0-37.846-16.944-37.846-37.846 0-20.9 16.943-37.844 37.846-37.844zm-242.263 65.959a8.331 8.331 0 010-16.661h126.509a8.332 8.332 0 010 16.661H126.406z"/><path fill="#DACD71" d="M139.602 153.639h56.914c7.258 0 13.197 5.939 13.197 13.197v2.883h-83.307v-2.883c0-7.258 5.938-13.197 13.196-13.197zm70.111 20.621v28.134h-25.844V174.26h25.844zm-30.384 28.134h-22.568V174.26h22.568v28.134zm-27.109 0h-25.814V174.26h25.814v28.134zm57.493 4.541v2.928c0 7.257-5.94 13.196-13.197 13.196h-56.914c-7.257 0-13.196-5.938-13.196-13.196v-2.928h83.307z"/></svg>');

-- ==========================
-- TABLE 14: bonds
-- ==========================
CREATE TABLE IF NOT EXISTS bonds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL, -- Total Bond Amount
    initial_paid DECIMAL(10,2) DEFAULT 0, -- Down payment at creation
    paid_amount DECIMAL(10,2) DEFAULT 0, -- Accumulated Payments
    installment_count INT DEFAULT 1,
    due_date DATE NOT NULL,
    nominee_name VARCHAR(100) NOT NULL,
    nominee_phone VARCHAR(15) NOT NULL,
    customer_signature VARCHAR(255) DEFAULT NULL,
    nominee_signature VARCHAR(255) DEFAULT NULL,
    repayment_type ENUM('one-time', 'installments') DEFAULT 'one-time',
    status ENUM('pending', 'active', 'overdue', 'closed') DEFAULT 'pending',
    terms TEXT,
    fine_amount DECIMAL(10,2) DEFAULT 0, -- Fines managed only within bond system
    last_fine_date DATE DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

-- ==========================
-- TABLE 14a: bond_payments
-- (Separate from main ledger payment history)
-- ==========================
CREATE TABLE IF NOT EXISTS bond_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bond_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    razorpay_order_id VARCHAR(100) DEFAULT NULL,
    razorpay_payment_id VARCHAR(100) DEFAULT NULL,
    payment_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending', -- Status of Razorpay payment
    is_settled_manually TINYINT(1) DEFAULT 0, -- For admin to mark if transferred manually
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bond_id) REFERENCES bonds(id) ON DELETE CASCADE
);

-- Optimization for High-Scale Bond tracking
CREATE INDEX idx_bonds_lookup ON bonds(shop_id, customer_id, status);
CREATE INDEX idx_bond_payments_lookup ON bond_payments(bond_id, payment_date);

-- ==========================
-- TABLE 15: bond_warnings
-- ==========================
CREATE TABLE IF NOT EXISTS bond_warnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bond_id INT NOT NULL,
    warning_number INT NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bond_id) REFERENCES bonds(id) ON DELETE CASCADE
);

-- ==========================
-- TABLE 16: monthly_khata (New Feature)
-- ==========================
CREATE TABLE IF NOT EXISTS monthly_khata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    customer_id INT NOT NULL,
    start_date DATE NOT NULL,
    status ENUM('open', 'closed') DEFAULT 'open',
    total_amount DECIMAL(10,2) DEFAULT 0,
    paid_amount DECIMAL(10,2) DEFAULT 0,
    is_settled_manually TINYINT(1) DEFAULT 0, -- For admin to mark if transferred manually
    razorpay_order_id VARCHAR(100) DEFAULT NULL,
    razorpay_payment_id VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS monthly_khata_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    khata_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    item_date DATE NOT NULL,
    FOREIGN KEY (khata_id) REFERENCES monthly_khata(id) ON DELETE CASCADE
);

-- ==========================
-- TABLE 17: user_fcm_tokens (Notification Engine)
-- ==========================
CREATE TABLE IF NOT EXISTS user_fcm_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('shop', 'customer', 'delivery') NOT NULL,
    fcm_token TEXT NOT NULL,
    device_type ENUM('web', 'android', 'ios') DEFAULT 'web',
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Fast Lookup Indexes
    INDEX idx_user_lookup (user_id, user_type), 
    INDEX idx_token_type (user_type),
    
    -- Security: Token duplicates na hon isliye Unique constraint on token content ka logic logic backend sambhalega
    -- kyuki TEXT field pe direct UNIQUE constraint indexing limits ki wajah se nahi lagate.
    UNIQUE KEY uniq_user_token (user_id, user_type, device_type)
);

-- ==========================
-- TABLE 18: shop_ratings
-- ==========================
CREATE TABLE IF NOT EXISTS shop_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    shop_id INT NOT NULL,
    order_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shop_owners(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_order_rating (order_id)
);