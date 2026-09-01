from flask import Flask, jsonify
from preprocessing import load_csv_data
from lstm_model import run_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next
import os

app = Flask(__name__)

@app.route('/')
def home():
    return {"status": "success", "message": "AICS Predictive DSS API is running!"}, 200

@app.route('/api/forecast', methods=['GET'])
def get_forecast():
    df = load_csv_data()
    
    # LSTM forecast
    lstm_forecast = run_lstm()
    
    # Random Forest pattern analysis
    cause_patterns = analyze_patterns()
    
    # Regression trend forecast
    trend_forecast = forecast_next()
    
    response = {
        'random_forest': cause_patterns,
        'lstm': lstm_forecast,
        'trend_forecast': trend_forecast
    }
    
    return jsonify(response)

if __name__ == '__main__':
    port = int(os.environ.get("PORT", 5000))
    app.run(host='0.0.0.0', port=port)