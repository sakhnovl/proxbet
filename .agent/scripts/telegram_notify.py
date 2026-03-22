#!/usr/bin/env python3
"""
Send a short Telegram notification using workspace environment variables.

Resolution order:
1. Process environment
2. Project .env file

Required:
- TELEGRAM_BOT_TOKEN
- TELEGRAM_NOTIFY_CHAT_ID or first TELEGRAM_ADMIN_IDS value
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
ENV_FILE = ROOT / ".env"
API_TIMEOUT_SECONDS = 20
TRANSPORT_AUTO = "auto"
TRANSPORT_URLLIB = "urllib"
TRANSPORT_POWERSHELL = "powershell"


def load_dotenv(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}

    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("'").strip('"')
    return values


def get_setting(name: str, dotenv: dict[str, str]) -> str:
    return os.getenv(name, "").strip() or dotenv.get(name, "").strip()


def resolve_chat_id(dotenv: dict[str, str], explicit_chat_id: str | None) -> str:
    if explicit_chat_id:
        return explicit_chat_id.strip()

    notify_chat_id = get_setting("TELEGRAM_NOTIFY_CHAT_ID", dotenv)
    if notify_chat_id:
        return notify_chat_id

    admin_ids = get_setting("TELEGRAM_ADMIN_IDS", dotenv)
    if not admin_ids:
        return ""

    return admin_ids.split(",", 1)[0].strip()


def send_message_via_urllib(bot_token: str, chat_id: str, text: str) -> None:
    url = f"https://api.telegram.org/bot{bot_token}/sendMessage"
    payload = json.dumps(
        {
            "chat_id": chat_id,
            "text": text,
            "disable_web_page_preview": True,
        }
    ).encode("utf-8")

    request = urllib.request.Request(
        url,
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    with urllib.request.urlopen(request, timeout=API_TIMEOUT_SECONDS) as response:
        body = response.read().decode("utf-8", errors="replace")
        data = json.loads(body)

    if not data.get("ok"):
        raise RuntimeError(f"Telegram API returned non-ok response: {body}")


def send_message_via_powershell(bot_token: str, chat_id: str, text: str) -> None:
    payload = json.dumps(
        {
            "chat_id": chat_id,
            "text": text,
            "disable_web_page_preview": True,
        }
    )
    script = "\n".join(
        [
            "$ErrorActionPreference = 'Stop'",
            "$ProgressPreference = 'SilentlyContinue'",
            f"$uri = 'https://api.telegram.org/bot{bot_token}/sendMessage'",
            f"$body = @'\n{payload}\n'@",
            "$response = Invoke-RestMethod -Method Post -Uri $uri -ContentType 'application/json' -Body $body -TimeoutSec 20",
            "if (-not $response.ok) {",
            "    throw ('Telegram API returned non-ok response: ' + ($response | ConvertTo-Json -Depth 10 -Compress))",
            "}",
        ]
    )
    result = subprocess.run(
        ["powershell", "-NoProfile", "-Command", script],
        capture_output=True,
        text=True,
        timeout=API_TIMEOUT_SECONDS + 5,
        check=False,
    )
    if result.returncode != 0:
        stderr = result.stderr.strip()
        stdout = result.stdout.strip()
        detail = stderr or stdout or "PowerShell transport failed"
        raise RuntimeError(detail)


def send_message(bot_token: str, chat_id: str, text: str, transport: str) -> str:
    attempts: list[tuple[str, callable]] = []

    if transport == TRANSPORT_URLLIB:
        attempts = [(TRANSPORT_URLLIB, send_message_via_urllib)]
    elif transport == TRANSPORT_POWERSHELL:
        attempts = [(TRANSPORT_POWERSHELL, send_message_via_powershell)]
    else:
        attempts = [(TRANSPORT_URLLIB, send_message_via_urllib)]
        if os.name == "nt":
            attempts.append((TRANSPORT_POWERSHELL, send_message_via_powershell))

    errors: list[str] = []
    for attempt_name, sender in attempts:
        try:
            sender(bot_token, chat_id, text)
            return attempt_name
        except Exception as error:  # noqa: BLE001
            errors.append(f"{attempt_name}: {error}")

    joined_errors = "; ".join(errors) if errors else "No transport attempts executed"
    raise RuntimeError(joined_errors)


def build_message(summary: str, task: str | None) -> str:
    prefix = "[Codex] Task completed"
    if task:
        prefix = f"{prefix}: {task}"
    return f"{prefix}\n{summary}"


def main() -> int:
    parser = argparse.ArgumentParser(description="Send a Telegram completion notification.")
    parser.add_argument("summary", help="Short completion summary")
    parser.add_argument("--task", help="Optional task label")
    parser.add_argument("--chat-id", help="Optional explicit Telegram chat id override")
    parser.add_argument(
        "--transport",
        choices=[TRANSPORT_AUTO, TRANSPORT_URLLIB, TRANSPORT_POWERSHELL],
        default=TRANSPORT_AUTO,
        help="HTTP transport for Telegram delivery",
    )
    parser.add_argument("--dry-run", action="store_true", help="Validate config and print the message without sending")
    args = parser.parse_args()

    dotenv = load_dotenv(ENV_FILE)
    bot_token = get_setting("TELEGRAM_BOT_TOKEN", dotenv)
    chat_id = resolve_chat_id(dotenv, args.chat_id)

    if not bot_token:
        print("SKIPPED: TELEGRAM_BOT_TOKEN is not configured.", file=sys.stderr)
        return 2

    if not chat_id:
        print(
            "SKIPPED: No Telegram destination found. Set TELEGRAM_NOTIFY_CHAT_ID or TELEGRAM_ADMIN_IDS.",
            file=sys.stderr,
        )
        return 3

    message = build_message(args.summary, args.task)
    if args.dry_run:
        print(f"DRY-RUN: Telegram notification prepared for chat {chat_id}.")
        print(f"Transport: {args.transport}")
        print(message)
        return 0

    try:
        transport_used = send_message(bot_token, chat_id, message, args.transport)
    except Exception as error:  # noqa: BLE001
        print(f"ERROR: {error}", file=sys.stderr)
        return 5

    print(f"SENT: Telegram notification delivered to chat {chat_id} via {transport_used}.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
