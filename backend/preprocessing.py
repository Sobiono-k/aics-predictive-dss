import pandas as pd
from sqlalchemy import create_engine
import os
import traceback

# =========================================================
# DATABASE CONFIGURATION
# =========================================================

DB_HOST = os.environ.get('DB_HOST', 'localhost')
DB_USER = os.environ.get('DB_USER', 'root')
DB_PASS = os.environ.get('DB_PASS', '')
DB_NAME = os.environ.get('DB_NAME', 'aics_dss')
DB_PORT = os.environ.get('DB_PORT', '3306')

DB_CONNECTION = f"mysql+pymysql://{DB_USER}:{DB_PASS}@{DB_HOST}:{DB_PORT}/{DB_NAME}"


def get_engine():
    """Create the SQLAlchemy engine, enabling SSL only when connecting to a remote host."""
    connect_args = {}
    if DB_HOST != 'localhost':
        # PyMySQL enables SSL simply by passing a non-empty ssl dict;
        # an empty dict is enough to trigger "use SSL" without needing a cert file.
        connect_args = {"ssl": {"ssl": True}}
    return create_engine(DB_CONNECTION, connect_args=connect_args)

# =========================================================
# LOAD DATA FROM MYSQL
# =========================================================

def load_csv_data():
    try:
        engine = get_engine()   # ← changed from create_engine(DB_CONNECTION)

        query = """
            SELECT *
            FROM aics_sample_data
            ORDER BY id ASC
        """

        df = pd.read_sql(query, engine)

        # -------------------------------------------------
        # EMPTY DATASET CHECK
        # -------------------------------------------------
        if df.empty:
            print("Dataset is empty.")
            return pd.DataFrame()

        # -------------------------------------------------
        # FIND DATE COLUMN
        # -------------------------------------------------
        actual_date_col = None

        for col in df.columns:
            if 'request_date' in col.lower():
                actual_date_col = col
                break

        if actual_date_col is None:
            print("No request_date column found.")
            return pd.DataFrame()

        # Rename column consistently
        df = df.rename(columns={
            actual_date_col: 'request_date'
        })

        # -------------------------------------------------
        # PARSE DATES SAFELY
        # -------------------------------------------------
        df['request_date'] = pd.to_datetime(
            df['request_date'],
            errors='coerce'
        )

        # Remove invalid dates
        df = df.dropna(subset=['request_date'])

        # -------------------------------------------------
        # KEEP ONLY REALISTIC YEARS
        # -------------------------------------------------
        df = df[
            (df['request_date'].dt.year >= 2022) &
            (df['request_date'].dt.year <= 2030)
        ]

        # -------------------------------------------------
        # CLEAN OPTIONAL COLUMNS
        # -------------------------------------------------
        optional_columns = [
            'medical_cause',
            'assistance_type'
        ]

        for col in optional_columns:
            if col not in df.columns:
                df[col] = 'Unknown'

            df[col] = df[col].fillna('Unknown')

        # -------------------------------------------------
        # SORT DATA
        # -------------------------------------------------
        df = df.sort_values('request_date')

        return df

    except Exception as e:
        import traceback
        print(f"MySQL Connection Error: {e}")
        print(traceback.format_exc())
        raise RuntimeError(f"load_csv_data failed: {e}")


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

