
import os
import sys
import gc
import json
import tempfile
import warnings

# ---------------------------------------------------------
# ENVIRONMENT SETTINGS
# ---------------------------------------------------------

temp_dir = tempfile.gettempdir()

os.environ["TEMP"] = temp_dir
os.environ["TMP"] = temp_dir
os.environ["HOME"] = temp_dir
os.environ["TF_CPP_MIN_LOG_LEVEL"] = "2"
os.environ["TF_ENABLE_ONEDNN_OPTS"] = "0"

import numpy as np
import pandas as pd
import tensorflow as tf

from tensorflow.keras.models import Sequential
from tensorflow.keras.layers import LSTM, Dense, Input

from preprocessing import load_csv_data


# ---------------------------------------------------------
# SHARED HELPERS
# ---------------------------------------------------------

def scale(arr):
    """Min-max normalize values to [0, 1]."""
    mn = float(np.min(arr))
    mx = float(np.max(arr))

    normalized = (arr - mn) / (mx - mn + 1e-8)

    return normalized, mn, mx


def unscale(arr, mn, mx):
    """Reverse min-max scaling and log1p transformation."""
    values = np.asarray(arr, dtype=float)

    log_values = values * (mx - mn) + mn

    return np.maximum(0, np.expm1(log_values))


def make_sequences(values, window):
    """Create sliding-window sequences for LSTM training."""
    X, y = [], []

    for i in range(len(values) - window):
        X.append(values[i:i + window])
        y.append(values[i + window])

    return (
        np.array(X, dtype=np.float32).reshape(-1, window, 1),
        np.array(y, dtype=np.float32)
    )


def build_and_train(
    X_train,
    y_train,
    window,
    units=24,
    epochs=15,
    batch=32
):
    """Build and train the LSTM model."""

    model = Sequential([
        Input(shape=(window, 1)),
        LSTM(units, return_sequences=False),
        Dense(16, activation="relu"),
        Dense(1)
    ])

    model.compile(
        optimizer="adam",
        loss="mae"
    )

    model.fit(
        X_train,
        y_train,
        epochs=epochs,
        batch_size=batch,
        verbose=0,
        shuffle=False
    )

    return model


def forecast_ahead(model, last_window, steps):
    """
    Recursively forecast multiple periods.
    Predictions are generated in normalized log space.
    """

    current = last_window.copy()
    predictions = []

    for _ in range(steps):

        prediction = model.predict(
            current[np.newaxis, :, :],
            verbose=0
        )[0][0]

        prediction = float(prediction)

        predictions.append(prediction)

        current = np.vstack([
            current[1:],
            [[prediction]]
        ])

    return np.array(predictions, dtype=float)


def safe_round(value, decimals=2):
    if value is None:
        return None

    if not np.isfinite(value):
        return None

    return round(float(value), decimals)


def nullpad(values, n_before):
    """Align values with the historical labels."""
    return (
        [None] * n_before
        + [safe_round(value) for value in values]
    )


def month_difference(start, end):
    """Number of monthly steps between two month-start dates."""
    return (
        (end.year - start.year) * 12
        + end.month - start.month
    )


# ---------------------------------------------------------
# DATA LOADING
# ---------------------------------------------------------

def load_base_series():

    df = load_csv_data()

    if df.empty:
        raise ValueError("No AICS records available.")

    if "request_date" not in df.columns:
        raise ValueError("'request_date' column is missing.")

    df = df.copy()

    df["request_date"] = pd.to_datetime(
        df["request_date"],
        errors="coerce"
    )

    df = df.dropna(subset=["request_date"])

    today = pd.Timestamp.today().normalize()

    # Ignore future-dated records.
    df = df[
        (df["request_date"] >= "2022-09-01")
        & (df["request_date"] <= today)
    ]

    if df.empty:
        raise ValueError("No valid AICS records available.")

    max_date = df["request_date"].max()

    print(
        f"Dataset date range: "
        f"{df['request_date'].min():%Y-%m-%d} "
        f"to {max_date:%Y-%m-%d}",
        file=sys.stderr
    )

    days_old = (today - max_date.normalize()).days

    # Warn rather than stop forecasting.
    if days_old > 45:
        warnings.warn(
            f"Dataset may be outdated. Latest record: "
            f"{max_date:%Y-%m-%d} ({days_old} days old). "
            "Update the AICS data for more reliable forecasts."
        )

    daily = (
        df.groupby(
            pd.Grouper(key="request_date", freq="D")
        )
        .size()
        .asfreq("D", fill_value=0)
    )

    return daily


# ---------------------------------------------------------
# WEEKLY / MONTHLY LSTM FORECASTING
# ---------------------------------------------------------

