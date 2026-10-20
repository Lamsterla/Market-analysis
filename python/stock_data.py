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


def compute_technical_indicators(df, requested_period="30d"):
    """
    Compute SMA20, SMA50, RSI(14), Historical Volatility, and Rule-based Outlook
    from a pandas DataFrame with datetime index and 'Close' column.
    """
    import numpy as np
    import pandas as pd

    if df.empty or "Close" not in df.columns or len(df["Close"].dropna()) < 5:
        return None

    close = df["Close"].dropna()
    total_points = len(close)

    # 1. Moving Averages
    sma20_series = close.rolling(window=20, min_periods=1).mean()
    sma50_series = close.rolling(window=50, min_periods=1).mean()

    # 2. RSI (14-period)
    delta = close.diff()
    gain = delta.clip(lower=0)
    loss = -1 * delta.clip(upper=0)
    avg_gain = gain.rolling(window=14, min_periods=14).mean()
    avg_loss = loss.rolling(window=14, min_periods=14).mean()
    rs = avg_gain / avg_loss.replace(0, np.nan)
    rsi_series = 100 - (100 / (1 + rs))

    # 3. Volatility (Annualized standard deviation of daily returns)
    daily_returns = close.pct_change()
    if len(daily_returns.dropna()) > 2:
        volatility_annualized = float(daily_returns.std() * np.sqrt(252) * 100)
    else:
        volatility_annualized = 0.0

    latest_close = float(close.iloc[-1])
    latest_sma20 = float(sma20_series.iloc[-1]) if total_points >= 20 else None
    latest_sma50 = float(sma50_series.iloc[-1]) if total_points >= 50 else None
    latest_rsi = float(rsi_series.dropna().iloc[-1]) if not rsi_series.dropna().empty else None

    # 30-day price change
    idx_30d = max(0, total_points - 22)  # ~21-22 trading days in 30 calendar days
    close_30d_ago = float(close.iloc[idx_30d])
    change_30d_pct = round(((latest_close - close_30d_ago) / close_30d_ago) * 100, 2) if close_30d_ago > 0 else 0.0

    # 4. Filter chart data by requested period
    period_lower = requested_period.strip().lower()
    max_date = df.index.max()
    if period_lower == "7d":
        target_df = df[df.index >= (max_date - pd.Timedelta(days=7))]
        if len(target_df) < 2:
            target_df = df.tail(5)
    elif period_lower == "30d":
        target_df = df[df.index >= (max_date - pd.Timedelta(days=30))]
        if len(target_df) < 5:
            target_df = df.tail(22)
    elif period_lower == "90d":
        target_df = df[df.index >= (max_date - pd.Timedelta(days=90))]
        if len(target_df) < 10:
            target_df = df.tail(66)
    else:  # 1y or default
        target_df = df

    if target_df.empty:
        target_df = df.tail(30)

    period_start_close = float(target_df["Close"].dropna().iloc[0])
    period_change = round(latest_close - period_start_close, 2)
    period_change_pct = round((period_change / period_start_close) * 100, 2) if period_start_close > 0 else 0.0

    # Format points for charting
    history_points = []
    for dt, row in target_df.iterrows():
        dt_str = dt.strftime("%Y-%m-%d") if hasattr(dt, "strftime") else str(dt)[:10]
        c_val = round(float(row["Close"]), 2) if pd.notnull(row["Close"]) else None
        s20_val = round(float(sma20_series.loc[dt]), 2) if (dt in sma20_series and pd.notnull(sma20_series.loc[dt]) and total_points >= 20) else None
        s50_val = round(float(sma50_series.loc[dt]), 2) if (dt in sma50_series and pd.notnull(sma50_series.loc[dt]) and total_points >= 50) else None
        history_points.append({
            "date": dt_str,
            "close": c_val,
            "sma20": s20_val,
            "sma50": s50_val
        })

    # 5. Rule-Based Stock Outlook
    has_sufficient_data = (total_points >= 50) and (latest_sma20 is not None) and (latest_sma50 is not None)

    if not has_sufficient_data:
        signal = "INSUFFICIENT_DATA"
        signal_label = "Insufficient Historical Data"
        explanation = f"Insufficient trading history (only {total_points} trading sessions recorded). A minimum of 50 trading days is required to calculate reliable 20-day and 50-day moving averages."
    else:
        is_above_sma20 = latest_close >= latest_sma20
        is_above_sma50 = latest_close >= latest_sma50

        if is_above_sma20 and is_above_sma50:
            signal = "POSITIVE"
            signal_label = "Positive Technical Trend (Bullish)"
            explanation = (
                f"Positive technical trend: The latest closing price ({latest_close:.2f}) is above both the "
                f"20-day moving average ({latest_sma20:.2f}) and the 50-day moving average ({latest_sma50:.2f}). "
                f"This indicates positive historical momentum across short-term and medium-term horizons, but does not guarantee future gains."
            )
        elif (not is_above_sma20) and (not is_above_sma50):
            signal = "NEGATIVE"
            signal_label = "Negative Technical Trend (Bearish)"
            explanation = (
                f"Negative technical trend: The latest closing price ({latest_close:.2f}) is below both the "
                f"20-day moving average ({latest_sma20:.2f}) and the 50-day moving average ({latest_sma50:.2f}). "
                f"This indicates prevailing downward historical momentum, but does not guarantee future losses."
            )
        else:
            signal = "MIXED"
            signal_label = "Mixed Technical Signals (Neutral/Consolidation)"
            explanation = (
                f"Mixed trend: The closing price ({latest_close:.2f}) and moving averages disagree. "
                f"The stock is {'above' if is_above_sma20 else 'below'} the 20-day MA ({latest_sma20:.2f}) but "
                f"{'above' if is_above_sma50 else 'below'} the 50-day MA ({latest_sma50:.2f}), "
                f"reflecting potential consolidation or a transitional trend."
            )

    rsi_zone = "Neutral (30–70)"
    if latest_rsi is not None:
        if latest_rsi >= 70:
            rsi_zone = "Overbought (>= 70)"
        elif latest_rsi <= 30:
            rsi_zone = "Oversold (<= 30)"

    risks = (
        "Technical indicators (moving averages, RSI, volatility) reflect past trading history only. "
        "They do not account for fundamental corporate events, macroeconomic policy shifts, or unexpected market shocks, "
        "and should never be treated as guaranteed predictors of future price movements."
    )

    return {
        "history": history_points,
        "metrics": {
            "latest_close": round(latest_close, 2),
            "sma20": round(latest_sma20, 2) if latest_sma20 is not None else None,
            "sma50": round(latest_sma50, 2) if latest_sma50 is not None else None,
            "rsi14": round(latest_rsi, 2) if latest_rsi is not None else None,
            "volatility_annualized": round(volatility_annualized, 2),
            "period_change": period_change,
            "period_change_pct": period_change_pct,
            "change_30d_pct": change_30d_pct,
            "total_trading_days": total_points
        },
        "outlook": {
            "signal": signal,
            "signal_label": signal_label,
            "has_sufficient_data": has_sufficient_data,
            "rsi_zone": rsi_zone,
            "explanation": explanation,
            "risks": risks
        }
    }


