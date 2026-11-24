r"""
Store Camera: watch each store camera live, all day, and send saifsys one line per
event with a snapshot. saifsys turns them into the daily report
(Operations -> Store Camera).

Double-click store_camera.bat and leave the window open. It:
  - asks saifsys for the cameras switched on there (every few minutes, so a camera
    added or turned off on the page is picked up without a restart),
  - watches each one live; when something moves it keeps a few snapshots until it
    has been still for a while,
  - asks Claude what happened, in one line ("Door opened, 1 person came in and took
    a blue can"); a change of light or nothing that matters is dropped,
  - sends the line, its start and end time and the clearest snapshot to saifsys.

Uses config.ini next to this file: [camera] saifsys_url and camera_key, the same as
attendance_camera.py. For Claude, set ANTHROPIC_API_KEY, or put claude_api_key in a
[store_camera] section of config.ini (see config.example.ini).
Read only for the cameras: nothing on them is changed.
"""

import configparser
import io
import json
import os
import queue
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from base64 import standard_b64encode
from datetime import datetime, timedelta

import cv2
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))

CHECK_EVERY = 0.5          # seconds between looks at the live picture
MOTION_SHARE = 0.015       # this share of the picture changed: something moved
STILL_SECONDS = 20         # still this long: the event is over
MAX_EVENT_SECONDS = 300    # a longer one is cut into parts, each its own line
MIN_EVENT_SECONDS = 1.5    # shorter: a flicker, not an event
SNAPSHOTS = 3              # pictures Claude sees per event
CAMERAS_EVERY = 300        # seconds between asking saifsys for the cameras
NOTHING = "NOTHING"

PROMPT = (
    "These are snapshots from a store-room security camera, in time order, taken during one "
    "moment when something moved ({times}). In ONE short line (at most 20 words), say what "
    "happened: how many people, doors opened or closed, and any item taken or brought in, with "
    "its colour or kind (for example: \"Door opened, 1 person came in and took a blue can\"). "
    "Say only what the pictures show; do not guess names. If nothing meaningful happened (only "
    f"light changed, no person and nothing moved), answer exactly {NOTHING}."
)


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def load_config():
    path = os.path.join(HERE, "config.ini")
    if not os.path.exists(path):
        sys.exit("config.ini is missing. Copy config.example.ini to config.ini and fill it in.")
    cfg = configparser.ConfigParser()
    cfg.read(path, encoding="utf-8")
    c = cfg["camera"]
    s = cfg["store_camera"] if cfg.has_section("store_camera") else {}
    return {
        # [store_camera] saifsys_url, when set, wins: e.g. http://localhost/saifsys to try it on a local copy.
        "saifsys_url": ((s.get("saifsys_url", "") or "").strip() or c.get("saifsys_url")).rstrip("/"),
        "camera_key": (s.get("camera_key", "") or "").strip() or c.get("camera_key"),  # [store_camera] camera_key wins too
        "claude_api_key": (s.get("claude_api_key", "") or "").strip(),
        "claude_model": (s.get("claude_model", "") or "").strip() or "claude-opus-5-5",
        # Where a past moment is read back from the memory card (the same setting as attendance_camera.py).
        "playback_path": c.get("playback_path", "/Streaming/tracks/101?starttime={start}&endtime={end}").strip(),
    }


# ---------- saifsys ----------

def saifsys(cfg, method, action, fields=None, jpeg=None, timeout=60):
    url = f"{cfg['saifsys_url']}/api/store_camera.php?action={action}"
    headers = {"X-Camera-Key": cfg["camera_key"], "User-Agent": "store-camera/1"}
    body = None
    if method == "POST":
        boundary = uuid.uuid4().hex
        buf = io.BytesIO()
        for k, v in (fields or {}).items():
            buf.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode())
        if jpeg is not None:
            buf.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"snapshot\"; filename=\"snapshot.jpg\"\r\n"
                      "Content-Type: image/jpeg\r\n\r\n".encode())
            buf.write(jpeg)
            buf.write(b"\r\n")
        buf.write(f"--{boundary}--\r\n".encode())
        body = buf.getvalue()
        headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        try:
            return json.loads(e.read().decode() or "{}")
        except Exception:
            return {"ok": False, "error": f"HTTP {e.code}"}
    except (urllib.error.URLError, OSError, ValueError) as e:
        return {"ok": False, "error": f"no connection to saifsys ({e})"}


