from reportlab.pdfgen import canvas
from forecast_service import get_forecast

def generate_report():

    data=get_forecast()

    c=canvas.Canvas("forecast_report.pdf")

    c.drawString(100,750,"AICS Forecast Report")

    c.drawString(100,720,f"Linear Prediction: {data['linear_prediction']}")

    c.drawString(100,700,f"LSTM Prediction: {data['lstm_prediction']}")

    y=650

    for cause,count in data['medical_patterns'].items():

        c.drawString(100,y,f"{cause}: {count}")

        y-=20

    c.save()