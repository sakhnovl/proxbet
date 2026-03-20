#!/usr/bin/env python3
"""back_start.py

Scheduler/launcher for ProxBet.

What it does while this script is running:

1) Cron-like jobs (no overlap per job):
   - backend\\live.php -> backend\\scanner\\ScannerCli.php -> backend\\bet_checker.php
                                              every 1 minute as one sequential pipeline
   - backend\\parser.php                      every 5 minutes
   - backend\\stat.php                        every 5 minutes, immediately after parser.php completes

2) Long-living background processes (kept running; auto-restart on exit):
   - Telegram bot: backend\\telegram_bot.php

Stop everything with Ctrl+C.

PHP detection order:
  1) env var PHP_BIN
  2) `php` in PATH
  3) common XAMPP paths (C:\\xampp\\php\\php.exe)

Usage (Windows cmd):
  python back_start.py

Show help:
  python back_start.py --help

If php is not detected:
  set PHP_BIN=C:\\xampp\\php\\php.exe
  python back_start.py

One-time test run (runs live once and parser+stat once, does NOT start bot):
  python back_start.py --once
"""

from __future__ import annotations

import argparse
import os
import shutil
import signal
import subprocess
import threading
import time
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Callable, Optional


ROOT = Path(__file__).resolve().parent

PARSER_PHP = ROOT / "backend" / "parser.php"
STAT_PHP = ROOT / "backend" / "stat.php"
LIVE_PHP = ROOT / "backend" / "live.php"
SCANNER_PHP = ROOT / "backend" / "scanner" / "ScannerCli.php"
BET_CHECKER_PHP = ROOT / "backend" / "bet_checker.php"
TELEGRAM_BOT_PHP = ROOT / "backend" / "telegram_bot.php"


def ts() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def is_windows() -> bool:
    return os.name == "nt"


def detect_php_bin() -> str:
    """Best-effort detection of php.exe on Windows/XAMPP."""

    env_bin = os.environ.get("PHP_BIN")
    if env_bin:
        return env_bin

    in_path = shutil.which("php")
    if in_path:
        return in_path

    candidates = [
        r"C:\\xampp\\php\\php.exe",
        r"C:\\XAMPP\\php\\php.exe",
    ]
    for c in candidates:
        if Path(c).exists():
            return c

    # Fallback: will produce a clear error message in run_php/run_long()
    return "php"


def run_php(script_path: Path, job_name: str, args: list[str] = None) -> int:
    """Run a php script and stream output to this process."""

    if not script_path.exists():
        print(f"[{ts()}] [X] {job_name}: file not found: {script_path}", flush=True)
        return 2

    php_bin = detect_php_bin()
    cmd = [php_bin, str(script_path)]
    if args:
        cmd.extend(args)
    print(f"[{ts()}] [INFO] {job_name}: start -> {' '.join(cmd)}", flush=True)

    try:
        completed = subprocess.run(cmd, cwd=str(ROOT), check=False)
        code = int(completed.returncode or 0)
        if code == 0:
            print(f"[{ts()}] [OK] {job_name}: done (exit {code})", flush=True)
        else:
            print(f"[{ts()}] [WARN] {job_name}: done (exit {code})", flush=True)
        return code
    except FileNotFoundError:
        print(
            f"[{ts()}] [X] {job_name}: executable not found: {cmd[0]}. "
            "Set PHP_BIN env var (e.g. C:\\xampp\\php\\php.exe)",
            flush=True,
        )
        return 127
    except Exception as e:
        print(f"[{ts()}] [X] {job_name}: exception: {e}", flush=True)
        return 1


def popen_kwargs_new_process_group() -> dict:
    """Make child process easier to stop on Windows (CTRL_BREAK)."""

    if not is_windows():
        return {}

    # CREATE_NEW_PROCESS_GROUP allows sending CTRL_BREAK_EVENT to the process.
    return {"creationflags": subprocess.CREATE_NEW_PROCESS_GROUP}


def terminate_process(p: subprocess.Popen, name: str, timeout: float = 8.0) -> None:
    """Try graceful termination, then force kill."""

    if p.poll() is not None:
        return

    print(f"[{ts()}] [INFO] stop: terminating {name} (pid {p.pid})", flush=True)

    try:
        if is_windows():
            # Try Ctrl+Break for console processes (node/php). If it fails, fall back to terminate.
            try:
                p.send_signal(signal.CTRL_BREAK_EVENT)
            except Exception:
                p.terminate()
        else:
            p.terminate()

        p.wait(timeout=timeout)
        print(f"[{ts()}] [OK] stop: {name} exited", flush=True)
        return
    except Exception:
        pass

    try:
        print(f"[{ts()}] [WARN] stop: killing {name} (pid {p.pid})", flush=True)
        p.kill()
        p.wait(timeout=timeout)
    except Exception as e:
        print(f"[{ts()}] [X] stop: failed to kill {name}: {e}", flush=True)