def fetch_live_history(symbol: str, period: str = "1y") -> dict:
    """Fetch real historical stock data and compute technical indicators."""
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
        # Fetch 1y to have enough historical depth for SMA50 and RSI
        hist_df = ticker.history(period="1y")

        if hist_df.empty or "Close" not in hist_df.columns:
            return {
                "success": False,
                "symbol": sym,
                "error": f"No historical market data found for '{sym}'.",
                "timestamp": datetime.now(timezone.utc).isoformat()
            }

        # Name and currency resolution
        name = sym
        currency = "INR" if sym.endswith(".NS") or sym.endswith(".BO") else "USD"
        try:
            info = ticker.info or {}
            name = info.get("shortName") or info.get("longName") or sym
            currency = info.get("currency", currency) or currency
        except Exception:
            pass

        analysis = compute_technical_indicators(hist_df, period)
        if not analysis:
            return {
                "success": False,
                "symbol": sym,
                "error": f"Insufficient historical data points to compute analysis for '{sym}'.",
                "timestamp": datetime.now(timezone.utc).isoformat()
            }

        current_price = analysis["metrics"]["latest_close"]

        return {
            "success": True,
            "symbol": sym,
            "name": name,
            "currency": currency.upper(),
            "currency_symbol": get_currency_symbol(currency),
            "current_price": current_price,
            "period": period,
            "history": analysis["history"],
            "metrics": analysis["metrics"],
            "outlook": analysis["outlook"],
            "is_mock": False,
            "last_updated": datetime.now(timezone.utc).isoformat()
        }

    except Exception as e:
        return {
            "success": False,
            "symbol": sym,
            "error": f"Historical data retrieval failed for '{sym}': {str(e)}",
            "timestamp": datetime.now(timezone.utc).isoformat()
        }


