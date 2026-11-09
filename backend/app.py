
import os
import json
import traceback
import threading
from datetime import datetime, timedelta, timezone

import numpy as np
import pandas as pd

from flask import Flask, jsonify
from sqlalchemy import text

from preprocessing import load_csv_data, get_engine
from lstm_model import run_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next
from model_evaluation import evaluate_models


# =========================================================
# FLASK CONFIGURATION
# =========================================================

app = Flask(__name__)

# New version prevents old cached results from being reused.
CACHE_KEY = "forecast_v2"
STATUS_KEY = "training_v2"

# Allow enough time for TensorFlow and the other models.
STALE_AFTER_MINUTES = 60

# Prevent overlapping training jobs in this Flask process.
_training_lock = threading.Lock()


# =========================================================
# JSON SERIALIZATION
# =========================================================

def json_serializer(value):

    if isinstance(value, (np.integer,)):
        return int(value)

    if isinstance(value, (np.floating,)):
        return float(value)

    if isinstance(value, np.ndarray):
        return value.tolist()

    if isinstance(value, (pd.Timestamp, datetime)):
        return value.isoformat()

    if isinstance(value, np.bool_):
        return bool(value)

    raise TypeError(
        f"Object of type {type(value).__name__} "
        "is not JSON serializable."
    )


# =========================================================
# DATABASE CACHE
# =========================================================

def save_cache(payload):

    engine = get_engine()

    serialized_payload = json.dumps(
        payload,
        default=json_serializer,
        allow_nan=False
    )

    with engine.begin() as conn:

        conn.execute(
            text("""
                INSERT INTO forecast_cache
                    (cache_key, payload)
                VALUES
                    (:key, :payload)
                ON DUPLICATE KEY UPDATE
                    payload = :payload
            """),
            {
                "key": CACHE_KEY,
                "payload": serialized_payload
            }
        )


def load_cache():

    engine = get_engine()

    with engine.begin() as conn:

        row = conn.execute(
            text("""
                SELECT payload
                FROM forecast_cache
                WHERE cache_key = :key
            """),
            {"key": CACHE_KEY}
        ).fetchone()

    if row is None:
        return None

    payload = row[0]

    if isinstance(payload, bytes):
        payload = payload.decode("utf-8")

    if isinstance(payload, str):
        return json.loads(payload)

    return payload


# =========================================================
# TRAINING STATUS
# =========================================================

def set_status(state, message=""):

    engine = get_engine()

    with engine.begin() as conn:

        conn.execute(
            text("""
                INSERT INTO training_status
                    (status_key, state, message, updated_at)
                VALUES
                    (:key, :state, :message, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    state = :state,
                    message = :message,
                    updated_at = CURRENT_TIMESTAMP
            """),
            {
                "key": STATUS_KEY,
                "state": state,
                "message": message
            }
        )


def get_status():

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

        return {
            "state": "idle",
            "message": "",
            "updated_at": None,
            "stale": False
        }

    state, message, updated_at = row

    if isinstance(updated_at, str):

        try:
            updated_at = datetime.fromisoformat(updated_at)
        except (ValueError, TypeError):
            updated_at = None

    stale = False

    if state == "running" and updated_at is not None:

        try:

            now = datetime.now(
                updated_at.tzinfo
            ) if updated_at.tzinfo else datetime.utcnow()

            stale = (
                now - updated_at
            ) > timedelta(minutes=STALE_AFTER_MINUTES)

        except (TypeError, ValueError):
            stale = False

    return {
        "state": state,
        "message": message or "",
        "updated_at": (
            updated_at.isoformat()
            if updated_at else None
        ),
        "stale": stale
    }


# =========================================================
# LSTM OUTPUT VALIDATION
# =========================================================

