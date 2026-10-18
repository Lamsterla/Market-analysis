#!/usr/bin/env python3
"""
Stock Market Automated System - Yahoo Finance Data Retrieval
Fetches stock price, name, and currency using yfinance with built-in mock fallback for testing.
"""

import os
import sys
import json
import argparse
from datetime import datetime, timezone

# Ensure running under Python 3.12 64-bit where yfinance is installed
try:
    import yfinance as yf
except Exception:
    py312_exe = r"C:\Users\Arun Sah\AppData\Local\Programs\Python\Python312\python.exe"
    if os.path.exists(py312_exe) and sys.executable.lower() != py312_exe.lower():
        import subprocess
        _res = subprocess.run([py312_exe] + sys.argv, check=False)
        sys.exit(_res.returncode)
    yf = None

# Standard curated mock data for automated testing and offline resilience
MOCK_DATABASE = {
    "AAPL": {
        "symbol": "AAPL",
        "name": "Apple Inc.",
        "price": 235.50,
        "currency": "USD",
        "currency_symbol": "$",
        "change": 1.25,
        "change_percent": 0.53
    },
    "MSFT": {
        "symbol": "MSFT",
        "name": "Microsoft Corporation",
        "price": 425.80,
        "currency": "USD",
        "currency_symbol": "$",
        "change": -2.10,
        "change_percent": -0.49
    },
    "GOOGL": {
        "symbol": "GOOGL",
        "name": "Alphabet Inc.",
        "price": 182.40,
        "currency": "USD",
        "currency_symbol": "$",
        "change": 0.85,
        "change_percent": 0.47
    },
    "TSLA": {
        "symbol": "TSLA",
        "name": "Tesla, Inc.",
        "price": 218.90,
        "currency": "USD",
        "currency_symbol": "$",
        "change": 4.30,
        "change_percent": 2.00
    },
    "RELIANCE.NS": {
        "symbol": "RELIANCE.NS",
        "name": "Reliance Industries Limited",
        "price": 2740.25,
        "currency": "INR",
        "currency_symbol": "₹",
        "change": 14.50,
        "change_percent": 0.53
    },
    "TCS.NS": {
        "symbol": "TCS.NS",
        "name": "Tata Consultancy Services Limited",
        "price": 4125.00,
        "currency": "INR",
        "currency_symbol": "₹",
        "change": -12.00,
        "change_percent": -0.29
    },
    "INFY.NS": {
        "symbol": "INFY.NS",
        "name": "Infosys Limited",
        "price": 1890.60,
        "currency": "INR",
        "currency_symbol": "₹",
        "change": 8.75,
        "change_percent": 0.46
    },
    "HDFCBANK.NS": {
        "symbol": "HDFCBANK.NS",
        "name": "HDFC Bank Limited",
        "price": 1680.10,
        "currency": "INR",
        "currency_symbol": "₹",
        "change": -5.40,
        "change_percent": -0.32
    }
}


def get_currency_symbol(currency_code: str) -> str:
    """Return common display symbol for currency code."""
    mapping = {
        "INR": "₹",
        "USD": "$",
        "EUR": "€",
        "GBP": "£",
        "JPY": "¥",
        "CAD": "CA$",
        "AUD": "AU$"
    }
    return mapping.get(currency_code.upper(), currency_code)


def fetch_mock_stock(symbol: str) -> dict:
    """Return mock stock quote for testing."""
    sym_upper = symbol.strip().upper()
    if sym_upper in MOCK_DATABASE:
        data = MOCK_DATABASE[sym_upper].copy()
        data["success"] = True
        data["is_mock"] = True
        data["timestamp"] = datetime.now(timezone.utc).isoformat()
        return data

    return {
        "success": False,
        "symbol": symbol,
        "error": f"Mock data for symbol '{symbol}' not found.",
        "is_mock": True,
        "timestamp": datetime.now(timezone.utc).isoformat()
    }


