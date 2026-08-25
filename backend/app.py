from flask import Flask, jsonify
from preprocessing import load_data
from lstm_model import train_lstm
from random_forest import analyze_patterns
from regression_model import forecast_next

app = Flask(__name__)

@app.route('/api/forecast', methods=['GET'])
def get_forecast():
    df = load_data()
    
    # LSTM forecast
    lstm_forecast = train_lstm()
    
    # Random Forest pattern analysis
    cause_patterns = analyze_patterns()
    
    # Regression trend forecast
    trend_forecast = forecast_next()
    
    response = {
        'lstm_forecast': lstm_forecast,
        'cause_patterns': cause_patterns,
        'trend_forecast': trend_forecast
    }
    
    return jsonify(response)

if __name__ == '__main__':
    app.run(debug=True)