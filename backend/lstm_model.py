import os
import tempfile

# Set cache/temp directory safely cross-platform
if os.name == 'nt':
    os.environ['USERPROFILE'] = r'C:\Windows\Temp'
    os.environ['HOME']        = r'C:\Windows\Temp'
else:
    os.environ['HOME']        = tempfile.gettempdir()

os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'
os.environ['TF_ENABLE_ONEDNN_OPTS']= '0'

import sys
import gc
import json
import numpy as np
import pandas as pd
import tensorflow as tf
from tensorflow.keras.models import Sequential
from tensorflow.keras.layers import LSTM, Dense, Input

from preprocessing import load_csv_data

# ─────────────────────────────────────────────────────────────────
# SHARED HELPERS
# ─────────────────────────────────────────────────────────────────

def scale(arr):
    """Min-max normalise to [0,1]. Returns (normalised, min, max)."""
    mn, mx = arr.min(), arr.max()
    return (arr - mn) / (mx - mn + 1e-8), mn, mx

def unscale(arr, mn, mx):
    """Reverse min-max normalisation then undo log1p."""
    return np.expm1(arr * (mx - mn) + mn)

def make_sequences(values, window):
    X, y = [], []
    for i in range(len(values) - window):
        X.append(values[i : i + window])
        y.append(values[i + window])
    return np.array(X, dtype=np.float32), np.array(y, dtype=np.float32)

def build_and_train(X_train, y_train, window, units=24, epochs=15, batch=32):
    """
    Single-layer LSTM tuned for small sample sizes and cloud resource constraints.
    """
    model = Sequential([
        Input(shape=(window, 1)),
        LSTM(units, return_sequences=False),
        Dense(16, activation='relu'),
        Dense(1),
    ])
    model.compile(optimizer='adam', loss='mae')
    model.fit(X_train, y_train,
              epochs=epochs, batch_size=batch,
              verbose=0, validation_split=0.1)
    return model

def forecast_ahead(model, last_window, steps):
    """
    Autoregressively forecast `steps` periods forward in normalized space.
    """
    current = last_window.copy()          # shape (window, 1)
    preds   = []
    for _ in range(steps):
        p = model.predict(current[np.newaxis, :, :], verbose=0)[0][0]
        p = float(np.clip(p, 0.0, 1.0))
        preds.append(p)
        current = np.vstack([current[1:], [[p]]])
    return np.array(preds)

def safe_round(v, d=2):
    return round(float(v), d) if (v is not None and not np.isnan(v)) else None

def nullpad(lst, n_before):
    """Prepend n_before Nones so forecast aligns on the combined label axis."""
    return [None] * n_before + [safe_round(v) for v in lst]


# ─────────────────────────────────────────────────────────────────
# DATA LOADING (Filtered Sep 2022 – May 2026)
# ─────────────────────────────────────────────────────────────────

def load_base_series():
    """
    Load CSV, parse dates, return daily DatetimeIndex Series strictly
    filtered from September 1, 2022 through May 31, 2026.
    """
    df = load_csv_data()

    if 'request_date' not in df.columns:
        raise ValueError(
            f"'request_date' column missing. Actual columns: {df.columns.tolist()}."
        )

    df['request_date'] = pd.to_datetime(df['request_date'])

    # Explicit date filtering: Sep 2022 to May 2026
    df = df[(df['request_date'] >= '2022-09-01') & (df['request_date'] <= '2026-05-31')]

    daily = (df.groupby(pd.Grouper(key='request_date', freq='D'))
               .size()
               .asfreq('D', fill_value=0))
    return daily


# ─────────────────────────────────────────────────────────────────
# PER-GRAIN PIPELINE
# ─────────────────────────────────────────────────────────────────

