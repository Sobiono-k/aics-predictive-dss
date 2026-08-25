import json
from regression_model import forecast_next
from lstm_model import train_lstm
from random_forest import analyze_patterns

def get_forecast():
    # Get actual predictions from ML models
    linear_result = forecast_next()
    lstm_result = train_lstm()
    patterns = analyze_patterns()

    result = {
        "linear_prediction": linear_result.get('prediction', 0) if isinstance(linear_result, dict) else linear_result,
        "lstm_prediction": lstm_result,
        "medical_patterns": patterns
    }
    
    # Print as JSON so PHP can read it
    print(json.dumps(result))

if __name__ == "__main__":
    get_forecast()

