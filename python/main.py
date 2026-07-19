from fastapi import FastAPI
from pydantic import BaseModel
from transformers import pipeline
from typing import List
import torch
import re
import uvicorn
import os

app = FastAPI()


# Выбираем устройство: GPU, если доступен
device = 0 if torch.cuda.is_available() else -1

# Загружаем zero-shot классификатор с учетом устройства
classifier = pipeline(
    "zero-shot-classification",
    model="joeddav/xlm-roberta-large-xnli",
    device=device
)

class InputData(BaseModel):
    text: str  # Пример: "кофе 200, 300 такси"
    categories: List[str] = None  # Список категорий для классификации

class CategoriesData(BaseModel):
    user_id: int
    categories: List[str]

@app.post("/classify")
def classify(data: InputData):
    # Если категории не переданы, используем базовые
    categories = data.categories if data.categories else [
        "еда", "транспорт", "жилье", "одежда", "развлечения", 
        "здоровье", "гигиена", "техника", "коммуналка", "спортпит", 
        "кафе и рестораны", "товары для дома"
    ]
    
    entries = [x.strip() for x in data.text.split(',')]
    results = []

    for entry in entries:
        # Ищем число (цену) и слова (название)
        match = re.search(r'(\d+(?:[.,]\d+)?)', entry)
        if not match:
            continue

        price = match.group(1).replace(',', '.')
        try:
            price = float(price)
        except ValueError:
            continue

        # Удаляем цену из текста — оставляем только название
        name = entry.replace(match.group(1), '').strip()
        if not name:
            continue

        classification = classifier(name, categories)
        results.append({
            "name": name,
            "price": price,
            "category": classification["labels"][0],
            "confidence": round(classification["scores"][0], 3)
        })

    return {"items": results}

@app.get("/health")
def health_check():
    return {"status": "ok", "service": "text-classifier"}

@app.get("/categories/default")
def get_default_categories():
    """Возвращает список категорий по умолчанию"""
    default_categories = [
        "еда", "транспорт", "жилье", "одежда", "развлечения", 
        "здоровье", "гигиена", "техника", "коммуналка", "спортпит", 
        "кафе и рестораны", "товары для дома"
    ]
    return {"categories": default_categories}

# Запускаем сервер, если файл вызывается напрямую
if __name__ == "__main__":
    host = os.getenv("HOST", "0.0.0.0")
    port = int(os.getenv("PORT", "8000"))
    uvicorn.run(app, host=host, port=port)

