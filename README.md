# AICS Predictive Decision Support System

Predictive Decision Support System for the AICS Program of DSWD Batasan Hills: Medical Assistance Planning and Trend Analysis Using Machine Learning.

The system helps staff encode applicants, manage the pending queue, review records, and plan ahead using forecasts of request volume and medical-cause trends.

## Features

- **Applicant intake**: staff encoding portal (`new_applicant.php`) and a public online form reached by QR code (`public_form.php`)
- **Pending queue**: staff review online applicants, then confirm them into official records or remove them
- **Records management**: search, filter, edit, import CSV, and print
- **Dashboard**: KPI cards, geographic demand heatmap, and decision insights
- **Forecast Analysis**:
  - Weekly and monthly request volume forecast (LSTM) with 95% confidence bands
  - Yearly forecast (linear trend extrapolation)
  - Medical cause prediction and hotspot detection (Random Forest)
- **Role-based access**: Admin and Staff

## Architecture

```
Browser
  │
  ▼
PHP frontend (XAMPP locally or Apache/Docker in production)
  │   ├── reads/writes applicant data ──────────────┐
  │   ├── GET  /api/forecast (cached result)        │
  │   └── api_train_proxy.php (SSE progress stream) │
  ▼                                                 ▼
Flask ML API (Render, Gunicorn)               MySQL (Aiven)
  ├── LSTM (TensorFlow/Keras)                   ├── aics_sample_data
  ├── Random Forest (scikit-learn)              ├── pending_applications
  └── trains in a background thread,            ├── users
      saves results to MySQL                    ├── forecast_cache
                                                └── training_status
```

Training takes minutes, so it never runs during a page load. `/api/train` starts a background thread and returns immediately. The result is saved to the `forecast_cache` table. `/api/forecast` only reads that cache, so the dashboard loads fast.

## Project structure

```
aics_predictive_dss/
├── db.php                   # MySQL connection (reads environment variables)
├── backend/                 # Flask ML service
│   ├── app.py               # API routes, background training, cache/status
│   ├── config.py            # Builds DATABASE_URL from environment variables
│   ├── preprocessing.py     # Data loading and cleaning
│   ├── lstm_model.py        # Weekly/monthly LSTM, yearly linear trend
│   ├── random_forest.py     # Cause classification, hotspots, confidence
│   ├── regression_model.py  # Trend forecast
│   ├── forecast_service.py
│   ├── report_generator.py
│   ├── aics_sample_data.csv # Training data source
│   └── requirements.txt
├── frontend/                # PHP pages
│   ├── login.php, login_action.php, auth.php
│   ├── index.php            # Dashboard
│   ├── forecast_analysis.php, api_train_proxy.php
│   ├── new_applicant.php, save_applicant.php
│   ├── public_form.php, submit_public_form.php
│   ├── lookup_applicant.php # Pending applicants
│   ├── records.php, reports.php
│   ├── forgot_password.php, reset_password.php
│   └── sidebar.php, style.css
└── images/
```

## Tech stack

| Layer | Technology |
|---|---|
| Frontend | PHP, HTML/CSS/JS, Chart.js, Leaflet |
| ML service | Python 3.10, Flask, Gunicorn |
| Forecasting | TensorFlow/Keras (LSTM), NumPy |
| Classification | scikit-learn (Random Forest) |
| Data | pandas, SQLAlchemy, PyMySQL |
| Database | MySQL (Aiven in production, MariaDB/XAMPP locally) |
| Hosting | Render (ML API) |

## Setup

### 1. Environment variables

Credentials are read from environment variables. Never hardcode them or commit them.

| Variable | Purpose |
|---|---|
| `DB_HOST` | Database host |
| `DB_PORT` | Database port |
| `DB_USER` | Database user |
| `DB_PASS` | Database password |
| `DB_NAME` | Database name (`aics_dss`) |