# ---------- Claude ----------

class Describer:
    """One line on what happened, from a few snapshots. None: nothing worth a line, or Claude could not be reached."""

    def __init__(self, cfg):
        import anthropic
        self.anthropic = anthropic
        self.model = cfg["claude_model"]
        # No key in config.ini and none in Windows: every event is still sent, as "Movement seen".
        self.client = None
        if cfg["claude_api_key"]:
            self.client = anthropic.Anthropic(api_key=cfg["claude_api_key"])
        elif os.environ.get("ANTHROPIC_API_KEY") or os.environ.get("ANTHROPIC_AUTH_TOKEN"):
            self.client = anthropic.Anthropic()
        if self.client is None:
            log("Claude is not set up: events are sent as 'Movement seen' until claude_api_key is in config.ini.")

    def describe(self, jpegs, times):
        if self.client is None:
            return "Movement seen (not described: no Claude key)"
        try:
            return self._describe(jpegs, times)
        except Exception as e:  # never lose an event over the description
            log(f"Claude: {type(e).__name__}: {e}; this event is sent without a description.")
            return "Movement seen (not described)"

    def _describe(self, jpegs, times):
        content = [{"type": "image", "source": {"type": "base64", "media_type": "image/jpeg",
                                                 "data": standard_b64encode(j).decode("ascii")}} for j in jpegs]
        content.append({"type": "text", "text": PROMPT.format(times=", ".join(times))})
        try:
            msg = self.client.messages.create(
                model=self.model,
                max_tokens=1024,
                output_config={"effort": "low"},
                messages=[{"role": "user", "content": content}],
            )
        except self.anthropic.AuthenticationError:
            log("Claude: the API key is not valid. Set ANTHROPIC_API_KEY or claude_api_key in config.ini.")
            return "Movement seen (not described: Claude key not valid)"
        except self.anthropic.RateLimitError:
            log("Claude: too many requests just now; this event is sent without a description.")
            return "Movement seen (not described: Claude was busy)"
        except self.anthropic.APIStatusError as e:
            log(f"Claude: error {e.status_code}: {e.message}")
            return "Movement seen (not described)"
        except self.anthropic.APIConnectionError:
            log("Claude: no connection; this event is sent without a description.")
            return "Movement seen (not described: no internet)"
        if msg.stop_reason == "refusal":
            return "Movement seen (not described)"
        text = " ".join(b.text for b in msg.content if b.type == "text").strip()
        if not text or text.strip(" .").upper() == NOTHING:
            return None
        return text.splitlines()[0][:500]



# ---------- counting items: the pictures for "Compare" on the Live tab ----------
# saifsys asks for a picture (now, or at a past time); Reem counts the items in it there.
# Nothing is counted here, and no Claude key is needed for it.

def playback_url(live_url, path, start, end):
    """The camera's recording between start and end (camera's local time), from its live address.
    The default path is the Hikvision/EZVIZ one; another brand may need playback_path in config.ini."""
    p = urllib.parse.urlsplit(live_url)
    fmt = "%Y%m%dT%H%M%SZ"
    return f"{p.scheme}://{p.netloc}" + path.format(start=start.strftime(fmt), end=end.strftime(fmt))


def picture_at(cam, cfg, at):
    """One picture from the camera's memory card at 'Y-m-d H:i:s', or None (nothing recorded then, or no answer)."""
    start = datetime.strptime(at, "%Y-%m-%d %H:%M:%S")
    cap = cv2.VideoCapture(playback_url(cam["rtsp_url"], cfg["playback_path"], start, start + timedelta(minutes=1)), cv2.CAP_FFMPEG)
    try:
        began = time.time()
        while time.time() - began < 25:
            ok, frame = cap.read()
            if ok and frame is not None:
                return frame
            time.sleep(0.2)
        return None
    finally:
        cap.release()


