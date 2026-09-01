from sqlalchemy.orm import Session
from sqlalchemy import text
from typing import List, Dict

def get_total_sales(db: Session, shop_id: int) -> float:
    """
    Calculates the total sales for a specific shop from the 'orders' and 'pos_bills' tables.

    This function sums the total amount from delivered/completed orders and
    all POS bills to give a comprehensive view of total revenue.
    """
    if not shop_id:
        return 0.0

    try:
        # Query to sum total_amount from 'orders' where status is not 'cancelled'
        orders_sales_query = text("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status != 'cancelled' AND shop_id = :shop_id")
        orders_sales = db.execute(orders_sales_query, {"shop_id": shop_id}).scalar_one()

        # Query to sum final_net_amount from 'pos_bills'
        pos_sales_query = text("SELECT COALESCE(SUM(final_net_amount), 0) FROM pos_bills WHERE shop_id = :shop_id")
        pos_sales = db.execute(pos_sales_query, {"shop_id": shop_id}).scalar_one()

        return float(orders_sales + pos_sales)
    except Exception as e:
        print(f"Error calculating total sales for shop_id {shop_id}: {e}")
        return 0.0

def get_monthly_sales(db: Session, shop_id: int) -> List[Dict]:
    """
    Calculates the total sales for a specific shop for each of the last 12 months.
    """
    if not shop_id:
        return []

    query = text("""
        SELECT
            DATE_FORMAT(sale_date, '%Y-%m') AS month,
            SUM(sale_amount) AS sales
        FROM (
            -- Sales from online/app orders
            SELECT
                created_at AS sale_date,
                total_amount AS sale_amount
            FROM orders
            WHERE
                shop_id = :shop_id
                AND order_status != 'cancelled'
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)

            UNION ALL

            -- Sales from Point-of-Sale
            SELECT
                created_at AS sale_date,
                final_net_amount AS sale_amount
            FROM pos_bills
            WHERE
                shop_id = :shop_id
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        ) AS combined_sales
        GROUP BY month
        ORDER BY month ASC;
    """)

    try:
        result = db.execute(query, {"shop_id": shop_id}).mappings().all()
        return [dict(row) for row in result]
    except Exception as e:
        print(f"Error calculating monthly sales for shop_id {shop_id}: {e}")
        # In case of a query failure, we re-raise to avoid returning misleading empty data
        raise e

def get_top_products(db: Session, shop_id: int, limit: int = 10) -> List[Dict]:
    """
    Calculates the top selling products for a specific shop by revenue.
    It combines sales from both 'orders' and 'pos_bills'.
    """
    if not shop_id:
        return []

    # Ensure limit is within a safe range
    safe_limit = max(1, min(50, limit))

    query = text("""
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            SUM(s.quantity) AS quantity_sold,
            SUM(s.revenue) AS revenue
        FROM (
            -- Sales from online orders
            SELECT
                oi.product_id,
                oi.quantity,
                (oi.quantity * oi.price) AS revenue
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.shop_id = :shop_id AND o.order_status != 'cancelled'

            UNION ALL

            -- Sales from Point-of-Sale
            SELECT
                pbi.product_id,
                pbi.quantity,
                pbi.total_amount AS revenue
            FROM pos_bill_items pbi
            JOIN pos_bills pb ON pbi.pos_bill_id = pb.id
            WHERE pb.shop_id = :shop_id
        ) AS s
        JOIN inventory_products p ON s.product_id = p.id
        WHERE s.product_id IS NOT NULL AND s.revenue > 0
        GROUP BY p.id, p.name, p.image_thumb_path
        ORDER BY revenue DESC, quantity_sold DESC
        LIMIT :limit; -- FIX: Moved LIMIT clause to the end of the main query
    """)

    try:
        result = db.execute(
            query,
            {"shop_id": shop_id, "limit": int(safe_limit)}
        ).mappings().all()
        return [dict(row) for row in result]
    except Exception as e:
        print(f"Error calculating top products for shop_id {shop_id}: {e}")
        raise e

def get_total_customers(db: Session, shop_id: int) -> int:
    """
    Calculates the total number of customers associated with a specific shop.
    """
    if not shop_id:
        return 0

    query = text("SELECT COUNT(customer_id) FROM shop_customers WHERE shop_id = :shop_id")
    try:
        result = db.execute(query, {"shop_id": shop_id}).scalar_one()
        return int(result)
    except Exception as e:
        print(f"Error calculating total customers for shop_id {shop_id}: {e}")
        raise e

def get_customer_list(db: Session, shop_id: int, limit: int = 25) -> List[Dict]:
    """
    Retrieves a list of customers for a specific shop.
    """
    if not shop_id:
        return []

    safe_limit = max(1, min(100, limit)) # Safe limit between 1 and 100

    query = text("""
        SELECT c.id, c.name, c.unique_id, c.phone
        FROM customers c
        JOIN shop_customers sc ON c.id = sc.customer_id
        WHERE sc.shop_id = :shop_id
        ORDER BY c.name ASC
        LIMIT :limit;
    """)

    try:
        result = db.execute(query, {"shop_id": shop_id, "limit": safe_limit}).mappings().all()
        return [dict(row) for row in result]
    except Exception as e:
        print(f"Error fetching customer list for shop_id {shop_id}: {e}")
        raise e

def get_total_customers(db: Session, shop_id: int) -> int:
    """
    Calculates the total number of customers associated with a specific shop.
    """
    if not shop_id:
        return 0

    query = text("SELECT COUNT(customer_id) FROM shop_customers WHERE shop_id = :shop_id")
    try:
        result = db.execute(query, {"shop_id": shop_id}).scalar_one()
        return int(result)
    except Exception as e:
        print(f"Error calculating total customers for shop_id {shop_id}: {e}")
        raise e

def get_customer_list(db: Session, shop_id: int, limit: int = 25) -> List[Dict]:
    """
    Retrieves a list of customers for a specific shop.
    """
    if not shop_id:
        return []

    safe_limit = max(1, min(100, limit)) # Safe limit between 1 and 100

    query = text("""
        SELECT c.id, c.name, c.unique_id, c.phone
        FROM customers c
        JOIN shop_customers sc ON c.id = sc.customer_id
        WHERE sc.shop_id = :shop_id
        ORDER BY c.name ASC
        LIMIT :limit;
    """)

    try:
        result = db.execute(query, {"shop_id": shop_id, "limit": safe_limit}).mappings().all()
        return [dict(row) for row in result]
    except Exception as e:
        print(f"Error fetching customer list for shop_id {shop_id}: {e}")
        raise e