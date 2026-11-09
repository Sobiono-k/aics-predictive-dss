
import os
import pandas as pd
from sqlalchemy import create_engine
from config import Config


# =========================================================
# SETTINGS
# =========================================================

ALLOW_CSV_FALLBACK = False

CSV_PATH = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    "aics_sample_data.csv"
)

_engine = create_engine(
    Config.DATABASE_URL,
    connect_args=(
        {"ssl": {"ssl_mode": "REQUIRED"}}
        if Config.DB_HOST not in ("localhost", "127.0.0.1")
        else {}
    ),
    pool_pre_ping=True,
    pool_recycle=3600
)


def get_engine():
    return _engine


# =========================================================
# DATA LOADING
# =========================================================

def load_csv_data():

    df = None
    source = None

    # -----------------------------------------------------
    # 1. LOAD FROM DATABASE
    # -----------------------------------------------------

    try:
        query = "SELECT * FROM aics_sample_data"

        df = pd.read_sql(query, con=_engine)

        if df.empty:
            raise ValueError("Aiven database returned no records.")

        source = "Aiven MySQL"

        print(
            f"[DATA] Successfully loaded {len(df)} records "
            f"from Aiven MySQL."
        )

    except Exception as db_err:

        print(f"[DATABASE ERROR] {db_err}")

        # -------------------------------------------------
        # 2. OPTIONAL CSV FALLBACK
        # -------------------------------------------------

        if not ALLOW_CSV_FALLBACK:
            raise RuntimeError(
                "Unable to load current AICS records from "
                "Aiven MySQL. CSV fallback is disabled."
            ) from db_err

        if not os.path.exists(CSV_PATH):
            raise FileNotFoundError(
                f"CSV fallback not found: {CSV_PATH}"
            )

        df = pd.read_csv(
            CSV_PATH,
            encoding="utf-8",
            sep=None,
            engine="python",
            comment="#",
            on_bad_lines="skip"
        )

        source = "Local CSV fallback"

        print(
            f"[WARNING] Using CSV fallback: {len(df)} records."
        )

    # -----------------------------------------------------
    # 3. CLEAN DATA
    # -----------------------------------------------------

    if df.empty:
        raise ValueError("AICS dataset is empty.")

    df.columns = (
        df.columns.astype(str)
        .str.strip()
        .str.replace('"', '', regex=False)
        .str.replace("'", '', regex=False)
    )

    # Prioritize exact request_date.
    if "request_date" in df.columns:
        date_col = "request_date"
    else:
        candidates = [
            col for col in df.columns
            if "date" in col.lower()
        ]

        if not candidates:
            raise ValueError(
                "No valid request_date column found."
            )

        if len(candidates) > 1:
            raise ValueError(
                f"Multiple date columns found: {candidates}. "
                "Please explicitly identify the request date."
            )

        date_col = candidates[0]

    if date_col != "request_date":
        df = df.rename(
            columns={date_col: "request_date"}
        )

    df["request_date"] = pd.to_datetime(
        df["request_date"],
        errors="coerce"
    )

    df = df.dropna(
        subset=["request_date"]
    )

    today = pd.Timestamp.today().normalize()

    df = df[
        (df["request_date"] >= pd.Timestamp("2022-09-01"))
        & (df["request_date"] <= today)
    ].copy()

    if df.empty:
        raise ValueError(
            "No valid AICS records remain after date filtering."
        )

    # Optional columns.
    for col in ["medical_cause", "assistance_type"]:
        if col not in df.columns:
            df[col] = "Unknown"

        df[col] = df[col].fillna("Unknown")

    df = df.sort_values(
        "request_date"
    ).reset_index(drop=True)

    # Attach data-source metadata.
    df.attrs["data_source"] = source
    df.attrs["latest_record_date"] = (
        df["request_date"].max().strftime("%Y-%m-%d")
    )

    print(f"[DATA] Source: {source}")
    print(f"[DATA] Records: {len(df)}")
    print(
        f"[DATA] Latest record: "
        f"{df.attrs['latest_record_date']}"
    )

    return df


# =========================================================
# CONTINUOUS TIME SERIES
# =========================================================

def create_time_series(df, freq="D"):

    if df.empty:
        return pd.DataFrame(
            columns=["request_date", "request_count"]
        )

    ts = (
        df.groupby(
            pd.Grouper(
                key="request_date",
                freq=freq
            )
        )
        .size()
        .rename("request_count")
    )

    full_range = pd.date_range(
        start=ts.index.min(),
        end=ts.index.max(),
        freq=freq
    )

    ts = ts.reindex(
        full_range,
        fill_value=0
    )

    return (
        ts.rename_axis("request_date")
        .reset_index()
        .rename(columns={0: "request_count"})
    )


# =========================================================
# MONTHLY SERIES FOR LSTM
# =========================================================

def monthly_series():

    df = load_csv_data()

    if df.empty:
        return pd.Series(dtype=float)

    monthly = (
        df.groupby(
            pd.Grouper(
                key="request_date",
                freq="MS"
            )
        )
        .size()
        .astype(float)
    )

    full_range = pd.date_range(
        start=monthly.index.min(),
        end=monthly.index.max(),
        freq="MS"
    )

    monthly = monthly.reindex(
        full_range,
        fill_value=0
    )

    monthly.name = "request_count"

    return monthly


# =========================================================
# YEARLY SERIES
# =========================================================

def yearly_series():

    df = load_csv_data()

    if df.empty:
        return pd.Series(dtype=float)

    yearly = (
        df.groupby(
            pd.Grouper(
                key="request_date",
                freq="YS"
            )
        )
        .size()
        .astype(float)
    )

    return yearly


# =========================================================
# TESTING
# =========================================================

if __name__ == "__main__":

    print("\n--- AICS PREPROCESSING PIPELINE ---\n")

    data = load_csv_data()

    print("\nRecords loaded:", len(data))
    print("Data source:", data.attrs.get("data_source"))
    print(
        "Latest record:",
        data.attrs.get("latest_record_date")
    )

    print("\nLast 10 daily records:")
    print(create_time_series(data, "D").tail(10))

    print("\nMonthly totals:")
    print(monthly_series().tail(12))

    print("\nYearly totals:")
    print(yearly_series())