def do_count(cfg, watchers, job):
    """Send saifsys the picture one count needs. saifsys has Reem count it before it answers, so wait long."""
    cid, count_id, at = job.get("camera_id"), job.get("id"), job.get("at")
    w = watchers.get(cid)
    frame = None
    if at:
        if w:
            frame = picture_at(w.cam, cfg, at)
        why = f"Nothing came back from the camera's recording for {at}. Is the memory card in, and was it recording then?"
    else:
        for _ in range(20):  # up to 10 seconds for a fresh live picture
            w = watchers.get(cid)
            if w and w.latest and time.time() - w.latest[0] <= 5:
                frame = w.latest[1]
                break
            time.sleep(0.5)
        why = "No fresh picture from the camera."
    jpeg = jpeg_of(frame, 1600) if frame is not None else None
    if not jpeg:
        saifsys(cfg, "POST", "count", {"count_id": count_id, "ok": 0, "note": why if w else "This camera is not being watched."})
        log(f"camera {cid}: no picture for the count ({at or 'now'})")
        return
    r = saifsys(cfg, "POST", "count", {"count_id": count_id, "ok": 1}, jpeg, timeout=200)
    log(f"camera {cid}: picture sent for the count ({at or 'now'})" + ("" if r.get("ok") else f"; saifsys: {r.get('error', 'no answer')}"))


def count_worker(cfg, watchers, jobs):
    """One count at a time, in the order asked: the second picture of a compare comes after the first is counted."""
    while True:
        job = jobs.get()
        try:
            do_count(cfg, watchers, job)
        except Exception as e:  # never stop the worker over one count
            log(f"count {job.get('id')}: {type(e).__name__}: {e}")


# ---------- watching one camera ----------

def jpeg_of(frame, width=1024):
    h, w = frame.shape[:2]
    if w > width:
        frame = cv2.resize(frame, (width, int(h * width / w)))
    ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 82])
    return buf.tobytes() if ok else None


class Watcher(threading.Thread):
    def __init__(self, cfg, cam, describer):
        super().__init__(daemon=True)
        self.cfg, self.cam, self.describer = cfg, cam, describer
        self.stop = threading.Event()
        self.latest = None  # (time, picture): the newest look, for the Live tab

    def send(self, event):
        shots = event["shots"]
        # Spread over the event: the first, the busiest, the last.
        picks = sorted({0, max(range(len(shots)), key=lambda i: shots[i][2]), len(shots) - 1})[:SNAPSHOTS]
        chosen = [shots[i] for i in picks]
        jpegs = [jpeg_of(s[1]) for s in chosen]
        jpegs = [j for j in jpegs if j]
        times = [datetime.fromtimestamp(s[0]).strftime("%H:%M:%S") for s in chosen]
        what = self.describer.describe(jpegs, times) if jpegs else None
        start, end = datetime.fromtimestamp(event["start"]), datetime.fromtimestamp(event["last"])
        if what is None:
            log(f"{self.cam['name']}: {start:%H:%M:%S} nothing worth a line, dropped")
            return
        best = jpeg_of(shots[max(range(len(shots)), key=lambda i: shots[i][2])][1], 1280)
        r = saifsys(self.cfg, "POST", "event", {
            "camera_id": self.cam["id"],
            "started_at": start.strftime("%Y-%m-%d %H:%M:%S"),
            "ended_at": end.strftime("%Y-%m-%d %H:%M:%S"),
            "description": what,
        }, best)
        log(f"{self.cam['name']}: {start:%H:%M}-{end:%H:%M} {what}" + ("" if r.get("ok") else f"  (NOT SAVED: {r.get('error')})"))

    def run(self):
        name = self.cam["name"]
        while not self.stop.is_set():
            cap = cv2.VideoCapture(self.cam["rtsp_url"], cv2.CAP_FFMPEG)
            if not cap.isOpened():
                log(f"{name}: cannot open the camera; trying again in 30 s")
                cap.release()
                self.stop.wait(30)
                continue
            log(f"{name}: watching")
            background, last_look, last_ok, event = None, 0.0, time.time(), None
            while not self.stop.is_set():
                ok = cap.grab()
                now = time.time()
                if not ok:
                    if now - last_ok > 20:
                        log(f"{name}: the picture stopped; reconnecting")
                        break
                    time.sleep(0.05)
                    continue
                last_ok = now
                if now - last_look < CHECK_EVERY:
                    continue
                last_look = now
                ok, frame = cap.retrieve()
                if not ok:
                    continue
                self.latest = (now, frame)
                small = cv2.GaussianBlur(cv2.cvtColor(cv2.resize(frame, (320, 180)), cv2.COLOR_BGR2GRAY), (5, 5), 0)
                if background is None:
                    background = small.astype("float")
                    continue
                diff = cv2.absdiff(small, cv2.convertScaleAbs(background))
                share = float(np.count_nonzero(diff > 25)) / diff.size
                cv2.accumulateWeighted(small, background, 0.05)
                moving = share >= MOTION_SHARE

                if moving and event is None:
                    event = {"start": now, "last": now, "shots": []}
                if event is not None:
                    if moving:
                        event["last"] = now
                        # Keep a few pictures spread over the event, and the busiest one.
                        if not event["shots"] or now - event["shots"][-1][0] >= 2:
                            event["shots"].append((now, frame.copy(), share))
                            if len(event["shots"]) > 12:
                                del event["shots"][1:3]
                        elif share > event["shots"][-1][2]:
                            event["shots"][-1] = (now, frame.copy(), share)
                    over = now - event["last"] >= STILL_SECONDS
                    too_long = now - event["start"] >= MAX_EVENT_SECONDS
                    if over or too_long:
                        if event["last"] - event["start"] >= MIN_EVENT_SECONDS and event["shots"]:
                            threading.Thread(target=self.send, args=(event,), daemon=True).start()
                        event = {"start": now, "last": now, "shots": []} if (too_long and moving) else None
            cap.release()


