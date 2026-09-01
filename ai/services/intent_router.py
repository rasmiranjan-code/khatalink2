import re
from typing import Dict, Any, Tuple

# Define keywords and their weights for each intent
INTENT_KEYWORDS = {
    "total_sales": {
        "total sales": 3, "total revenue": 3, "kitni sale": 2, "kitna sell": 2,
        "sales batao": 1, "sale hui": 1, "meri sales": 1
    },
    "monthly_sales": {
        "monthly sales": 3, "monthly revenue": 3, "sales by month": 3,
        "month wise": 2, "har month": 2, "pichle months": 1
    },
    "top_products": {
        "top product": 3, "best selling": 3, "sold the most": 2,
        "sabse jyada bikne": 2, "best products": 1, "most sold": 1
    },
    "customer_list": {
        "customer list": 3, "customers ki list": 3, "show my customer": 2,
        "mere customer": 2, "mere grahak": 2, "customer ka list": 2,
        "customers batao": 1, "dikha sakte ho": 1
    }
}

def _normalize_text(text: str) -> str:
    """Lowercase, remove punctuation, and normalize whitespace."""
    return re.sub(r'[^\w\s]', '', text.lower()).strip()

def detect_intent(question: str) -> str:
    """
    Detects the user's intent based on a keyword scoring system.
    """
    normalized_question = _normalize_text(question)
    scores = {intent: 0 for intent in INTENT_KEYWORDS}

    for intent, keywords in INTENT_KEYWORDS.items():
        for keyword, weight in keywords.items():
            if keyword in normalized_question:
                scores[intent] += weight

    # Find the intent with the highest score
    best_intent = max(scores, key=scores.get)

    # Return the best intent only if its score is above a threshold
    if scores[best_intent] > 0:
        return best_intent
    else:
        return "unknown"

def extract_entities(question: str, intent: str) -> Dict[str, Any]:
    """
    Extracts entities like 'limit' from the user's question.
    """
    entities = {}
    normalized_question = _normalize_text(question)

    # Extract limit for top_products or customer_list
    if intent in ["top_products", "customer_list"]:
        # Default limit
        entities['limit'] = 10

        # Regex to find numbers after keywords like 'top', 'show', 'list'
        match = re.search(r'(top|show|list|dikhao)\s+(\d+)', normalized_question)
        if match:
            limit = int(match.group(2))
            # Apply safe limit bounds
            entities['limit'] = max(1, min(50, limit))

    return entities

def route(question: str) -> Tuple[str, Dict[str, Any]]:
    """
    Main router function to get intent and entities.
    """
    # 1. Detect the primary intent
    intent = detect_intent(question)

    # 2. If intent is known, extract relevant entities
    entities = {}
    if intent != "unknown":
        entities = extract_entities(question, intent)

    return intent, entities

