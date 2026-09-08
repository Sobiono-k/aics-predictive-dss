import os
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

class Config:
    DATABASE_URL = os.getenv("DATABASE_URL")

    @staticmethod
    def validate():
        if not Config.DATABASE_URL:
            raise ValueError("DATABASE_URL environment variable is not set!")

# Validate on import
Config.validate()