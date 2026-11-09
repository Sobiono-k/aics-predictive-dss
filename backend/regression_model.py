
import json
import numpy as np
import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt

from sklearn.linear_model import LinearRegression
from preprocessing import monthly_series


WINDOW = 6


def _empty_result():
    return {
        "prediction": 0,
        "slope": 0.0,
        "trend": "Stable",
        "monthly_impact": 0,
        "annual_impact": 0
    }


def forecast_next():
    """Forecast next month's request volume using six-month lag features."""

    series = monthly_series()

    if series is None or len(series) < WINDOW + 2:
        return _empty_result()

    # Keep chronological order and clean the monthly observations.
    series = series.sort_index()
    values = np.asarray(series.values, dtype=float).reshape(-1)

    if not np.all(np.isfinite(values)) or np.any(values < 0):
        raise ValueError(
            "Monthly request counts must be finite and non-negative."
        )

    if len(values) < WINDOW + 2:
        return _empty_result()

    # Create training examples:
    # previous six months -> following month's request count.
    X, y = [], []

    for i in range(WINDOW, len(values)):
        X.append(values[i - WINDOW:i])
        y.append(values[i])

    X = np.asarray(X, dtype=float)
    y = np.asarray(y, dtype=float)

    model = LinearRegression()
    model.fit(X, y)

    # Forecast the month after the latest observed month.
    latest_window = values[-WINDOW:].reshape(1, WINDOW)
    raw_prediction = float(model.predict(latest_window)[0])
    prediction = max(0.0, raw_prediction)

    # Estimate the historical average month-to-month change.
    # This is descriptive, not the lag model's forecast slope.
    changes = np.diff(values)
    slope = float(np.mean(changes)) if len(changes) else 0.0

    average_volume = float(np.mean(values))
    threshold = 0.01 * average_volume

    if slope > threshold:
        trend = "Increasing"
    elif slope < -threshold:
        trend = "Decreasing"
    else:
        trend = "Stable"

    monthly_impact = slope
    annual_impact = slope * 12

    # Plot actual counts and the next-month forecast.
    fig, ax = plt.subplots(figsize=(9, 5))

    try:
        x = np.arange(len(values))

        ax.plot(
            x,
            values,
            marker="o",
            label="Actual Monthly Requests"
        )

        ax.scatter(
            [len(values)],
            [prediction],
            color="red",
            label="Next-Month Forecast",
            zorder=3
        )

        ax.plot(
            [len(values) - 1, len(values)],
            [values[-1], prediction],
            linestyle="--",
            label="Forecast Connection"
        )

        ax.set_title("Linear Regression - Monthly Request Forecast")
        ax.set_xlabel("Month Index (chronological)")
        ax.set_ylabel("Request Volume")
        ax.legend()
        ax.grid(True, alpha=0.3)
        fig.tight_layout()

        # Save the figure here if your application needs a graph file.
    finally:
        plt.close(fig)

    return {
        "prediction": int(round(prediction)),
        "slope": round(slope, 3),
        "trend": trend,
        "monthly_impact": int(round(monthly_impact)),
        "annual_impact": int(round(annual_impact))
    }


if __name__ == "__main__":
    try:
        print(json.dumps(forecast_next()))
    except Exception as exc:
        print(json.dumps({
            **_empty_result(),
            "error": str(exc)
        }))