For local development, either set these as system environment variables or create a `.env` file in the project root. `db.php` loads it if present. **Add `.env` to `.gitignore`.**

On Render, set them in the service's **Environment** tab. If you reset the database password, update `DB_PASS` everywhere it is set (Render and any PHP host), or the app will fail with "Access denied".

### 2. Database tables

Import your `aics_dss` data, then make sure these helper tables exist:

### 3. Run the ML service locally

```bash
cd backend
python -m venv .venv
# Windows: .venv\Scripts\activate    macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
python app.py        # http://127.0.0.1:5000
```

### 4. Run the PHP frontend locally

1. Place the project in `C:\xampp\htdocs\aics_predictive_dss`
2. Start Apache and MySQL in XAMPP
3. Open `http://localhost/aics_predictive_dss/frontend/login.php`

### 5. Deploy the ML service on Render

- Runtime: Python 3.10
- Start command (run from the `backend` directory):
  ```
  gunicorn app:app --timeout 300 --workers 1 --threads 2
  ```
- Set the environment variables listed above.
- The base URL in `forecast_analysis.php` and `api_train_proxy.php` must match your Render service URL.

## API

| Endpoint | Method | Description |
|---|---|---|
| `/` | GET | Health check |
| `/api/forecast` | GET | Returns the cached forecast (instant). 404 if nothing has been trained yet |
| `/api/train` | POST/GET | Starts training in the background and returns immediately |
| `/api/train/status` | GET | Returns `idle`, `running`, `done`, or `error` plus a message |

## Using the forecast page

1. Open **Forecast Analysis** (Admin only).
2. Click **Train Model**. A modal streams progress through `api_train_proxy.php`.
3. When training finishes, the page reloads with the new forecast.

If no forecast has been trained yet, the page shows a simple fallback projection, not model output. Train the model once to get real predictions.

## How the models work

- **Weekly and monthly volume (LSTM)**: each grain is resampled directly from the raw daily series. Monthly forecasts are not built by summing weekly predictions. The loss function is MAE.
- **Yearly volume**: linear trend extrapolation, because there are only about 5 yearly points, too few for a neural network.
- **Cause classification (Random Forest)**: uses time features (day of week, month, quarter, week of year, weekend flag) and encoded assistance type.
- **Metrics**: MAE is computed on the held-out test portion in the original scale. The 95% band comes from the residual standard deviation. Neither is a guarantee of future accuracy.

The LSTM and Random Forest run independently and are shown together on the dashboard. This is a hybrid setup, not a stacked ensemble.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| "Access denied for user" | `DB_PASS` is stale after a password reset. Update it on every host |
| "No database selected" | `DB_NAME` is not set or is empty |
| "Headers already sent" | Whitespace after a closing `?>` in an included file. Omit the closing tag in `db.php` |
| Training stuck on "Waiting for training to begin…" | A previous run crashed and left `training_status` as `running`. Reset it with `UPDATE training_status SET state='idle' WHERE status_key='training_v1';` |
| Charts show a steady decline | The page is using the PHP fallback, not trained output. Run Train Model |
| `ModuleNotFoundError: No module named 'backend'` | Use `from config import Config`, not `from backend.config import ...` |
| Render worker killed ("out of memory") | Free-tier memory limit. Keep the LSTM small, `n_jobs=1` for Random Forest, and the single Gunicorn worker |

## Security notes

- Some PHP queries still use `real_escape_string` instead of prepared statements. Moving them to prepared statements is on the roadmap.
- Validate input on the server as well as in the browser. Client-side checks can be bypassed.

## Known limitations

- The Render free tier has limited memory and an ephemeral filesystem. Trained results are stored in MySQL so they survive restarts.
- The training data has no `amount` column, so real fund allocation and amount recommendation are not possible yet.
- Forecasts are only as good as the historical data. Short or irregular history, such as the gaps visible in the sample data, limits accuracy.

## Credits

Developed as a capstone project for the AICS Program of DSWD Batasan Hills.
