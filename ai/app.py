import sys
import os
import re
from fastapi import FastAPI, Depends
from sqlalchemy.orm import Session
from pydantic import BaseModel
from sqlalchemy.exc import OperationalError

# Add the project root to the Python path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

from ai.services.database import get_db
from ai.services import query_engine
from ai.services import intent_router
# Initialize the FastAPI app
app = FastAPI(
    title="KhataLink AI Analytics Service",
    description="This service provides AI-powered data analytics and conversational business intelligence for the KhataLink platform.",
    version="1.1.0"
)

@app.get("/")
def read_root():
    """A simple root endpoint to confirm the service is running."""
    return {"message": "Welcome to the KhataLink AI Analytics Service. We are live."}

@app.get("/api/health")
def health_check(db: Session = Depends(get_db)):
    """ 
    Provides a health check for the service, including database connectivity.
    """
    db_status = "ok" 
    try:
        # Run a simple query to check the database connection
        db.execute("SELECT 1")
    except OperationalError:
        db_status = "error"

    return {
        "status": "ok", 
        "service": "khatalink-ai",
        "database_connection": db_status
    }

class AnalysisRequest(BaseModel):
    """Request model for the analysis endpoint."""
    question: str
    shop_id: int | None = None # Optional: for shop-specific queries in the future

@app.post("/api/analyze")
def analyze_data(request: AnalysisRequest, db: Session = Depends(get_db)):
    """
    Analyzes a natural language question and returns business insights.
    """
    question = request.question.lower().strip()
    shop_id = request.shop_id

    answer = "I'm not sure how to answer that yet. Please try asking about 'total sales'."
    intent = "unknown"
    response_data = None
    visualization = None
    
    # --- Intent Detection (Simple version) ---
    if "total sales" in question or "total revenue" in question: # TOTAL SALES
        intent = "total_sales"
        total_sales_figure = get_total_sales(db, shop_id=shop_id)
        answer = f"The total sales for your shop are ₹{total_sales_figure:,.2f}."
        response_data = {
            "value": total_sales_figure
        }
    elif "monthly sales" in question or "monthly revenue" in question or "sales by month" in question or "month wise sales" in question: # MONTHLY SALES
        intent = "monthly_sales"
        try:
            monthly_sales_data = get_monthly_sales(db, shop_id=shop_id)
            answer = "Here is your monthly sales data for the last 12 months."
            response_data = { "months": monthly_sales_data }
            visualization = {
                "type": "line",
                "title": "Monthly Sales",
                "x_key": "month",
                "y_key": "sales"
            }
        except Exception as e:
            answer = "Sorry, I encountered an error while fetching your monthly sales data."
            # In a real scenario, we would log the error `e`
            return {"success": False, "answer": answer}
    elif "top product" in question or "best selling" in question or "sold the most" in question: # TOP PRODUCTS
        intent = "top_products"
        limit = 10 # Default limit
        
        # Check for a user-specified limit
        limit_match = re.search(r'top (\d+)', question)
        if limit_match:
            limit = int(limit_match.group(1))

        try:
            top_products_data = get_top_products(db, shop_id=shop_id, limit=limit)
            answer = f"Here are your top {len(top_products_data)} products by revenue."
            response_data = { "products": top_products_data }
            visualization = {
                "type": "bar",
                "title": "Top Products by Revenue",
                "x_key": "product_name",
                "y_key": "revenue"
            }
        except Exception as e:
            answer = "Sorry, I encountered an error while fetching your top products data."
            return {"success": False, "answer": answer}
    elif "total customer" in question or "how many customer" in question or "kitne customer" in question: # TOTAL CUSTOMERS
        intent = "total_customers"
        try:
            total_customers_count = get_total_customers(db, shop_id=shop_id)
            answer = f"You have a total of {total_customers_count} customers associated with your shop."
            response_data = { "value": total_customers_count }
        except Exception as e:
            answer = "Sorry, I couldn't retrieve the total customer count."
            return {"success": False, "answer": answer}
    elif "list customer" in question or "show my customer" in question or "customer list" in question: # LIST CUSTOMERS
        intent = "customer_list"
        try:
            customer_list_data = get_customer_list(db, shop_id=shop_id)
            answer = f"Here is a list of your customers."
            response_data = { "customers": customer_list_data }
            visualization = {
                "type": "table",
                "title": "Customer List"
            }
        except Exception as e:
            answer = "Sorry, I couldn't fetch your customer list."
            return {"success": False, "answer": answer}


    return {
        "success": True,
        "intent": intent,
        "shop_id": shop_id,
        "answer": answer,
        "data": response_data,
        "visualization": visualization
    }