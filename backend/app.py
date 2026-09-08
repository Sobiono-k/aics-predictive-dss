from flask import Flask, jsonify
from preprocessing import load_csv_data, get_engine
from lstm_model import run_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next
import os
import json
import traceback
import threading
from datetime import datetime, timedelta
from sqlalchemy import text

app = Flask(__name__)

CACHE_KEY  = "forecast_v1"
STATUS_KEY = "training_v1"

# If a training run has been "running" for longer than this with no update,
# assume the worker crashed (e.g. OOM-killed) and allow a fresh run to start.
STALE_AFTER_MINUTES = 10

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


def get_status() -> dict:
    """
    Returns the current status row, including how many minutes ago it was
    last updated (used to detect a stale/crashed 'running' state).
    """
    engine = get_engine()
    with engine.begin() as conn:
        row = conn.execute(
            text("""
                SELECT state, message, updated_at
                FROM training_status
                WHERE status_key = :key
            """),
            {"key": STATUS_KEY}
        ).fetchone()

    if row is None:
        return {"state": "idle", "message": "", "updated_at": None, "stale": False}

    state, message, updated_at = row[0], row[1], row[2]
    stale = False
    if state == "running" and updated_at is not None:
        # updated_at comes back as a datetime from SQLAlchemy/pymysql
        age = datetime.utcnow() - updated_at
        stale = age > timedelta(minutes=STALE_AFTER_MINUTES)

    return {
        "state": state,
        "message": message,
        "updated_at": updated_at.isoformat() if updated_at else None,
        "stale": stale,
    }


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
        # Always release the lock, even if something above threw before
        # reaching this point, so a crash never permanently blocks retries.
        if _training_lock.locked():
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
    current = get_status()

    # If a previous run is stuck in "running" way past its expected duration,
    # it almost certainly crashed (e.g. OOM-killed) without releasing the
    # lock/status. Force-reset it so a fresh run can proceed.
    if current.get('state') == 'running' and current.get('stale'):
        print(f"Detected stale training lock (state=running, no update for "
              f">{STALE_AFTER_MINUTES}min). Resetting.")
        set_status("idle", "Previous run appeared to crash — auto-reset.")
        if _training_lock.locked():
            try:
                _training_lock.release()
            except RuntimeError:
                pass  # lock wasn't held by this thread/process, ignore
        current = get_status()

    if current.get('state') == 'running':
        return jsonify({
            "status": "already_running",
            "message": current.get('message') or "Training is already in progress."
        }), 202

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
    status = get_status()
    # Don't leak the internal 'stale' flag details to the frontend beyond
    # what it needs — but keep it simple and just pass state/message through.
    return jsonify({"state": status["state"], "message": status["message"]}), 200


if __name__ == '__main__':
    port = int(os.environ.get("PORT", 5000))
    app.run(host='0.0.0.0', port=port)