def run_grain(
    daily_series,
    freq,
    window,
    forecast_steps,
    label_fmt,
    units=24,
    epochs=15,
    batch=32
):

    today = pd.Timestamp.today().normalize()

    # Convert daily records into the requested time frequency.
    ts = daily_series.resample(freq).sum().fillna(0)

    # For monthly forecasting, exclude the current incomplete month.
    if freq == "MS":

        current_month = today.to_period("M").to_timestamp()

        ts = ts[ts.index < current_month]

    if len(ts) <= window + 1:

        raise ValueError(
            f"Not enough historical data for {freq} forecasting. "
            f"Required more than {window + 1} periods; "
            f"available: {len(ts)}."
        )

    raw_values = ts.values.astype(float)
    date_index = ts.index

    # Log transformation helps reduce the effect of large count changes.
    log_values = np.log1p(raw_values)

    normalized, mn, mx = scale(log_values)

    X, y = make_sequences(normalized, window)

    if len(X) == 0:
        raise ValueError(
            f"Unable to create training sequences for {freq}."
        )

    # Train the model using all available historical sequences.
    tf.keras.backend.clear_session()

    model = build_and_train(
        X,
        y,
        window,
        units=units,
        epochs=epochs,
        batch=batch
    )

    # Historical fitted predictions.
    fitted_normalized = model.predict(
        X,
        verbose=0
    ).flatten()

    fitted_values = unscale(
        fitted_normalized,
        mn,
        mx
    )

    actual_for_metrics = raw_values[window:]

    mae = float(
        np.mean(
            np.abs(actual_for_metrics - fitted_values)
        )
    )

    rmse = float(
        np.sqrt(
            np.mean(
                (actual_for_metrics - fitted_values) ** 2
            )
        )
    )

    # -----------------------------------------------------
    # DETERMINE FORECAST START
    # -----------------------------------------------------

    last_date = date_index[-1]

    if freq == "MS":

        # The requested forecast starts next calendar month.
        target_start = (
            today.to_period("M") + 1
        ).to_timestamp()

        # Number of months between the last historical month
        # and the requested forecast start.
        steps_to_target = month_difference(
            last_date,
            target_start
        )

        # Generate intermediate predictions if necessary.
        total_steps = steps_to_target + forecast_steps - 1

        future_idx = pd.date_range(
            start=last_date + pd.DateOffset(months=1),
            periods=total_steps,
            freq="MS"
        )

    else:

        # Weekly forecasting continues after the last historical week.
        total_steps = forecast_steps

        future_idx = pd.date_range(
            start=last_date + pd.Timedelta(days=7),
            periods=forecast_steps,
            freq="W-MON"
        )

    # -----------------------------------------------------
    # RECURSIVE FORECAST
    # -----------------------------------------------------

    last_window = normalized[-window:].reshape(
        window,
        1
    )

    all_future_normalized = forecast_ahead(
        model,
        last_window,
        total_steps
    )

    # Convert all predictions back to request counts.
    all_future_values = unscale(
        all_future_normalized,
        mn,
        mx
    )

    # -----------------------------------------------------
    # CONFIDENCE INTERVAL ESTIMATION
    # -----------------------------------------------------

    residuals = y - fitted_normalized

    residual_std = (
        float(np.std(residuals, ddof=1))
        if len(residuals) > 1
        else 0.0
    )

    steps_ahead = np.arange(
        1,
        total_steps + 1
    )

    lower_normalized = (
    all_future_normalized
    - 1.96 * residual_std * np.sqrt(steps_ahead)
    )

    upper_normalized = (
    all_future_normalized
    + 1.96 * residual_std * np.sqrt(steps_ahead)
    )

    all_future_lower = unscale(
        lower_normalized,
        mn,
        mx
    )

    all_future_upper = unscale(
        upper_normalized,
        mn,
        mx
    )

    # -----------------------------------------------------
    # LABELS AND OUTPUT ALIGNMENT
    # -----------------------------------------------------

    hist_labels = date_index.strftime(
        label_fmt
    ).tolist()

    future_labels = future_idx.strftime(
        label_fmt
    ).tolist()

    all_labels = hist_labels + future_labels

    padded_actuals = (
        [safe_round(value) for value in raw_values]
        + [None] * total_steps
    )

    padded_predictions = (
        [None] * window
        + [safe_round(value) for value in fitted_values]
        + [None] * total_steps
    )

    # Include the last historical actual before future estimates.
    combined_forecast = (
        [raw_values[-1]]
        + all_future_values.tolist()
    )

    combined_lower = (
        [raw_values[-1]]
        + all_future_lower.tolist()
    )

    combined_upper = (
        [raw_values[-1]]
        + all_future_upper.tolist()
    )

    # The first future value aligns immediately after the last
    # historical observation.
    forecast_output = nullpad(
        combined_forecast,
        len(raw_values) - 1
    )

    lower_output = nullpad(
        combined_lower,
        len(raw_values) - 1
    )

    upper_output = nullpad(
        combined_upper,
        len(raw_values) - 1
    )

    # -----------------------------------------------------
    # MONTHLY FORECAST METADATA
    # -----------------------------------------------------

    forecast_start = future_idx[0]
    forecast_end = future_idx[-1]

    if freq == "MS":

        # Identify intermediate estimates before the requested
        # six-month forecast window.
        bridge_months = max(
            0,
            steps_to_target - 1
        )

    else:
        bridge_months = 0

    result = {
        "labels": all_labels,
        "actual": padded_actuals,
        "predicted": padded_predictions,
        "forecast": forecast_output,
        "forecast_lower": lower_output,
        "forecast_upper": upper_output,

        "metrics": {
            "mae": safe_round(mae),
            "rmse": safe_round(rmse)
        },

        "forecast_info": {
            "frequency": freq,
            "last_historical_period": last_date.strftime("%Y-%m-%d"),
            "forecast_start": forecast_start.strftime("%Y-%m-%d"),
            "forecast_end": forecast_end.strftime("%Y-%m-%d"),
            "forecast_steps": forecast_steps,
            "bridge_periods": bridge_months,
            "data_as_of": daily_series.index[-1].strftime("%Y-%m-%d")
        }
    }

    if freq == "MS":

        # Separate the requested forecast from bridge predictions.
        result["forecast_info"]["requested_forecast_start"] = (
            target_start.strftime("%Y-%m-%d")
        )

    del model
    tf.keras.backend.clear_session()
    gc.collect()

    return result


