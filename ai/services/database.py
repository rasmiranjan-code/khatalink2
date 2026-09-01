import os
from dotenv import load_dotenv
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.exc import SQLAlchemyError

# Load environment variables from .env file
load_dotenv()

DB_HOST = os.getenv("DB_HOST")
DB_NAME = os.getenv("DB_NAME")
DB_USER = os.getenv("DB_USER")
DB_PASS = os.getenv("DB_PASS")

DATABASE_URL = f"mysql+mysqlconnector://{DB_USER}:{DB_PASS}@{DB_HOST}/{DB_NAME}"

try:
    engine = create_engine(DATABASE_URL, pool_pre_ping=True)
    SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
    print("Database connection successful.")
except SQLAlchemyError as e:
    print(f"Database connection failed: {e}")
    engine = None
    SessionLocal = None

def get_db():
    """Dependency to get a DB session for each request."""
    if SessionLocal is None:
        raise Exception("Database not connected. Check credentials in .env file.")
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()