def fetch_mock_history(symbol: str, period: str = "1y") -> dict:
    """Generate realistic mock historical series and indicators for offline testing."""
    import pandas as pd
    import numpy as np

    sym = symbol.strip().upper()
    mock_base = MOCK_DATABASE.get(sym)
    base_price = mock_base["price"] if mock_base else 150.00
    name = mock_base["name"] if mock_base else sym
    currency = mock_base["currency"] if mock_base else ("INR" if sym.endswith(".NS") else "USD")

    # Generate 120 weekdays of realistic historical prices
    dates = pd.bdate_range(end=datetime.now(timezone.utc), periods=120)
    np.random.seed(abs(hash(sym)) % 10000)

    # Mild upward or gentle oscillating trend
    trend = np.linspace(-0.05, 0.08, len(dates))
    noise = np.random.normal(0, 0.015, len(dates))
    multipliers = np.cumprod(1 + (trend / 100.0) + noise)
    prices = [round(float(base_price * (m / multipliers[-1])), 2) for m in multipliers]

    df = pd.DataFrame({"Close": prices}, index=dates)
    analysis = compute_technical_indicators(df, period)

    return {
        "success": True,
        "symbol": sym,
        "name": name,
        "currency": currency.upper(),
        "currency_symbol": get_currency_symbol(currency),
        "current_price": base_price,
        "period": period,
        "history": analysis["history"] if analysis else [],
        "metrics": analysis["metrics"] if analysis else {},
        "outlook": analysis["outlook"] if analysis else {},
        "is_mock": True,
        "last_updated": datetime.now(timezone.utc).isoformat()
    }


def main():
    parser = argparse.ArgumentParser(description="Fetch stock market data via yfinance")
    parser.add_argument("symbol", nargs="?", default="", help="Stock ticker symbol (e.g., AAPL, RELIANCE.NS)")
    parser.add_argument("--batch", help="Comma-separated list of symbols to fetch")
    parser.add_argument("--history", action="store_true", help="Fetch historical data, indicators, and outlook")
    parser.add_argument("--period", default="30d", help="Historical timeframe (7d, 30d, 90d, 1y)")
    parser.add_argument("--mock", action="store_true", help="Force mock data return for testing")

    args = parser.parse_args()

    # Mode 1: Historical Data & Technical Analysis
    if args.history:
        symbol = args.symbol.strip().upper()
        if not symbol:
            print(json.dumps({
                "success": False,
                "error": "No stock symbol provided for history.",
                "timestamp": datetime.now(timezone.utc).isoformat()
            }))
            sys.exit(1)

        if args.mock:
            result = fetch_mock_history(symbol, args.period)
        else:
            result = fetch_live_history(symbol, args.period)
            if not result.get("success") and symbol in MOCK_DATABASE:
                mock_res = fetch_mock_history(symbol, args.period)
                mock_res["fallback_triggered"] = True
                mock_res["original_error"] = result.get("error")
                result = mock_res

        print(json.dumps(result, indent=2))
        if not result.get("success"):
            sys.exit(1)
        return

    # Mode 2: Batch quotes
    if args.batch:
        symbols = [s.strip().upper() for s in args.batch.split(",") if s.strip()]
        results = {}
        for s in symbols:
            if args.mock:
                results[s] = fetch_mock_stock(s)
            else:
                res = fetch_live_stock(s)
                if not res.get("success") and s in MOCK_DATABASE:
                    mock_res = fetch_mock_stock(s)
                    mock_res["fallback_triggered"] = True
                    results[s] = mock_res
                else:
                    results[s] = res

        print(json.dumps({"success": True, "batch": results}, indent=2))
        return

    # Mode 3: Single stock quote (Existing default)
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
