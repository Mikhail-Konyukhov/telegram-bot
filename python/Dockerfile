FROM python:3.10-slim

# 1) Оптимальный порядок: сначала NumPy нужной версии
RUN pip install --no-cache-dir "numpy<2"

# 2) Затем другие зависимости (torch, transformers и т.п.)
COPY requirements.txt /app/
RUN pip install --no-cache-dir -r /app/requirements.txt

# Устанавливаем sentencepiece для токенизатора
RUN pip install --no-cache-dir sentencepiece

WORKDIR /app
COPY . /app

# Запуск вашего Uvicorn/FastAPI
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
