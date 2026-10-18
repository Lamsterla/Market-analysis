#!/usr/bin/env python3
"""
Stock Market Automated System - Background Stop-Loss Monitor
Continuously polls market prices for open positions with configured stop-losses
and signals the PHP backend to execute simulated sales when thresholds are triggered.

Usage on Windows:
  python python/monitor_stop_loss.py
  python python/monitor_stop_loss.py --interval 15
  python python/monitor_stop_loss.py --once
  python python/monitor_stop_loss.py --mock

To stop: Press Ctrl + C in the command prompt or terminal window.
"""

import os
import sys

# Ensure running under Python 3.12 64-bit where yfinance is installed
py312_exe = r"C:\Users\Arun Sah\AppData\Local\Programs\Python\Python312\python.exe"
if os.path.exists(py312_exe) and sys.executable.lower() != py312_exe.lower():
    import subprocess
    _res = subprocess.run([py312_exe] + sys.argv, check=False)
    sys.exit(_res.returncode)

import time
import json
import signal
import subprocess
from datetime import datetime

# Script Directory and Root Paths
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR = os.path.dirname(SCRIPT_DIR)
STOCK_DATA_SCRIPT = os.path.join(SCRIPT_DIR, "stock_data.py")
PHP_API_SCRIPT = os.path.join(ROOT_DIR, "api", "execute_stop_loss.php")
HEARTBEAT_FILE = os.path.join(ROOT_DIR, "monitor_heartbeat.json")

# Find PHP Binary
def get_php_executable():
    candidate_paths = [
        r"C:\xampp\php\php.exe",
        r"C:\php\php.exe",
        "php"
    ]
    for p in candidate_paths:
        if os.path.isabs(p) and os.path.exists(p):
            return p
    return "php"


PHP_BIN = get_php_executable()
RUNNING = True


def signal_handler(sig, frame):
    global RUNNING
    print("\n[MONITOR] Shutdown signal received (Ctrl+C). Stopping monitor gracefully...")
    RUNNING = False
    update_heartbeat(is_running=False, message="Monitor stopped by user")
    sys.exit(0)


def update_heartbeat(is_running: bool, message: str = "", symbols: list = None):
    data = {
        "is_running": is_running,
        "last_heartbeat": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "symbols": symbols or [],
        "message": message
    }
    try:
        with open(HEARTBEAT_FILE, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2)
    except Exception as e:
        print(f"[HEARTBEAT ERROR] Could not write heartbeat: {e}", file=sys.stderr)


def get_active_stop_loss_symbols():
    """Call PHP backend to retrieve positions that currently have stop-losses configured."""
    cmd = [PHP_BIN, PHP_API_SCRIPT, "--get-symbols"]
    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
        if res.returncode == 0 and res.stdout:
            data = json.loads(res.stdout)
            if data.get("success"):
                return data.get("symbols", [])
    except Exception as e:
        print(f"[WARN] Failed to query active symbols from PHP: {e}")
    return []


def fetch_prices_for_symbols(symbols: list, force_mock: bool = False):
    """Fetch batch prices using stock_data.py."""
    if not symbols:
        return {}

    symbol_str = ",".join(s["symbol"] for s in symbols)
    cmd = [sys.executable, STOCK_DATA_SCRIPT, "--batch", symbol_str]
    if force_mock:
        cmd.append("--mock")

    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=25)
        if res.returncode == 0 and res.stdout:
            data = json.loads(res.stdout)
            if data.get("success"):
                return data.get("batch", {})
    except Exception as e:
        print(f"[WARN] Price fetch error: {e}")
    return {}


def trigger_php_evaluation(prices: dict, force_mock: bool = False):
    """Pass fetched prices to PHP for atomic evaluation and stop-loss execution."""
    cmd = [PHP_BIN, PHP_API_SCRIPT, f"--prices={json.dumps(prices)}"]
    if force_mock:
        cmd.append("--mock")

    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
        if res.returncode == 0 and res.stdout:
            return json.loads(res.stdout)
    except Exception as e:
        print(f"[ERROR] PHP evaluation invocation failed: {e}")
    return None


def main():
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    import argparse
    parser = argparse.ArgumentParser(description="Stock Market Automated Stop-Loss Monitor")
    parser.add_argument("--interval", type=int, default=30, help="Polling interval in seconds (default: 30)")
    parser.add_argument("--once", action="store_true", help="Run a single evaluation cycle and exit")
    parser.add_argument("--mock", action="store_true", help="Use mock price data for testing")
    args = parser.parse_args()

    interval = max(5, args.interval)
    force_mock = args.mock

    print("=" * 60)
    print(" STOCK MARKET SIMULATOR - AUTOMATED STOP-LOSS MONITOR")
    print("=" * 60)
    print(f" Polling Interval : {interval} seconds")
    print(f" Mode             : {'MOCK TESTING' if force_mock else 'LIVE YAHOO FINANCE'}")
    print(f" PHP Executable   : {PHP_BIN}")
    print(f" Python Executable: {sys.executable}")
    print(" Note: Strictly paper-trading. No real orders are executed.")
    print(" Press Ctrl+C at any time to stop monitoring.")
    print("=" * 60 + "\n")

    iteration = 0

    while RUNNING:
        iteration += 1
        now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        print(f"[{now_str}] [Cycle #{iteration}] Checking active stop-losses...")

        # 1. Get positions requiring stop-loss checks
        active_positions = get_active_stop_loss_symbols()

        if not active_positions:
            msg = "No open positions with configured stop-losses."
            print(f"  -> {msg}")
            update_heartbeat(is_running=True, message=msg, symbols=[])
        else:
            symbols = [p["symbol"] for p in active_positions]
            print(f"  -> Monitoring {len(active_positions)} position(s): {', '.join(symbols)}")

            # 2. Retrieve latest quotes
            quotes = fetch_prices_for_symbols(active_positions, force_mock=force_mock)

            # Display prices
            for pos in active_positions:
                sym = pos["symbol"]
                sl_price = float(pos["stop_loss_price"])
                quote = quotes.get(sym, {})
                price = quote.get("price")
                if price:
                    status = "TRIGGER PENDING!" if float(price) <= sl_price else "OK"
                    print(f"     {sym}: Price={price} | Stop-Loss={sl_price} [{status}]")
                else:
                    print(f"     {sym}: Price unavailable (skipping)")

            # 3. Call PHP to evaluate and trigger simulated orders
            eval_result = trigger_php_evaluation(quotes, force_mock=force_mock)

            if eval_result and eval_result.get("success"):
                res = eval_result.get("result", {})
                triggered = res.get("triggered", [])
                if triggered:
                    print(f"\n  [ALERT] {len(triggered)} STOP-LOSS ORDER(S) EXECUTED:")
                    for t in triggered:
                        print(f"    * Sold {t['quantity']} shares of {t['symbol']} at {t['executed_price']}. P&L: {t['realised_pnl']}")
                    print()
                msg = res.get("message", "Cycle completed successfully")
                update_heartbeat(is_running=True, message=msg, symbols=symbols)
            else:
                update_heartbeat(is_running=True, message="Checked, PHP evaluation pending", symbols=symbols)

        if args.once:
            print("[MONITOR] Single run completed (--once). Exiting.")
            break

        # Sleep with sub-second polling for responsive Ctrl+C exit
        for _ in range(interval):
            if not RUNNING:
                break
            time.sleep(1)

    update_heartbeat(is_running=False, message="Monitor stopped")


if __name__ == "__main__":
    main()
