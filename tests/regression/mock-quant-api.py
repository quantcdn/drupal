#!/usr/bin/env python3
"""Mock Quant API for the multi-domain PoC.

Purpose: record which Quant project each push is addressed to.

The Drupal module identifies the target project with the `Quant-Project`
request header. This server accepts every request, returns the minimum
response shape the module expects, and appends one JSON line per request
to requests.jsonl.

Run on the host. ddev containers reach it at http://host.docker.internal:8899
"""

import json
import os
import sys
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT = 8899
LOG_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "requests.jsonl")

# The response shape QuantApi::onOutput() destructures after a send().
SEND_RESPONSE = {
    "attachments": {
        "js": [],
        "css": [],
        "media": {"images": [], "documents": [], "video": []},
    }
}

# The shape quant_search / SeedForm expect from /v1/ping.
PING_RESPONSE = {
    "project": "mock",
    "config": {"search_enabled": True},
}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        # Silence the default stderr access log; we keep our own.
        pass

    def _record(self, method):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""

        pushed_url = None
        has_search_record = False
        try:
            if raw:
                payload = json.loads(raw)
                pushed_url = payload.get("url")
                has_search_record = "search_record" in payload
        except (ValueError, AttributeError):
            pass

        entry = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "method": method,
            "path": self.path,
            # The three headers that identify the destination.
            "quant_project": self.headers.get("Quant-Project"),
            "quant_customer": self.headers.get("Quant-Customer"),
            "quant_token": self.headers.get("Quant-Token"),
            # The content being published.
            "pushed_url": pushed_url,
            # unpublish sends its target in a header rather than the body.
            "quant_url_header": self.headers.get("Quant-Url"),
            "has_search_record": has_search_record,
        }

        with open(LOG_PATH, "a") as handle:
            handle.write(json.dumps(entry) + "\n")

        print(
            f"{method:6} {self.path:24} project={entry['quant_project']!s:20} "
            f"url={entry['pushed_url']}",
            flush=True,
        )
        return entry

    def _respond(self, payload):
        body = json.dumps(payload).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self._record("GET")
        if self.path.startswith("/v1/ping"):
            self._respond(PING_RESPONSE)
        else:
            self._respond({"status": "ok"})

    def do_POST(self):
        self._record("POST")
        self._respond(SEND_RESPONSE)

    def do_PATCH(self):
        self._record("PATCH")
        self._respond({"status": "ok"})


if __name__ == "__main__":
    if "--reset" in sys.argv and os.path.exists(LOG_PATH):
        os.remove(LOG_PATH)
    print(f"Mock Quant API on :{PORT}, logging to {LOG_PATH}", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