def fetch_live_stock(symbol: str) -> dict:
    """Fetch live market data using yfinance."""
    sym = symbol.strip().upper()
    try:
        import yfinance as yf
    except ImportError:
        return {
            "success": False,
            "symbol": sym,
            "error": "Python package 'yfinance' is not installed.",
            "timestamp": datetime.now(timezone.utc).isoformat()
        }

    try:
        ticker = yf.Ticker(sym)

        # Retrieve fast info first (low overhead)
        fast_info = getattr(ticker, "fast_info", None)
        price = None
        currency = "USD"

        if fast_info:
            try:
                price = fast_info.get("last_price", None) or fast_info.get("previous_close", None)
                currency = fast_info.get("currency", "USD") or "USD"
            except Exception:
                price = None

        name = sym
        change = 0.0
        change_pct = 0.0

        # If fast info doesn't give price or name, attempt ticker.info
        if price is None:
            info = ticker.info or {}
            price = (
                info.get("currentPrice") or
                info.get("regularMarketPrice") or
                info.get("previousClose") or
                info.get("bid") or
                info.get("ask")
            )
            name = info.get("shortName") or info.get("longName") or sym
            currency = info.get("currency", currency) or currency
            prev_close = info.get("regularMarketPreviousClose") or info.get("previousClose")
            if price and prev_close:
                change = float(price) - float(prev_close)
                change_pct = (change / float(prev_close)) * 100
        else:
            # Try to get shortName gracefully
            try:
                info = ticker.info or {}
                name = info.get("shortName") or info.get("longName") or sym
                prev_close = getattr(fast_info, "previous_close", None) or info.get("previousClose")
                if prev_close and price:
                    change = float(price) - float(prev_close)
                    change_pct = (change / float(prev_close)) * 100
            except Exception:
                name = sym

        # If price is still None, attempt history(period='1d')
        if price is None or price <= 0:
            hist = ticker.history(period="5d")
            if not hist.empty and "Close" in hist.columns:
                price = float(hist["Close"].dropna().iloc[-1])
                if len(hist["Close"]) > 1:
                    prev = float(hist["Close"].dropna().iloc[-2])
                    change = price - prev
                    change_pct = (change / prev) * 100

        if price is None or price <= 0:
            return {
                "success": False,
                "symbol": sym,
                "error": f"Symbol '{sym}' not found or market data unavailable.",
                "timestamp": datetime.now(timezone.utc).isoformat()
            }

        price = round(float(price), 2)
        change = round(float(change), 2)
        change_pct = round(float(change_pct), 2)

        return {
            "success": True,
            "symbol": sym,
            "name": name,
            "price": price,
            "currency": currency.upper(),
            "currency_symbol": get_currency_symbol(currency),
            "change": change,
            "change_percent": change_pct,
            "is_mock": False,
            "timestamp": datetime.now(timezone.utc).isoformat()
        }

    except Exception as e:
        return {
            "success": False,
            "symbol": sym,
            "error": f"Failed to retrieve data for '{sym}': {str(e)}",
            "timestamp": datetime.now(timezone.utc).isoformat()
        }


def main():
    parser = argparse.ArgumentParser(description="Fetch stock market data via yfinance")
    parser.add_argument("symbol", nargs="?", default="", help="Stock ticker symbol (e.g., AAPL, RELIANCE.NS)")
    parser.add_argument("--batch", help="Comma-separated list of symbols to fetch")
    parser.add_argument("--mock", action="store_true", help="Force mock data return for testing")

    args = parser.parse_args()

    if args.batch:
        symbols = [s.strip().upper() for s in args.batch.split(",") if s.strip()]
        results = {}
        for s in symbols:
            if args.mock:
                results[s] = fetch_mock_stock(s)
            else:
                res = fetch_live_stock(s)
                # If live retrieval failed but mock is available, we can provide seamless fallback if enabled
                if not res.get("success") and s in MOCK_DATABASE:
                    mock_res = fetch_mock_stock(s)
                    mock_res["fallback_triggered"] = True
                    results[s] = mock_res
                else:
                    results[s] = res

        print(json.dumps({"success": True, "batch": results}, indent=2))
        return

    symbol = args.symbol.strip().upper()
    if not symbol:
        print(json.dumps({
            "success": False,
            "error": "No stock symbol provided.",
            "timestamp": datetime.now(timezone.utc).isoformat()
        }))
        sys.exit(1)

    if args.mock:
        result = fetch_mock_stock(symbol)
    else:
        result = fetch_live_stock(symbol)
        # If live retrieval failed due to network / rate limit, fallback to mock if available
        if not result.get("success") and symbol in MOCK_DATABASE:
            mock_res = fetch_mock_stock(symbol)
            mock_res["fallback_triggered"] = True
            mock_res["original_error"] = result.get("error")
            result = mock_res

    print(json.dumps(result, indent=2))
    if not result.get("success"):
        sys.exit(1)


if __name__ == "__main__":
    main()
