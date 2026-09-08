from flask import Flask, jsonify
from preprocessing import load_csv_data, get_engine
from lstm_model import run_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next
import os
import json
import traceback
import threading
from sqlalchemy import text

app = Flask(__name__)

CACHE_KEY  = "forecast_v1"
STATUS_KEY = "training_v1"

# Simple in-process lock so two training runs can't overlap on the same worker
_training_lock = threading.Lock()


def save_cache(payload: dict):
    engine = get_engine()
    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO forecast_cache (cache_key, payload)
                VALUES (:key, :payload)
                ON DUPLICATE KEY UPDATE payload = :payload
            """),
            {"key": CACHE_KEY, "payload": json.dumps(payload)}
        )


def load_cache():
    engine = get_engine()
    with engine.begin() as conn:
        row = conn.execute(
            text("SELECT payload FROM forecast_cache WHERE cache_key = :key"),
            {"key": CACHE_KEY}
        ).fetchone()
    if row is None:
        return None
    return json.loads(row[0])


def set_status(state: str, message: str = ""):
    engine = get_engine()
    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO training_status (status_key, state, message)
                VALUES (:key, :state, :message)
                ON DUPLICATE KEY UPDATE state = :state, message = :message
            """),
            {"key": STATUS_KEY, "state": state, "message": message}
        )


def get_status():
    engine = get_engine()
    with engine.begin() as conn:
        row = conn.execute(
            text("SELECT state, message FROM training_status WHERE status_key = :key"),
            {"key": STATUS_KEY}
        ).fetchone()
    if row is None:
        return {"state": "idle", "message": ""}
    return {"state": row[0], "message": row[1]}


def _run_training_job():
    """Runs in a background thread. Updates status as it goes."""
    try:
        set_status("running", "Loading data…")
        df = load_csv_data()

        set_status("running", "Training LSTM models…")
        lstm_forecast = run_lstm()

        set_status("running", "Training Random Forest…")
        cause_patterns = analyze_patterns()

        set_status("running", "Computing trend forecast…")
        trend_forecast = forecast_next()

        response = {
            'random_forest': cause_patterns,
            'lstm': lstm_forecast,
            'trend_forecast': trend_forecast
        }
        save_cache(response)
        set_status("done", "Training complete.")

    except Exception as e:
        error_details = traceback.format_exc()
        print(error_details)
        set_status("error", str(e))
    finally:
        _training_lock.release()


@app.route('/')
def home():
    return {"status": "success", "message": "AICS Predictive DSS API is running!"}, 200


@app.route('/api/forecast', methods=['GET'])
def get_forecast():
    """Reads the cached forecast from MySQL — instant, no training."""
    try:
        cached = load_cache()
    except Exception as e:
        return jsonify({"error": f"Failed to read cache: {e}"}), 500

    if cached is None:
        return jsonify({"error": "No cached forecast yet. Trigger /api/train first."}), 404

    return jsonify(cached), 200


@app.route('/api/train', methods=['POST', 'GET'])
def train_forecast():
    """Kicks off training in the background and returns immediately."""
    acquired = _training_lock.acquire(blocking=False)
    if not acquired:
        return jsonify({"status": "already_running", "message": "Training is already in progress."}), 202

    set_status("running", "Starting…")
    thread = threading.Thread(target=_run_training_job, daemon=True)
    thread.start()

    return jsonify({"status": "started", "message": "Training started in the background."}), 202


@app.route('/api/train/status', methods=['GET'])
def train_status():
    """Frontend polls this to know when training finishes."""
    return jsonify(get_status()), 200


if __name__ == '__main__':
    port = int(os.environ.get("PORT", 5000))
    app.run(host='0.0.0.0', port=port)