def validate_lstm_output(lstm_data):

    if not isinstance(lstm_data, dict):
        raise ValueError(
            "LSTM did not return a valid dictionary."
        )

    required_periods = [
        "weekly",
        "monthly",
        "yearly"
    ]

    for period in required_periods:

        data = lstm_data.get(period)

        if not isinstance(data, dict):
            raise ValueError(
                f"LSTM {period} forecast is missing."
            )

        labels = data.get("labels", [])
        actual = data.get("actual", [])
        predicted = data.get("predicted", [])
        forecast = data.get("forecast", [])

        lengths = [
            len(labels),
            len(actual),
            len(predicted),
            len(forecast)
        ]

        if len(set(lengths)) != 1:
            raise ValueError(
                f"{period} forecast arrays do not match: "
                f"labels={lengths[0]}, "
                f"actual={lengths[1]}, "
                f"predicted={lengths[2]}, "
                f"forecast={lengths[3]}"
            )

        if not labels:
            raise ValueError(
                f"LSTM {period} forecast has no labels."
            )

        # Report the number of generated future periods.
        info = data.get("forecast_info", {})

        if period in ("weekly", "monthly"):

            expected = (
                26 if period == "weekly" else 6
            )

            reported = info.get("forecast_steps")

            if reported is not None and reported != expected:
                raise ValueError(
                    f"{period} forecast expected "
                    f"{expected} periods but reports "
                    f"{reported}."
                )

        print(
            f"[LSTM] {period}: "
            f"{len(labels)} labels, "
            f"{len(forecast)} forecast values, "
            f"forecast info={info}"
        )

    return True


# =========================================================
# OPTIONAL MODEL EXECUTION
# =========================================================

def run_optional_model(name, function):

    try:

        print(f"[TRAINING] {name}...")

        result = function()

        print(f"[SUCCESS] {name} completed.")

        return result, None

    except Exception as exc:

        print(
            f"[ERROR] {name} failed:\n"
            f"{traceback.format_exc()}"
        )

        return {}, str(exc)


# =========================================================
# BACKGROUND TRAINING JOB
# =========================================================

def _run_training_job():

    try:

        # -------------------------------------------------
        # STEP 1: LOAD AND VERIFY DATA
        # -------------------------------------------------

        set_status(
            "running",
            "Loading AICS historical data..."
        )

        df = load_csv_data()

        if df.empty:
            raise ValueError(
                "No AICS records are available for training."
            )

        latest_date = df["request_date"].max()

        data_source = df.attrs.get(
            "data_source",
            "Unknown"
        )

        print("\n========== DATA INFORMATION ==========")
        print("Records:", len(df))
        print("Source:", data_source)
        print("Latest record:", latest_date)
        print("======================================\n")

        # -------------------------------------------------
        # STEP 2: TRAIN LSTM
        # -------------------------------------------------

        set_status(
            "running",
            "Training weekly, monthly, and yearly LSTM forecasts..."
        )

        print("[TRAINING] LSTM models...")

        lstm_forecast = run_lstm()

        validate_lstm_output(lstm_forecast)

        print("[SUCCESS] LSTM training completed.")

        # -------------------------------------------------
        # STEP 3: RANDOM FOREST
        # -------------------------------------------------

        set_status(
            "running",
            "Analyzing assistance patterns with Random Forest..."
        )

        cause_patterns, rf_error = run_optional_model(
            "Random Forest",
            analyze_patterns
        )

        # -------------------------------------------------
        # STEP 4: REGRESSION
        # -------------------------------------------------

        set_status(
            "running",
            "Computing regression trend forecast..."
        )

        trend_forecast, trend_error = run_optional_model(
            "Regression forecast",
            forecast_next
        )

        # -------------------------------------------------
        # STEP 5: MODEL EVALUATION
        # -------------------------------------------------

        set_status(
            "running",
            "Evaluating model performance..."
        )

        model_evaluation, evaluation_error = run_optional_model(
            "Model evaluation",
            evaluate_models
        )

        # -------------------------------------------------
        # STEP 6: BUILD API RESPONSE
        # -------------------------------------------------

        generated_at = datetime.now(
            timezone.utc
        ).isoformat()

        response = {

            "random_forest": cause_patterns,

            "lstm": lstm_forecast,

            "trend_forecast": trend_forecast,

            "model_evaluation": model_evaluation,

            "metadata": {
                "generated_at": generated_at,
                "data_source": data_source,
                "latest_record_date": latest_date.strftime(
                    "%Y-%m-%d"
                ),
                "records_used": int(len(df)),
                "cache_version": CACHE_KEY
            },

            "model_errors": {
                "random_forest": rf_error,
                "trend_forecast": trend_error,
                "model_evaluation": evaluation_error
            }
        }

        # -------------------------------------------------
        # STEP 7: SAVE CACHE
        # -------------------------------------------------

        set_status(
            "running",
            "Saving updated forecast results..."
        )

        save_cache(response)

        print("\n========== TRAINING COMPLETE ==========")
        print("LSTM weekly forecast: SUCCESS")
        print("LSTM monthly forecast: SUCCESS")
        print("LSTM yearly forecast: SUCCESS")
        print("Forecast cache updated.")
        print("=======================================\n")

        set_status(
            "done",
            "All LSTM forecasts completed and results saved."
        )

    except Exception as exc:

        error_details = traceback.format_exc()

        print("\n========== TRAINING FAILED ==========")
        print(error_details)
        print("=====================================\n")

        try:
            set_status(
                "error",
                str(exc)[:1000]
            )
        except Exception:
            print(
                "[ERROR] Unable to update training status."
            )

    finally:

        if _training_lock.locked():
            _training_lock.release()


