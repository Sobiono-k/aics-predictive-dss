import numpy as np
import json
import matplotlib.pyplot as plt

from sklearn.linear_model import LinearRegression
from preprocessing import monthly_series


# =========================
# Trend Forecasting - Linear Regression
# =========================
"""
(Trend Forecasting - Linear Regression):
- Analyzes chronological request volumes.
- Identifies overall upward or downward trend.
- Predicts future request demand using linear projection.
"""


def forecast_next():

    # =========================
    # LOAD DATA
    # =========================
    series = monthly_series()

    if series is None or len(series) < 2:
        return {
            "prediction": 0,
            "slope": 0,
            "trend": "Stable",
            "annual_impact": 0
        }

    values = series.values.astype(float)

    # =========================
    # MODEL INPUT
    # =========================
    X = np.arange(len(values)).reshape(-1, 1)
    y = values

    # =========================
    # TRAIN MODEL
    # =========================
    model = LinearRegression()
    model.fit(X, y)

    slope = float(model.coef_[0])
    prediction = model.predict([[len(values)]])[0]

    # =========================
    # TREND CLASSIFICATION
    # =========================
    if slope > 0.05:
        trend = "Increasing"
    elif slope < -0.05:
        trend = "Decreasing"
    else:
        trend = "Stable"

    # =========================
    # FORECAST IMPACT
    # =========================
    monthly_impact = slope * 30
    annual_impact = slope * 365

    # =========================
    # GRAPH VISUALIZATION
    # =========================
    plt.figure(figsize=(8,5))

    # Actual data
    plt.plot(X, y, label="Actual Requests", marker='o')

    # Regression line
    plt.plot(X, model.predict(X), label="Trend Line", linewidth=2)

    # Future prediction point
    plt.scatter(len(values), prediction, color='red', label="Next Prediction")

    plt.title("Linear Regression - Trend Forecasting")
    plt.xlabel("Time (Days)")
    plt.ylabel("Request Volume")
    plt.legend()
    plt.grid(True)

    plt.tight_layout()
    plt.show()

    # =========================
    # RETURN OUTPUT
    # =========================
    return {
        "prediction": int(round(max(0, prediction))),
        "slope": round(slope, 3),
        "trend": trend,
        "monthly_impact": int(round(monthly_impact)),
        "annual_impact": int(round(annual_impact))
    }


# =========================
# EXECUTION (PHP / API)
# =========================
if __name__ == "__main__":
    try:
        result = forecast_next()
        print(json.dumps(result))
    except Exception:
        print(json.dumps({
            "prediction": 0,
            "slope": 0,
            "trend": "Stable",
            "annual_impact": 0
        }))