# ---------------------------------------------------------
# YEARLY LINEAR TREND
# ---------------------------------------------------------

def run_yearly_trend(
    daily_series,
    forecast_steps=5,
    label_fmt="%Y"
):

    ts = (
        daily_series
        .resample("YS")
        .sum()
        .fillna(0)
    )

    raw_values = ts.values.astype(float)
    date_index = ts.index

    n = len(raw_values)

    if n < 2:

        return {
            "labels": [],
            "actual": [],
            "predicted": [],
            "forecast": [],
            "forecast_lower": [],
            "forecast_upper": [],
            "metrics": {}
        }

    x = np.arange(n, dtype=float)

    slope, intercept = np.polyfit(
        x,
        raw_values,
        1
    )

    fitted_values = slope * x + intercept

    residuals = raw_values - fitted_values

    residual_std = (
        np.std(residuals, ddof=1)
        if n > 2
        else abs(raw_values[-1] - raw_values[0]) * 0.15
    )

    future_x = np.arange(
        n,
        n + forecast_steps,
        dtype=float
    )

    future_values = np.maximum(
        0,
        slope * future_x + intercept
    )

    steps_ahead = np.arange(
        1,
        forecast_steps + 1
    )

    future_lower = np.maximum(
        0,
        future_values
        - 1.96 * residual_std * np.sqrt(steps_ahead)
    )

    future_upper = (
        future_values
        + 1.96 * residual_std * np.sqrt(steps_ahead)
    )

    last_date = date_index[-1]

    future_idx = pd.date_range(
        start=last_date + pd.DateOffset(years=1),
        periods=forecast_steps,
        freq="YS"
    )

    hist_labels = date_index.strftime(
        label_fmt
    ).tolist()

    future_labels = future_idx.strftime(
        label_fmt
    ).tolist()

    all_labels = hist_labels + future_labels

    padded_actuals = (
        [safe_round(value) for value in raw_values]
        + [None] * forecast_steps
    )

    padded_predictions = (
        [safe_round(value) for value in fitted_values]
        + [None] * forecast_steps
    )

    combined_forecast = (
        [raw_values[-1]]
        + future_values.tolist()
    )

    combined_lower = (
        [raw_values[-1]]
        + future_lower.tolist()
    )

    combined_upper = (
        [raw_values[-1]]
        + future_upper.tolist()
    )

    mae = float(
        np.mean(np.abs(residuals))
    )

    rmse = float(
        np.sqrt(np.mean(residuals ** 2))
    )

    return {
        "labels": all_labels,
        "actual": padded_actuals,
        "predicted": padded_predictions,

        "forecast": nullpad(
            combined_forecast,
            n - 1
        ),

        "forecast_lower": nullpad(
            combined_lower,
            n - 1
        ),

        "forecast_upper": nullpad(
            combined_upper,
            n - 1
        ),

        "metrics": {
            "mae": safe_round(mae),
            "rmse": safe_round(rmse)
        }
    }


# ---------------------------------------------------------
# MAIN LSTM EXECUTION
# ---------------------------------------------------------

def train_lstm():

    daily = load_base_series()

    # WEEKLY FORECAST
    weekly = run_grain(
        daily,
        freq="W-MON",
        window=26,
        forecast_steps=26,
        label_fmt="%b %d, %Y",
        units=24,
        epochs=15,
        batch=32
    )

    # MONTHLY FORECAST
    # Automatically forecasts the next six calendar months.
    monthly = run_grain(
        daily,
        freq="MS",
        window=6,
        forecast_steps=6,
        label_fmt="%b %Y",
        units=32,
        epochs=30,
        batch=8
    )

    # YEARLY FORECAST
    yearly = run_yearly_trend(
        daily,
        forecast_steps=5,
        label_fmt="%Y"
    )

    return {
        "weekly": weekly,
        "monthly": monthly,
        "yearly": yearly
    }


run_lstm = train_lstm


# ---------------------------------------------------------
# SCRIPT ENTRY POINT
# ---------------------------------------------------------

if __name__ == "__main__":

    output = train_lstm()

    sys.stdout.write(
        json.dumps(output)
    )

    sys.stdout.flush()

    sys.exit(0)