# =========================================================
# API: HOME
# =========================================================

@app.route("/", methods=["GET"])
def home():

    return jsonify({
        "status": "success",
        "message": "AICS Predictive DSS API is running.",
        "cache_version": CACHE_KEY
    }), 200


# =========================================================
# API: GET FORECAST
# =========================================================

@app.route("/api/forecast", methods=["GET"])
def get_forecast():

    try:

        cached = load_cache()

        if cached is None:

            return jsonify({
                "status": "not_ready",
                "error": "No forecast is available yet.",
                "message": "Trigger /api/train first."
            }), 404

        return jsonify(cached), 200

    except Exception as exc:

        traceback.print_exc()

        return jsonify({
            "status": "error",
            "error": f"Failed to read forecast cache: {str(exc)}"
        }), 500


# =========================================================
# API: START TRAINING
# =========================================================

@app.route("/api/train", methods=["POST", "GET"])
def train_forecast():

    try:

        current = get_status()

        # If the status is stale, only reset it when no
        # training thread is actually holding the process lock.
        if (
            current.get("state") == "running"
            and current.get("stale")
            and not _training_lock.locked()
        ):

            set_status(
                "idle",
                "Previous training status was stale. Ready to retry."
            )

            current = get_status()

        if current.get("state") == "running":

            return jsonify({
                "status": "already_running",
                "message": current.get("message")
            }), 202

        acquired = _training_lock.acquire(
            blocking=False
        )

        if not acquired:

            return jsonify({
                "status": "already_running",
                "message": "A training job is already running."
            }), 202

        try:

            set_status(
                "running",
                "Starting model training..."
            )

            thread = threading.Thread(
                target=_run_training_job,
                daemon=True
            )

            thread.start()

        except Exception:

            _training_lock.release()
            raise

        return jsonify({
            "status": "started",
            "message": "Model training started in the background."
        }), 202

    except Exception as exc:

        traceback.print_exc()

        return jsonify({
            "status": "error",
            "error": str(exc)
        }), 500


# =========================================================
# API: TRAINING STATUS
# =========================================================

@app.route("/api/train/status", methods=["GET"])
def train_status():

    try:

        status = get_status()

        return jsonify({
            "state": status["state"],
            "message": status["message"],
            "updated_at": status["updated_at"]
        }), 200

    except Exception as exc:

        traceback.print_exc()

        return jsonify({
            "state": "error",
            "message": str(exc)
        }), 500


# =========================================================
# API: CACHE INFORMATION
# =========================================================

@app.route("/api/forecast/info", methods=["GET"])
def forecast_info():

    try:

        cached = load_cache()

        if cached is None:

            return jsonify({
                "status": "not_ready",
                "message": "No forecast cache available."
            }), 404

        return jsonify({
            "status": "success",
            "metadata": cached.get("metadata", {}),
            "model_errors": cached.get("model_errors", {})
        }), 200

    except Exception as exc:

        return jsonify({
            "status": "error",
            "error": str(exc)
        }), 500


# =========================================================
# APPLICATION ENTRY POINT
# =========================================================

if __name__ == "__main__":

    port = int(
        os.environ.get("PORT", 5000)
    )

    app.run(
        host="0.0.0.0",
        port=port,
        debug=False,
        use_reloader=False
    )
