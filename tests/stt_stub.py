#!/usr/bin/env python3
"""Stand-in for DwemerDistro's speech-to-text services, for tests/stt_test.php and the Java stub test.

--mode parakeet   POST /v1/audio/transcriptions (fields file, model) -> {"text": ...}
--mode fasterwhisper POST /api/v0/transcribe (field audio_file) -> {"text": ...}
--mode whispercpp POST /inference (field file, response_format) -> {"text": ...}
Any other path is 404. The reply text names the fields it got, so tests can check the request.
A WAV whose data is all zero gets {"text": "[BLANK_AUDIO]"}.
"""
import argparse
import json
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PATHS = {"parakeet": "/v1/audio/transcriptions", "fasterwhisper": "/api/v0/transcribe", "whispercpp": "/inference"}


def fields(body: bytes, content_type: str):
    m = re.search(r"boundary=([^;]+)", content_type or "")
    if not m:
        return {}
    out = {}
    for part in body.split(b"--" + m.group(1).strip('"').encode()):
        head, _, value = part.partition(b"\r\n\r\n")
        name = re.search(rb'name="([^"]+)"', head)
        if name:
            out[name.group(1).decode()] = value[:-2] if value.endswith(b"\r\n") else value
    return out


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        if self.path != PATHS[self.server.mode]:
            self.send_response(404)
            self.end_headers()
            return
        got = fields(body, self.headers.get("Content-Type"))
        wav = next((v for v in got.values() if v[:4] == b"RIFF"), b"")
        if not wav:
            self.send_response(400)
            self.end_headers()
            return
        if not any(wav[44:]):
            text = "[BLANK_AUDIO]"
        else:
            names = ",".join(sorted(k for k in got if got[k][:4] != b"RIFF"))
            text = f" hello from {self.server.mode} fields={names} auth={'yes' if self.headers.get('Authorization') else 'no'} "
        data = json.dumps({"text": text}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, required=True)
    ap.add_argument("--mode", choices=PATHS.keys(), default="parakeet")
    a = ap.parse_args()
    srv = ThreadingHTTPServer(("127.0.0.1", a.port), Handler)
    srv.mode = a.mode
    srv.serve_forever()
