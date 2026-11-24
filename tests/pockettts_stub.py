#!/usr/bin/env python3
"""A stand-in for DwemerDistro PocketTTS (Dwemer-Dynamics/pocket-tts bridge_api.py) for tests.

Same routes and shapes as the real bridge: GET /health, /provider_info, /speakers_list,
/speakers_list_extended, /openapi.json; POST /tts_to_audio (JSON {text, speaker_wav, language})
and /tts_to_audio_form, /set_tts_settings. /v1/audio/speech answers 404 like the real one.
Each POST is appended as one JSON line to --log so a test can check what was sent.

  python3 pockettts_stub.py --port 8024 [--speakers alba,marius] [--mode xtts|audio_cpp] [--log file]
"""
import argparse
import json
import struct
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs

parser = argparse.ArgumentParser()
parser.add_argument("--host", default="127.0.0.1")
parser.add_argument("--port", type=int, default=8024)
parser.add_argument("--speakers", default="")
parser.add_argument("--mode", default="xtts", choices=["xtts", "audio_cpp"])
parser.add_argument("--log", default="")
args = parser.parse_args()
SPEAKERS = [s for s in args.speakers.split(",") if s] or ["alba", "marius", "javert"]
XTTS_PATHS = ["/health", "/provider_info", "/set_tts_settings", "/speakers_list", "/speakers_list_extended",
              "/tts_to_audio", "/tts_to_audio_form", "/upload_sample", "/voices/{voice_id}"]


def wav(seconds=0.1, rate=24000):
    # 32-bit float mono, like scipy.io.wavfile.write with a float32 array.
    frames = int(seconds * rate)
    data = b"\x00\x00\x00\x00" * frames
    fmt = struct.pack("<HHIIHH", 3, 1, rate, rate * 4, 4, 32)
    body = b"WAVE" + b"fmt " + struct.pack("<I", len(fmt)) + fmt + b"data" + struct.pack("<I", len(data)) + data
    return b"RIFF" + struct.pack("<I", len(body)) + body


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, status, body, ctype="application/json"):
        if not isinstance(body, bytes):
            body = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def record(self, entry):
        if args.log:
            with open(args.log, "a") as f:
                f.write(json.dumps(entry) + "\n")

    def do_GET(self):
        path = self.path.split("?")[0]
        if args.mode == "audio_cpp":
            if path == "/health":
                return self.send(200, {"status": "ok"})
            if path == "/v1/models":
                return self.send(200, {"data": [{"id": "pocket-tts", "family": "pocket_tts"}]})
            return self.send(404, {"detail": "Not Found"})
        info = {"status": "ok", "provider": "pockettts", "runtime": "python", "api_family": "xtts-compatible",
                "port": args.port, "device": "cpu", "language": None}
        if path in ("/health", "/provider_info"):
            return self.send(200, info)
        if path == "/speakers_list":
            return self.send(200, SPEAKERS)
        if path == "/speakers_list_extended":
            items = [{"voice_id": s, "can_delete": True, "source": "uploaded_sample"} for s in SPEAKERS]
            return self.send(200, {"speakers": items, "count": len(items)})
        if path == "/openapi.json":
            return self.send(200, {"openapi": "3.1.0", "info": {"title": "FastAPI", "version": "0.1.0"},
                                   "paths": {p: {} for p in XTTS_PATHS}})
        return self.send(404, {"detail": "Not Found"})

    def do_POST(self):
        path = self.path.split("?")[0]
        raw = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        if args.mode == "audio_cpp" and path == "/v1/audio/speech":
            body = json.loads(raw or b"{}")
            self.record({"path": path, "body": body})
            return self.send(200, wav(), "audio/wav")
        if args.mode == "xtts" and path in ("/tts_to_audio", "/tts_to_audio/"):
            try:
                body = json.loads(raw)
                assert isinstance(body.get("text"), str)
            except Exception:
                return self.send(422, {"detail": [{"type": "missing", "loc": ["body", "text"]}]})
            self.record({"path": path, "body": body, "content_type": self.headers.get("Content-Type")})
            if not body["text"]:
                return self.send(200, {"error": "no text"})
            return self.send(200, wav(), "audio/wav")
        if args.mode == "xtts" and path == "/tts_to_audio_form":
            form = {k: v[0] for k, v in parse_qs(raw.decode()).items()}
            self.record({"path": path, "body": form})
            return self.send(200, wav(), "audio/wav")
        if args.mode == "xtts" and path == "/set_tts_settings":
            return self.send(200, {"status": "success"})
        return self.send(404, {"detail": "Not Found"})


ThreadingHTTPServer((args.host, args.port), Handler).serve_forever()