def run_grain(daily_series, freq, window, forecast_steps, label_fmt, units=24, epochs=15, batch=16):
    """
    Resample → log1p → scale → sequence → train → in-sample fit → forecast.
    """
    if freq == 'W-MON':
        ts = daily_series.resample('W-MON').sum()
    elif freq == 'MS':
        ts = daily_series.resample('MS').sum()
    elif freq == 'YS':
        ts = daily_series.resample('YS').sum()
    else:
        raise ValueError(f"Unknown freq: {freq}")

    ts = ts.fillna(0)
    raw_values  = ts.values.astype(float)
    date_index  = ts.index

    # Log1p + scale
    log_vals          = np.log1p(raw_values)
    norm_vals, mn, mx = scale(log_vals)
    norm_vals         = norm_vals.reshape(-1, 1)

    n = len(norm_vals)
    if n <= window + 1:
        raise ValueError(
            f"[{freq}] Only {n} periods — need at least {window + 2}."
        )

    # Sequences
    X, y = make_sequences(norm_vals, window)
    split = max(1, int(len(X) * 0.85))
    X_train, y_train = X[:split], y[:split]

    # Train
    model = build_and_train(X_train, y_train, window, units=units, epochs=epochs, batch=batch)

    # In-sample predictions
    pred_norm = model.predict(X, verbose=0).flatten()
    pred_norm = np.clip(pred_norm, 0.0, 1.0)

    y_flat = y.flatten() 
    actual_vals = unscale(y_flat, mn, mx)
    pred_vals   = unscale(pred_norm, mn, mx)

    # Metrics
    test_residuals = actual_vals[split:] - pred_vals[split:]
    mae = float(np.mean(np.abs(test_residuals)))

    norm_test_residuals = y_flat[split:] - pred_norm[split:]
    if len(norm_test_residuals) > 1:
        norm_std = np.std(norm_test_residuals, ddof=1)
    else:
        norm_std = np.std(y_flat - pred_norm, ddof=1) if len(y_flat) > 1 else np.std(norm_vals)

    # Forecast
    last_window       = norm_vals[-window:]
    future_norm_preds = forecast_ahead(model, last_window, forecast_steps)

    lower_norm_bounds = np.clip(future_norm_preds - (1.96 * norm_std), 0.0, 1.0)
    upper_norm_bounds = np.clip(future_norm_preds + (1.96 * norm_std), 0.0, 1.0)

    future_vals    = np.maximum(0, unscale(future_norm_preds, mn, mx))
    forecast_lower = np.maximum(0, unscale(lower_norm_bounds, mn, mx))
    forecast_upper = unscale(upper_norm_bounds, mn, mx)

    moe = float(np.mean((forecast_upper - forecast_lower) / 2))

    # Future date labels
    last_date = date_index[-1]
    if freq == 'W-MON':
        future_idx = pd.date_range(start=last_date + pd.DateOffset(weeks=1),
                                   periods=forecast_steps, freq='W-MON')
    elif freq == 'MS':
        future_idx = pd.date_range(start=last_date + pd.DateOffset(months=1),
                                   periods=forecast_steps, freq='MS')

    hist_labels   = date_index.strftime(label_fmt).tolist()
    future_labels = future_idx.strftime(label_fmt).tolist()
    all_labels    = hist_labels + future_labels

    padded_actuals = [safe_round(v) for v in raw_values.tolist()] + [None] * forecast_steps
    padded_preds   = [None] * window + [safe_round(v) for v in pred_vals.tolist()] + [None] * forecast_steps

    last_actual_val   = raw_values[-1]
    combined_forecast = [last_actual_val] + future_vals.tolist()
    combined_lower    = [last_actual_val] + forecast_lower.tolist()
    combined_upper    = [last_actual_val] + forecast_upper.tolist()

    n_hist_offset = len(hist_labels) - 1

    result = {
        "labels":         all_labels,
        "actual":         padded_actuals,
        "predicted":      padded_preds,
        "forecast":       nullpad(combined_forecast, n_hist_offset),
        "forecast_lower": nullpad(combined_lower,    n_hist_offset),
        "forecast_upper": nullpad(combined_upper,    n_hist_offset),
        "metrics": {
            "mae":                safe_round(mae),
            "margin_of_error_95": safe_round(moe),
        },
    }

    # Memory Cleanup
    del model, X, y, X_train, y_train
    del pred_norm, y_flat, actual_vals, pred_vals
    tf.keras.backend.clear_session()
    gc.collect()

    return result


