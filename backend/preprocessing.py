import pandas as pd
import os
import traceback
from sqlalchemy import create_engine
from config import Config

# =========================================================
# DATABASE ENGINE
# =========================================================
# Built once at import time from Config.DATABASE_URL (which itself is
# built from DB_HOST / DB_USER / DB_PASS / DB_NAME / DB_PORT env vars).
_engine = create_engine(
    Config.DATABASE_URL,
    connect_args={"ssl": {"ssl_mode": "REQUIRED"}} if Config.DB_HOST not in ('localhost', '127.0.0.1') else {},
    pool_pre_ping=True,
    pool_recycle=3600
)


def get_engine():
    """
    Returns the shared SQLAlchemy engine used for both the request data
    (via load_csv_data, though that currently reads from CSV) and the
    forecast_cache / training_status tables used by app.py.
    """
    return _engine


# =========================================================
# LOAD DATA FROM LOCAL CSV
# =========================================================

def load_csv_data():
    """
    Loads AICS records directly from the Aiven MySQL database engine,
    with a fallback to the local CSV if database connection fails.
    """
    try:
        # Attempt to load directly from Aiven MySQL Database table
        query = "SELECT * FROM aics_sample_data"
        df = pd.read_sql(query, con=_engine)
        print("Successfully loaded data from Aiven MySQL database!")
        
    except Exception as db_err:
        print(f"Database connection failed: {db_err}. Falling back to local CSV...")
        
        # Fallback to local CSV if database is unreachable
        script_dir = os.path.dirname(os.path.abspath(__file__))
        csv_path = os.path.join(script_dir, 'aics_sample_data.csv')

        if not os.path.exists(csv_path):
            print(f"CSV file not found at: {csv_path}")
            return pd.DataFrame()

        read_kwargs = {
            'sep': None,
            'engine': 'python',
            'comment': '#',
            'on_bad_lines': 'skip'
        }

        try:
            df = pd.read_csv(csv_path, encoding='utf-8', **read_kwargs)
        except (UnicodeDecodeError, Exception):
            df = pd.read_csv(csv_path, encoding='latin1', **read_kwargs)

    # -------------------------------------------------
    # COMMON CLEANING PIPELINE (Applies to both DB & CSV)
    # -------------------------------------------------
    if df.empty:
        print("Dataset is empty.")
        return pd.DataFrame()

    # Clean quotes and whitespace from headers
    df.columns = df.columns.str.strip().str.replace('"', '').str.replace("'", "")
    
    # Find date column dynamically
    actual_date_col = None
    for col in df.columns:
        col_str = str(col).lower()
        if 'request_date' in col_str or 'date' in col_str:
            actual_date_col = col
            break

    if actual_date_col is None:
        print("No request_date column found.")
        return pd.DataFrame()

    df = df.rename(columns={actual_date_col: 'request_date'})

    # Parse dates safely
    df['request_date'] = pd.to_datetime(df['request_date'], errors='coerce')
    df = df.dropna(subset=['request_date'])

    # Keep only realistic years
    df = df[
        (df['request_date'].dt.year >= 2022) &
        (df['request_date'].dt.year <= 2030)
    ]

    # Clean optional columns
    optional_columns = ['medical_cause', 'assistance_type']
    for col in optional_columns:
        if col not in df.columns:
            df[col] = 'Unknown'
        df[col] = df[col].fillna('Unknown')

    df = df.sort_values('request_date')
    return df

# =========================================================
# CREATE TIME SERIES
# =========================================================

def create_time_series(df, freq='D'):
    """
    Create continuous time-series data
    with missing dates filled as zero.
    """

    if df.empty:
        return pd.DataFrame()

    # -------------------------------------------------
    # GROUP BY FREQUENCY
    # -------------------------------------------------
    ts = (
        df.groupby(
            pd.Grouper(
                key='request_date',
                freq=freq
            )
        )
        .size()
        .rename('request_count')
        .to_frame()
    )

    # -------------------------------------------------
    # FILL MISSING DATES
    # -------------------------------------------------
    full_range = pd.date_range(
        start=ts.index.min(),
        end=ts.index.max(),
        freq=freq
    )

    ts = ts.reindex(full_range, fill_value=0)

    # -------------------------------------------------
    # RESET INDEX
    # -------------------------------------------------
    ts = ts.reset_index()

    ts.columns = [
        'request_date',
        'request_count'
    ]

    return ts


# =========================================================
# MONTHLY SERIES FOR LSTM
# =========================================================

def monthly_series():
    """
    Generate monthly request totals
    for LSTM forecasting.
    """

    df = load_csv_data()

    if df.empty:
        return pd.Series(dtype=float)

    # -------------------------------------------------
    # MONTH START FREQUENCY
    # -------------------------------------------------
    ts = (
        df.groupby(
            pd.Grouper(
                key='request_date',
                freq='MS'
            )
        )
        .size()
    )

    return ts.astype(float)


# =========================================================
# YEARLY SERIES
# =========================================================

def yearly_series():
    """
    Generate yearly request totals.
    """

    df = load_csv_data()

    if df.empty:
        return pd.Series(dtype=float)

    ts = (
        df.groupby(
            pd.Grouper(
                key='request_date',
                freq='YS'
            )
        )
        .size()
    )

    return ts.astype(float)


# =========================================================
# TESTING BLOCK
# =========================================================

if __name__ == "__main__":

    print("\n--- AICS PREPROCESSING PIPELINE ---\n")

    data = load_csv_data()

    if not data.empty:

        print(f"Records Loaded : {len(data)}")

        print(
            f"Date Range     : "
            f"{data['request_date'].min()} "
            f"to "
            f"{data['request_date'].max()}"
        )

        # -------------------------------------------------
        # DAILY SERIES
        # -------------------------------------------------
        daily_ts = create_time_series(data)

        print("\nLast 10 Daily Records:")
        print(daily_ts.tail(10))

        # -------------------------------------------------
        # MONTHLY SERIES
        # -------------------------------------------------
        monthly_ts = monthly_series()

        print("\nMonthly Totals:")
        print(monthly_ts.tail())

        # -------------------------------------------------
        # YEARLY SERIES
        # -------------------------------------------------
        yearly_ts = yearly_series()

        print("\nYearly Totals:")
        print(yearly_ts.tail())

    else:
        print("Data loading failed.")