# KhataLink AI Analytics Service

This directory contains the Python-based AI service for the KhataLink project. It is designed to run as a separate FastAPI service that communicates with the main PHP application.

## Purpose

The service is responsible for:
- Natural Language Processing (NLP) to understand user queries.
- Data analysis of the MySQL database.
- Machine learning for predictions (e.g., sales forecasting).
- Generating visualizations and diagrams.

## Setup (Windows)

1.  **Create Virtual Environment:**
    ```shell
    python -m venv venv
    ```

2.  **Activate Environment:**
    ```shell
    .\venv\Scripts\activate
    ```

3.  **Install Dependencies:**
    ```shell
    pip install -r requirements.txt
    ```

## Running the Service

From the project root (`khatalink/`), run the following command:

```shell
python -m uvicorn ai.app:app --reload --host 127.0.0.1 --port 8000
```