class LongProcess:
    """Keeps a long-living process running; restarts it if it exits."""

    def __init__(self, name: str, cmd: list[str], cwd: Path):
        self.name = name
        self.cmd = cmd
        self.cwd = cwd
        self._proc: Optional[subprocess.Popen] = None
        self._lock = threading.Lock()

    def start(self) -> None:
        with self._lock:
            if self._proc and self._proc.poll() is None:
                return

            print(
                f"[{ts()}] [INFO] {self.name}: spawn -> {' '.join(self.cmd)} (cwd={self.cwd})",
                flush=True,
            )
            self._proc = subprocess.Popen(
                self.cmd,
                cwd=str(self.cwd),
                stdout=None,
                stderr=None,
                stdin=None,
                **popen_kwargs_new_process_group(),
            )

    def stop(self) -> None:
        with self._lock:
            if not self._proc:
                return
            terminate_process(self._proc, self.name)
            self._proc = None

    def watch_loop(self, stop_event: threading.Event, restart_delay_sec: float = 2.0) -> None:
        """Run in a thread."""

        self.start()

        while not stop_event.is_set():
            with self._lock:
                p = self._proc

            if not p:
                # If somehow missing, respawn
                self.start()
                stop_event.wait(timeout=restart_delay_sec)
                continue

            rc = p.poll()
            if rc is None:
                stop_event.wait(timeout=1.0)
                continue

            print(
                f"[{ts()}] [WARN] {self.name}: exited with code {rc}; restarting in {restart_delay_sec}s",
                flush=True,
            )
            stop_event.wait(timeout=restart_delay_sec)
            if stop_event.is_set():
                break
            self.start()


@dataclass
class Job:
    name: str
    interval_sec: int
    target: Callable[[], None]
    initial_delay_sec: float = 0.0

    _lock: threading.Lock = field(default_factory=threading.Lock, init=False, repr=False)
    _running: bool = field(default=False, init=False, repr=False)

    def tick(self, stop_event: threading.Event) -> None:
        """Run loop: execute job every interval seconds (best-effort)."""

        next_run = time.time() + max(0.0, self.initial_delay_sec)
        while not stop_event.is_set():
            now = time.time()
            if now < next_run:
                stop_event.wait(timeout=min(0.5, next_run - now))
                continue

            # Schedule next run from the planned time to reduce drift.
            next_run += self.interval_sec

            with self._lock:
                if self._running:
                    print(
                        f"[{ts()}] [WARN] {self.name}: previous run still executing; skipping this tick",
                        flush=True,
                    )
                    continue
                self._running = True

            try:
                self.target()
            finally:
                with self._lock:
                    self._running = False


def job_live() -> None:
    run_php(LIVE_PHP, "live")


def job_scanner() -> None:
    run_php(SCANNER_PHP, "scanner")


def job_bet_checker() -> None:
    run_php(BET_CHECKER_PHP, "bet_checker")


def job_minute_pipeline(run_live: bool, run_scanner: bool, run_bet_checker: bool) -> None:
    if run_live:
        job_live()
    if run_scanner:
        job_scanner()
    if run_bet_checker:
        job_bet_checker()


def job_parser_then_stat() -> None:
    code = run_php(PARSER_PHP, "parser")
    run_php(STAT_PHP, f"stat(after parser exit {code})")


def main() -> int:
    parser = argparse.ArgumentParser(
        prog="back_start.py",
        description=(
            "Launcher: live.php -> scanner -> bet_checker every 1m as one sequential pipeline; "
            "parser.php + stat.php every 5m (stat after parser); also runs telegram_bot.php in background."
        ),
    )

    parser.add_argument(
        "--once",
        action="store_true",
        help="Run live once and parser+stat once, then exit (does not start bot).",
    )

    # Enable/disable parts
    parser.add_argument("--no-live", action="store_true", help="Disable live.php job.")
    parser.add_argument("--no-scanner", action="store_true", help="Disable scanner job.")
    parser.add_argument("--no-bet-checker", action="store_true", help="Disable bet_checker.php job.")
    parser.add_argument("--no-parserstat", action="store_true", help="Disable parser.php+stat.php job.")
    parser.add_argument("--no-bot", action="store_true", help="Do not run backend/telegram_bot.php.")

    args = parser.parse_args()

    php_bin = detect_php_bin()
    print(f"[{ts()}] [INFO] back_start: root={ROOT}", flush=True)
    print(f"[{ts()}] [INFO] back_start: PHP_BIN={php_bin}", flush=True)

    if args.once:
        job_minute_pipeline(
            run_live=not args.no_live,
            run_scanner=not args.no_scanner,
            run_bet_checker=not args.no_bet_checker,
        )
        if not args.no_parserstat:
            job_parser_then_stat()
        return 0

    stop_event = threading.Event()

    # Long-living background processes
    long_procs: list[LongProcess] = []

    if not args.no_bot:
        long_procs.append(LongProcess("telegram-bot", [php_bin, str(TELEGRAM_BOT_PHP)], cwd=ROOT))

    watch_threads: list[threading.Thread] = []
    for lp in long_procs:
        t = threading.Thread(target=lp.watch_loop, args=(stop_event,), name=f"watch-{lp.name}", daemon=True)
        t.start()
        watch_threads.append(t)

    # Scheduled jobs
    jobs: list[Job] = []
    if not args.no_live or not args.no_scanner or not args.no_bet_checker:
        jobs.append(
            Job(
                name="minute-pipeline-every-1m",
                interval_sec=60,
                target=lambda: job_minute_pipeline(
                    run_live=not args.no_live,
                    run_scanner=not args.no_scanner,
                    run_bet_checker=not args.no_bet_checker,
                ),
            )
        )
    if not args.no_parserstat:
        jobs.append(Job(name="parser+stat-every-5m", interval_sec=300, target=job_parser_then_stat))

    job_threads: list[threading.Thread] = []
    for j in jobs:
        t = threading.Thread(target=j.tick, args=(stop_event,), name=j.name, daemon=True)
        t.start()
        job_threads.append(t)

    if not jobs and not long_procs:
        print(f"[{ts()}] [WARN] back_start: nothing enabled; exiting", flush=True)
        return 0

    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print(f"[{ts()}] [INFO] back_start: stopping...", flush=True)
        stop_event.set()

        # Stop long-living processes first
        for lp in long_procs:
            lp.stop()

        for t in job_threads + watch_threads:
            t.join(timeout=5)

        print(f"[{ts()}] [OK] back_start: stopped", flush=True)
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