def run_yearly_trend(daily_series, forecast_steps=5, label_fmt='%Y'):
    ts = daily_series.resample('YS').sum().fillna(0)
    raw_values = ts.values.astype(float)
    date_index = ts.index
    n = len(raw_values)

    if n < 2:
        return {"labels": [], "actual": [], "predicted": [], "forecast": [], "metrics": {}}

    x = np.arange(n, dtype=float)
    slope, intercept = np.polyfit(x, raw_values, 1)
    fitted_vals = slope * x + intercept
    residuals = raw_values - fitted_vals
    resid_std = np.std(residuals, ddof=1) if n > 2 else abs(raw_values[-1] - raw_values[0]) * 0.15

    future_x = np.arange(n, n + forecast_steps, dtype=float)
    future_vals = np.maximum(0, slope * future_x + intercept)

    steps_ahead = np.arange(1, forecast_steps + 1)
    forecast_lower = np.maximum(0, future_vals - 1.96 * resid_std * np.sqrt(steps_ahead))
    forecast_upper = future_vals + 1.96 * resid_std * np.sqrt(steps_ahead)

    mae = float(np.mean(np.abs(residuals)))
    moe = float(np.mean(forecast_upper - future_vals))

    last_date = date_index[-1]
    future_idx = pd.date_range(start=last_date + pd.DateOffset(years=1),
                               periods=forecast_steps, freq='YS')
    hist_labels   = date_index.strftime(label_fmt).tolist()
    future_labels = future_idx.strftime(label_fmt).tolist()
    all_labels    = hist_labels + future_labels

    padded_actuals = [safe_round(v) for v in raw_values.tolist()] + [None] * forecast_steps
    padded_preds   = [safe_round(v) for v in fitted_vals.tolist()] + [None] * forecast_steps

    last_actual_val = raw_values[-1]
    combined_forecast = [last_actual_val] + future_vals.tolist()
    combined_lower    = [last_actual_val] + forecast_lower.tolist()
    combined_upper    = [last_actual_val] + forecast_upper.tolist()

    return {
        "labels":         all_labels,
        "actual":         padded_actuals,
        "predicted":      padded_preds,
        "forecast":       nullpad(combined_forecast, n - 1),
        "forecast_lower": nullpad(combined_lower,    n - 1),
        "forecast_upper": nullpad(combined_upper,    n - 1),
        "metrics": {
            "mae":                safe_round(mae),
            "margin_of_error_95": safe_round(moe),
        },
    }


def train_lstm():
    daily = load_base_series()

    # WEEKLY
    weekly = run_grain(
        daily,
        freq           = 'W-MON',
        window         = 26,
        forecast_steps = 26,
        label_fmt      = '%b %d, %Y',
        units          = 24,
        epochs         = 15,
        batch          = 32
    )

    # MONTHLY (Sep 2022 – May 2026 base -> 12-Month Outlook starting June 2026)
    monthly = run_grain(
        daily,
        freq           = 'MS',
        window         = 6,         # Reduced from 12 to capture momentum without oversaturating small sample
        forecast_steps = 12,        # 12-month forward projection
        label_fmt      = '%b %Y',
        units          = 32,        # Slightly more capacity to prevent low predictions
        epochs         = 30,        # Additional training iterations for 45 monthly steps
        batch          = 8
    )

    # YEARLY
    yearly = run_yearly_trend(daily, forecast_steps=5, label_fmt='%Y')

    return {
        "weekly":  weekly,
        "monthly": monthly,
        "yearly":  yearly,
    }

run_lstm = train_lstm

if __name__ == "__main__":
    output = train_lstm()
    sys.stdout.write(json.dumps(output))
    sys.stdout.flush()
    sys.exit(0)