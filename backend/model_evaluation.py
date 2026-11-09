
import math
import numpy as np
import pandas as pd

from sklearn.ensemble import RandomForestRegressor
from sklearn.linear_model import LinearRegression
from sklearn.metrics import r2_score
from preprocessing import monthly_series


WINDOW = 6
TEST_FRACTION = 0.20
RANDOM_STATE = 42


def calculate_metrics(actual, predicted):
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)

    errors = actual - predicted
    nonzero = actual != 0

    return {
        "mae": float(np.mean(np.abs(errors))),
        "rmse": float(np.sqrt(np.mean(errors ** 2))),

        # R-squared: coefficient of determination.
        "r2": (
            float(r2_score(actual, predicted))
            if len(actual) >= 2
            else None
        ),

        # MAPE is undefined when actual values are zero.
        "mape": (
            float(np.mean(np.abs(errors[nonzero] / actual[nonzero])) * 100)
            if np.any(nonzero) else None
        ),
        "mape_evaluated_periods": int(np.sum(nonzero)),
        "test_periods": int(len(actual)),
    }


def make_lag_features(values, window):
    X, y, target_indices = [], [], []

    for i in range(window, len(values)):
        X.append(values[i - window:i])
        y.append(values[i])
        target_indices.append(i)

    return (
        np.asarray(X, dtype=float),
        np.asarray(y, dtype=float),
        np.asarray(target_indices, dtype=int),
    )


def evaluate_models():
    series = monthly_series()

    if series is None or len(series) < 15:
        raise ValueError(
            "At least 15 monthly observations are required "
            "for this evaluation configuration."
        )

    series = series.sort_index()
    series = pd.to_numeric(series, errors="coerce").fillna(0)
    series = series.asfreq("MS", fill_value=0)

    values = series.to_numpy(dtype=float)

    if not np.all(np.isfinite(values)) or np.any(values < 0):
        raise ValueError("Monthly request counts must be finite and non-negative.")

    n = len(values)
    test_count = max(3, math.ceil(n * TEST_FRACTION))
    split = n - test_count

    if split <= WINDOW + 1:
        raise ValueError("Not enough training months for a six-month window.")

    train_values = values[:split]
    actual_test = values[split:]
    test_dates = series.index[split:].strftime("%Y-%m").tolist()

    # The models below are evaluated on the same held-out months.
    predictions = {}

    # Build six-month lag features once for both regression models.
    X_all, y_all, target_indices = make_lag_features(values, WINDOW)

    train_mask = target_indices < split
    test_mask = target_indices >= split

    # Confirm both models have training and test examples.
    if not np.any(train_mask) or not np.any(test_mask):
        raise ValueError(
            "Not enough lagged observations for training and testing."
        )

    # 1. Linear Regression: previous six months -> next month
    linear_model = LinearRegression()
    linear_model.fit(X_all[train_mask], y_all[train_mask])

    predictions["linear_regression"] = np.maximum(
        0,
        linear_model.predict(X_all[test_mask])
    )

    # 2. Random Forest Regression: same inputs and target months
    rf_model = RandomForestRegressor(
        n_estimators=200,
        min_samples_leaf=2,
        random_state=RANDOM_STATE,
        n_jobs=1,
    )

    rf_model.fit(X_all[train_mask], y_all[train_mask])

    predictions["random_forest"] = np.maximum(
        0,
        rf_model.predict(X_all[test_mask])
    )

    # 3. LSTM: train only on the training period.
    # Log transform and scaling parameters are learned from training data only.
    import tensorflow as tf
    from tensorflow.keras.models import Sequential
    from tensorflow.keras.layers import LSTM, Dense, Input

    tf.keras.utils.set_random_seed(RANDOM_STATE)

    train_log = np.log1p(train_values)
    mn = float(train_log.min())
    mx = float(train_log.max())
    scale_range = mx - mn

    def scale_log(x):
        return (np.log1p(x) - mn) / (scale_range + 1e-8)

    scaled_train = scale_log(train_values)

    X_lstm, y_lstm = [], []
    for i in range(WINDOW, split):
        X_lstm.append(scaled_train[i - WINDOW:i])
        y_lstm.append(scaled_train[i])

    X_lstm = np.asarray(X_lstm, dtype=np.float32).reshape(-1, WINDOW, 1)
    y_lstm = np.asarray(y_lstm, dtype=np.float32)

    tf.keras.backend.clear_session()

    lstm_model = Sequential([
        Input(shape=(WINDOW, 1)),
        LSTM(24),
        Dense(16, activation="relu"),
        Dense(1),
    ])

    lstm_model.compile(optimizer="adam", loss="mae")

    lstm_model.fit(
        X_lstm,
        y_lstm,
        epochs=15,
        batch_size=8,
        verbose=0,
        shuffle=False,
    )

    # One-step-ahead evaluation: each test prediction uses actual prior months.
    lstm_predictions = []

    for i in range(split, n):
        context = values[i - WINDOW:i]
        normalized = scale_log(context).reshape(1, WINDOW, 1)

        pred_scaled = float(
            lstm_model.predict(normalized, verbose=0)[0, 0]
        )

        pred_scaled = np.clip(pred_scaled, 0, 1)

        pred_log = pred_scaled * (scale_range + 1e-8) + mn

        lstm_predictions.append(
            max(0, float(np.expm1(pred_log)))
        )

    predictions["lstm"] = np.asarray(lstm_predictions)

    results = {
        name: calculate_metrics(actual_test, pred)
        for name, pred in predictions.items()
    }

    results["_evaluation"] = {
        "target": "monthly_request_count",
        "frequency": "monthly",
        "training_periods": int(split),
        "test_periods": int(test_count),
        "test_start": test_dates[0],
        "test_end": test_dates[-1],
        "test_dates": test_dates,
        "method": "chronological holdout; one-step-ahead predictions",
    }

    del lstm_model
    tf.keras.backend.clear_session()

    return results


if __name__ == "__main__":
    import json
    print(json.dumps(evaluate_models(), indent=2))
