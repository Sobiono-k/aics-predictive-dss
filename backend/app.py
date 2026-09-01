from flask import Flask, jsonify
from preprocessing import load_csv_data
from lstm_model import run_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next
import os
import traceback

app = Flask(__name__)

@app.route('/')
def home():
    return {"status": "success", "message": "AICS Predictive DSS API is running!"}, 200

@app.route('/api/forecast', methods=['GET'])
def get_forecast():
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
        return jsonify(response), 200
    except Exception as e:
        error_details = traceback.format_exc()
        print(error_details)  # Prints to Render Logs
        return jsonify({"error": str(e), "traceback": error_details}), 500

if __name__ == '__main__':
    port = int(os.environ.get("PORT", 5000))
    app.run(host='0.0.0.0', port=port)