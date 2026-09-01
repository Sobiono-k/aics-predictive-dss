from flask import Flask, jsonify
from preprocessing import load_csv_data
from lstm_model import run_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next
import os
import json
import traceback

app = Flask(__name__)

CACHE_PATH = os.path.join(os.path.dirname(__file__), 'forecast_cache.json')

@app.route('/')
def home():
    return {"status": "success", "message": "AICS Predictive DSS API is running!"}, 200


@app.route('/api/forecast', methods=['GET'])
def get_forecast():
    """Reads the cached forecast — instant, no training."""
    if not os.path.exists(CACHE_PATH):
        return jsonify({
            "error": "No cached forecast yet. Trigger /api/train first."
        }), 404

    try:
        with open(CACHE_PATH, 'r') as f:
            cached = json.load(f)
        return jsonify(cached), 200
    except Exception as e:
        return jsonify({"error": f"Failed to read cache: {e}"}), 500


@app.route('/api/train', methods=['POST', 'GET'])
def train_forecast():
    """Runs the actual training and saves results to the cache file."""
    try:
        df = load_csv_data()
        lstm_forecast = run_lstm()
        cause_patterns = analyze_patterns()
        trend_forecast = forecast_next()

        response = {
            'random_forest': cause_patterns,
            'lstm': lstm_forecast,
            'trend_forecast': trend_forecast
        }

        # Save to cache so /api/forecast can read it instantly
        with open(CACHE_PATH, 'w') as f:
            json.dump(response, f)

        return jsonify({"status": "success", "message": "Training complete and cached."}), 200

    except Exception as e:
        error_details = traceback.format_exc()
        print(error_details)
        return jsonify({"error": str(e), "traceback": error_details}), 500


if __name__ == '__main__':
    port = int(os.environ.get("PORT", 5000))
    app.run(host='0.0.0.0', port=port) #