def live_sender(cfg, watchers):
    """The Live tab: while someone has it open, send that camera's newest picture about once a second.
    saifsys's answers also carry the pictures asked for item counts; they are sent one at a time."""
    want, asked = [], 0.0
    started = set()
    jobs = queue.Queue()
    threading.Thread(target=count_worker, args=(cfg, watchers, jobs), daemon=True).start()

    def take(r):
        for job in r.get("count", []) or []:
            if job.get("id") not in started:
                started.add(job.get("id"))
                jobs.put(job)
        return r.get("want", []) or []

    while True:
        now = time.time()
        if not want and now - asked < 3:
            time.sleep(0.5)
            continue
        if not want:
            asked = now
            want = take(saifsys(cfg, "GET", "live"))
            continue
        sent = False
        for cid in want:
            w = watchers.get(cid)
            if not w or not w.latest or now - w.latest[0] > 5:
                continue
            jpeg = jpeg_of(w.latest[1], 1280)
            if jpeg:
                want = take(saifsys(cfg, "POST", "frame", {"camera_id": cid}, jpeg))
                sent = True
        if not sent:
            asked = now
            want = take(saifsys(cfg, "GET", "live"))
        time.sleep(1)


def main():
    cfg = load_config()
    try:
        describer = Describer(cfg)
    except ImportError:
        sys.exit("The anthropic package is missing: run  py -m pip install anthropic")
    except Exception as e:
        # No Claude key yet: watch and send anyway, each event as "Movement seen", so live view and the report work.
        log(f"Claude is not set up ({e}). Events are sent as 'Movement seen' until claude_api_key is in config.ini.")
        describer = type("Plain", (), {"describe": lambda self, jpegs, times: "Movement seen (not described: no Claude key)"})()
    log("Store Camera: asking saifsys for the cameras ...")
    watchers = {}
    threading.Thread(target=live_sender, args=(cfg, watchers), daemon=True).start()
    while True:
        r = saifsys(cfg, "GET", "cameras")
        if not r.get("ok"):
            log(f"saifsys: {r.get('error', 'no answer')}; trying again in 60 s")
            time.sleep(60)
            continue
        wanted = {c["id"]: c for c in r.get("cameras", [])}
        for cid in list(watchers):
            w = watchers[cid]
            if cid not in wanted or wanted[cid]["rtsp_url"] != w.cam["rtsp_url"]:
                log(f"{w.cam['name']}: stopped (turned off or changed on the page)")
                w.stop.set()
                del watchers[cid]
        for cid, cam in wanted.items():
            if cid in watchers:
                watchers[cid].cam = cam  # settings changed on the page reach the running watcher
            if cid not in watchers:
                watchers[cid] = Watcher(cfg, cam, describer)
                watchers[cid].start()
        if not wanted:
            log("No camera is switched on in Operations -> Store Camera. Checking again in 5 minutes.")
        time.sleep(CAMERAS_EVERY)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
