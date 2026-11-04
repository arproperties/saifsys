"""
Entrance camera -> saifsys attendance.

Reads the EZVIZ camera's live video (RTSP), finds faces, matches them against
one photo per employee in faces/ and sends every match to
api/attendance_camera.php. saifsys keeps the first sighting of the day as the
check-in and the last as the check-out.

Face detection (YuNet) and recognition (SFace) are OpenCV's own models, so the
only thing to install is opencv-python. The two model files are downloaded
next to this script on first run.

Settings are in config.ini beside this file. Photos: HR adds them on the
Cameras page (People -> Photo, or the profile photo) and they are downloaded
into faces_hr/. Photos can also be put in faces/ by hand: faces/E00076.jpg,
one clear front-facing photo per employee, named by employee code. More than
one photo per person is fine: E00076.jpg, E00076_2.jpg.
"""

import base64
import configparser
import json
import os
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

# Take the camera's video over TCP. Over Wi-Fi the default (UDP) loses packets,
# the picture breaks up and the stream drops every few seconds.
os.environ.setdefault("OPENCV_FFMPEG_CAPTURE_OPTIONS", "rtsp_transport;tcp")
# The camera sends H.265; the decoder prints a warning for every damaged frame
# ("cu_qp_delta ... outside the valid range") and carries on. Keep them off the screen.
os.environ.setdefault("OPENCV_FFMPEG_LOGLEVEL", "-8")

import cv2
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))

MODELS = {
    "face_detection_yunet_2023mar.onnx":
        "https://media.githubusercontent.com/media/opencv/opencv_zoo/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx",
    "face_recognition_sface_2021dec.onnx":
        "https://media.githubusercontent.com/media/opencv/opencv_zoo/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx",
}

# How often to ask HR -> Cameras whether anything changed. The answer is a few
# bytes unless something did; then the whole list comes.
HR_CHECK_SECONDS = 15
SAME = "same"

# Cosine similarity. Named differently across OpenCV versions; the value is 0 in all of them.
FR_COSINE = getattr(cv2, "FaceRecognizerSF_FR_COSINE", getattr(getattr(cv2, "FaceRecognizerSF", None), "FR_COSINE", 0))


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def load_config():
    path = os.path.join(HERE, "config.ini")
    if not os.path.exists(path):
        sys.exit("config.ini is missing. Copy config.example.ini to config.ini and fill it in.")
    cfg = configparser.ConfigParser()
    cfg.read(path, encoding="utf-8")
    c = cfg["camera"]
    # One line per camera: rtsp_url, rtsp_url_2, rtsp_url_3 ... Empty lines are skipped.
    urls = [c.get(k).strip() for k in sorted(c, key=lambda k: (len(k), k))
            if k.startswith("rtsp_url") and c.get(k).strip()]
    return {
        "rtsp_urls": urls,
        "saifsys_url": c.get("saifsys_url").rstrip("/"),
        "camera_key": c.get("camera_key"),
        "match_threshold": c.getfloat("match_threshold", 0.40),
        "match_margin": c.getfloat("match_margin", 0.05),
        "resend_seconds": c.getint("resend_seconds", 120),
        "check_every_seconds": c.getfloat("check_every_seconds", 0.5),
    }


def ensure_models():
    for name, url in MODELS.items():
        path = os.path.join(HERE, name)
        if not os.path.exists(path):
            log(f"Downloading {name} ...")
            urllib.request.urlretrieve(url, path)
    return (os.path.join(HERE, "face_detection_yunet_2023mar.onnx"),
            os.path.join(HERE, "face_recognition_sface_2021dec.onnx"))


def biggest_face(detector, img):
    h, w = img.shape[:2]
    detector.setInputSize((w, h))
    _, faces = detector.detect(img)
    if faces is None or len(faces) == 0:
        return None
    return max(faces, key=lambda f: f[2] * f[3])


# faces/ holds photos put on this PC by hand; faces_hr/ the ones HR keeps on the
# Cameras page (or profile photos), downloaded by sync_hr_faces.
FACE_FOLDERS = ("faces", "faces_hr")


def faces_signature():
    """Names, sizes and times of the photos: changes when a photo is added, replaced or removed."""
    out = []
    for sub in FACE_FOLDERS:
        folder = os.path.join(HERE, sub)
        if os.path.isdir(folder):
            out += [(sub, e.name, e.stat().st_size, e.stat().st_mtime) for e in os.scandir(folder) if e.is_file()]
    return tuple(sorted(out))


def load_faces(detector, recognizer):
    """Employee code -> list of face features, from faces/ and faces_hr/."""
    known = {}
    for folder, name in [(os.path.join(HERE, sub), n) for sub in FACE_FOLDERS if os.path.isdir(os.path.join(HERE, sub))
                         for n in sorted(os.listdir(os.path.join(HERE, sub)))]:
        stem, ext = os.path.splitext(name)
        if ext.lower() not in (".jpg", ".jpeg", ".png"):
            continue
        code = stem.split("_")[0].upper()
        img = cv2.imdecode(np.fromfile(os.path.join(folder, name), dtype=np.uint8), cv2.IMREAD_COLOR)
        if img is None:
            log(f"  {name}: could not read the image, skipped")
            continue
        face = biggest_face(detector, img)
        if face is None:
            log(f"  {name}: no face found, skipped")
            continue
        feature = recognizer.feature(recognizer.alignCrop(img, face))
        known.setdefault(code, []).append(feature)
    return known


class LatestFrame:
    """Keeps reading the stream in the background so we always look at the newest frame, not a backlog."""

    def __init__(self, name, url, cam_id=None):
        self.name = name
        self.url = url
        self.cam_id = cam_id        # the camera's id in HR; None for a config.ini camera
        self.last_frame_at = 0.0    # when video last came through, for the status report
        self.frame = None
        self.newest = None          # the same frame, but not taken by get(): for the Live view
        self.lock = threading.Lock()
        self.stopped = False
        threading.Thread(target=self._run, daemon=True).start()

    def stop(self):
        """Let go of the camera: it was changed or removed in HR."""
        self.stopped = True

    def _run(self):
        while not self.stopped:
            cap = cv2.VideoCapture(self.url, cv2.CAP_FFMPEG)
            if not cap.isOpened():
                log(f"{self.name}: cannot open the stream; retrying in 10 s")
                time.sleep(10)
                continue
            log(f"{self.name}: stream connected")
            # A damaged frame fails to read but the next one is usually fine.
            # Reconnecting straight away starts mid-video and damages more, so
            # only give up when no picture has come through for 10 seconds.
            last_ok = time.time()
            while not self.stopped:
                ok, frame = cap.read()
                if ok:
                    last_ok = self.last_frame_at = time.time()
                    with self.lock:
                        self.frame = self.newest = frame
                elif time.time() - last_ok > 10:
                    log(f"{self.name}: no picture for 10 s; reconnecting")
                    break
                else:
                    time.sleep(0.05)
            cap.release()
            if not self.stopped:
                time.sleep(2)
        log(f"{self.name}: stopped")

    def get(self):
        with self.lock:
            frame, self.frame = self.frame, None
        return frame

    def peek(self):
        """The newest frame, if one came in the last few seconds; left for get()."""
        if time.time() - self.last_frame_at > 5:
            return None
        with self.lock:
            return self.newest


# The Live box on HR -> Cameras: while HR watches a camera, send its picture
# this often. When nobody watches, only ask now and then (a few bytes).
LIVE_SEND_SECONDS = 2
LIVE_ASK_SECONDS = 5


def capture_face(detector, frame, area=None):
    """HR's Capture in the face photo popup: (base64 JPEG of the face, "") or (None, why not).

    The face must be the clear main one, big enough and looking at the camera,
    because every later match is compared with it. area is the box HR drew
    around the person, [x, y, w, h] as fractions of the picture: only that part
    is looked at, enlarged up to twice when it is small.
    """
    if area:
        fh, fw = frame.shape[:2]
        ax, ay, aw, ah = (float(v) for v in area)
        x0, y0 = int(ax * fw), int(ay * fh)
        x1, y1 = min(fw, int((ax + aw) * fw)), min(fh, int((ay + ah) * fh))
        if x1 - x0 < 20 or y1 - y0 < 20:
            return None, "The box is too small. Draw it around the person's head and shoulders."
        frame = frame[y0:y1, x0:x1]
        if frame.shape[1] < 640:
            scale = min(2.0, 640 / frame.shape[1])
            frame = cv2.resize(frame, (int(frame.shape[1] * scale), int(frame.shape[0] * scale)), interpolation=cv2.INTER_CUBIC)
    h, w = frame.shape[:2]
    detector.setInputSize((w, h))
    _, faces = detector.detect(frame)
    if faces is None or len(faces) == 0:
        return None, "No face found. Ask the person to stand closer, facing the camera."
    faces = sorted(faces, key=lambda f: f[2] * f[3], reverse=True)
    face = faces[0]
    # Someone else close by would make it unclear whose photo this is.
    if len(faces) > 1 and faces[1][2] * faces[1][3] > face[2] * face[3] * 0.5:
        return None, "More than one person in front of the camera. Only the person should stand close."
    x, y, fw, fh = (int(v) for v in face[:4])
    if fw < 70:
        return None, "The face is too small. Ask the person to come closer to the camera."
    # Looking straight: the nose sits about halfway between the eyes.
    eye_r, eye_l, nose = face[4:6], face[6:8], face[8:10]
    eyes = abs(eye_l[0] - eye_r[0])
    if eyes <= 0 or abs(nose[0] - (eye_l[0] + eye_r[0]) / 2) / eyes > 0.25:
        return None, "The face is turned. Ask the person to look straight at the camera."
    x0, x1 = max(0, x - int(fw * 0.6)), min(w, x + fw + int(fw * 0.6))
    y0, y1 = max(0, y - int(fh * 0.6)), min(h, y + fh + int(fh * 0.5))
    crop = frame[y0:y1, x0:x1]
    if crop.shape[1] > 600:
        crop = cv2.resize(crop, (600, int(crop.shape[0] * 600 / crop.shape[1])))
    ok, jpg = cv2.imencode(".jpg", crop, [cv2.IMWRITE_JPEG_QUALITY, 90])
    if not ok:
        return None, "The photo could not be made. Try again."
    return base64.b64encode(jpg.tobytes()).decode(), ""


def live_loop(cfg, streams, det_path):
    """Send frames only for the cameras HR has open in Live, and answer Capture. View only: no attendance."""
    headers = {"X-Camera-Key": cfg["camera_key"]}
    # Its own detector: the main loop's is busy on another thread.
    detector = cv2.FaceDetectorYN.create(det_path, "", (320, 320), 0.8, 0.3, 5000)
    url = cfg["saifsys_url"] + "/api/attendance_camera.php"

    def post(payload):
        req = urllib.request.Request(url, data=json.dumps(payload).encode(),
                                     headers={**headers, "Content-Type": "application/json"}, method="POST")
        with urllib.request.urlopen(req, timeout=10) as r:
            body = json.loads(r.read().decode() or "{}")
        areas.clear()
        areas.update(body.get("capture_area") or {})
        return body.get("want") or [], body.get("capture") or []

    want, capture = [], []
    areas = {}  # camera id (text) -> the box HR drew for a waiting capture
    while True:
        if not want:
            time.sleep(LIVE_ASK_SECONDS)
            try:
                with urllib.request.urlopen(urllib.request.Request(url + "?action=live", headers=headers), timeout=10) as r:
                    body = json.loads(r.read().decode() or "{}")
                want, capture = body.get("want") or [], body.get("capture") or []
                areas.clear()
                areas.update(body.get("capture_area") or {})
            except Exception:
                want, capture = [], []  # an older saifsys without Live, or offline: ask again later
            continue
        # A capture is answered straight away; frames go every LIVE_SEND_SECONDS.
        time.sleep(0.5 if capture else LIVE_SEND_SECONDS)
        answer = None
        for s in list(streams.values()):
            if s.cam_id not in want and s.cam_id not in capture:
                continue
            frame = s.peek()
            if frame is None:
                continue  # no picture right now; keep trying while HR watches
            try:
                if s.cam_id in capture:
                    jpeg, why = capture_face(detector, frame, areas.get(str(s.cam_id)))
                    answer = post({"live_capture": {"camera_id": s.cam_id, "jpeg": jpeg, "error": why}})
                    log(f"{s.name}: face photo taken for HR" if jpeg else f"{s.name}: face photo not taken: {why}")
                if s.cam_id in want:
                    small = frame
                    if small.shape[1] > 640:
                        small = cv2.resize(small, (640, int(small.shape[0] * 640 / small.shape[1])))
                    ok, jpg = cv2.imencode(".jpg", small, [cv2.IMWRITE_JPEG_QUALITY, 60])
                    if ok:
                        answer = post({"live_frame": {"camera_id": s.cam_id, "jpeg": base64.b64encode(jpg.tobytes()).decode()}})
            except Exception:
                answer = ([], [])
        # Nothing sent (no picture, or a camera this PC does not have): ask again instead.
        want, capture = answer if answer is not None else ([], [])


def snapshot(frame, face, label):
    """Head and shoulders around one face, labelled, as base64 JPEG (about 20-40 KB).

    saifsys keeps it as the day's in or out photo for HR. Only the person is
    cropped, not the whole room.
    """
    x, y, w, h = (int(v) for v in face[:4])
    fh, fw = frame.shape[:2]
    x0, x1 = max(0, x - int(w * 1.2)), min(fw, x + w + int(w * 1.2))
    y0, y1 = max(0, y - int(h * 0.7)), min(fh, y + h + int(h * 1.5))
    crop = frame[y0:y1, x0:x1].copy()
    if crop.size == 0:
        return None
    cv2.rectangle(crop, (x - x0, y - y0), (x - x0 + w, y - y0 + h), (0, 200, 0), 2)
    if crop.shape[1] != 400:
        scale = 400 / crop.shape[1]
        crop = cv2.resize(crop, (400, max(1, int(crop.shape[0] * scale))))
    crop = cv2.copyMakeBorder(crop, 0, 24, 0, 0, cv2.BORDER_CONSTANT, value=(0, 0, 0))
    cv2.putText(crop, label, (6, crop.shape[0] - 7), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1, cv2.LINE_AA)
    ok, jpg = cv2.imencode(".jpg", crop, [cv2.IMWRITE_JPEG_QUALITY, 80])
    return base64.b64encode(jpg.tobytes()).decode() if ok else None


def send(cfg, code, seen_at, cam_id=None, photo=None):
    payload = {"employee_code": code, "seen_at": int(seen_at), "camera_id": cam_id}
    if photo:
        payload["snapshot"] = photo
    body = json.dumps(payload).encode()
    req = urllib.request.Request(
        cfg["saifsys_url"] + "/api/attendance_camera.php",
        data=body,
        headers={"Content-Type": "application/json", "X-Camera-Key": cfg["camera_key"]},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return True, json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        # 4xx is a real answer (unknown code, bad key): retrying will not help.
        return e.code < 500, {"error": f"HTTP {e.code}: {e.read().decode(errors='replace')[:200]}"}
    except Exception as e:  # no internet, saifsys down: keep it and try again
        return False, {"error": str(e)}


def device_name(url):
    """The name the camera gives itself on the network, e.g. C8c_BH5929142_EZVIZ (model + serial)."""
    host = urllib.parse.urlsplit(url).hostname or ""
    try:
        return socket.gethostbyaddr(host)[0].split(".")[0]
    except OSError:
        return ""


# Windows' own answer for this PC's networks: each address with its adapter,
# the Mobile Hotspot's name and band, and the Wi-Fi the PC itself is on.
NETWORKS_PS = r"""
$out = @{ addresses = @(); hotspot = $null; wifi = (netsh wlan show interfaces) -join "`n" }
foreach ($a in Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue) {
  $out.addresses += @{ ip = $a.IPAddress; alias = $a.InterfaceAlias }
}
try {
  [Windows.Networking.Connectivity.NetworkInformation,Windows.Networking.Connectivity,ContentType=WindowsRuntime] | Out-Null
  [Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager,Windows.Networking.NetworkOperators,ContentType=WindowsRuntime] | Out-Null
  $p = [Windows.Networking.Connectivity.NetworkInformation]::GetInternetConnectionProfile()
  $m = [Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager]::CreateFromConnectionProfile($p)
  $c = $m.GetCurrentAccessPointConfiguration()
  $out.hotspot = @{ on = ("$($m.TetheringOperationalState)" -eq 'On'); ssid = $c.Ssid; band = "$($c.Band)" }
} catch {}
$out | ConvertTo-Json -Depth 4 -Compress
"""

BANDS = {"TwoPointFourGigahertz": "2.4 GHz", "FiveGigahertz": "5 GHz", "SixGigahertz": "6 GHz", "Auto": "2.4 or 5 GHz (auto)"}


def pc_networks():
    """This PC's networks from Windows, or None when PowerShell cannot answer."""
    try:
        r = subprocess.run(["powershell", "-NoProfile", "-NonInteractive", "-Command", NETWORKS_PS],
                           capture_output=True, text=True, timeout=30,
                           creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        return json.loads(r.stdout)
    except Exception:
        return None


def wifi_of(url, nets):
    """(network, band, camera_ip) for one camera, worked out from the PC's side.

    The camera does not say which Wi-Fi it is on, but the PC knows which of its
    own networks it reaches the camera through: its hotspot (name and band are
    exact) or the Wi-Fi it is itself connected to (the router picks the
    camera's band, so only the PC's own band is known).
    """
    host = urllib.parse.urlsplit(url).hostname or ""
    try:
        cam_ip = socket.gethostbyname(host)
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect((cam_ip, 554))  # no packet is sent; this only picks the route
        local_ip = s.getsockname()[0]
        s.close()
    except OSError:
        return "", "", ""
    if not nets:
        return "", "", cam_ip
    addresses = nets.get("addresses") or []
    if isinstance(addresses, dict):
        addresses = [addresses]
    alias = next((a.get("alias", "") for a in addresses if a.get("ip") == local_ip), "")

    hotspot = nets.get("hotspot") or {}
    if hotspot.get("on") and local_ip == "192.168.137.1":  # Windows' Mobile Hotspot always uses this address
        return f"{hotspot.get('ssid', '')} (this PC's hotspot)", BANDS.get(hotspot.get("band", ""), hotspot.get("band", "")), cam_ip

    # `netsh wlan show interfaces`: blocks of "Name : WiFi", "SSID : ...", "Band : 5 GHz".
    for block in (nets.get("wifi") or "").split("\n\n"):
        info = {}
        for line in block.splitlines():
            key, sep, value = line.partition(":")
            if sep:
                info.setdefault(key.strip(), value.strip())
        if info.get("Name") == alias and info.get("SSID"):
            band = info.get("Band", "")
            return f"{info['SSID']} (same Wi-Fi as this PC)", (f"Router decides; this PC is on {band}" if band else ""), cam_ip
    return (f"Wired / other network ({alias})" if alias else "Other network"), "", cam_ip


def report_status(cfg, streams):
    """Tell saifsys each HR camera's device name, Wi-Fi and whether video is coming through.

    Runs in its own thread: a name lookup can take a few seconds and must not
    hold up face checking.
    """
    now = time.time()
    nets = pc_networks()
    status = []
    for s in streams:
        if s.cam_id is None:
            continue
        network, band, cam_ip = wifi_of(s.url, nets)
        status.append({"id": s.cam_id, "device_name": device_name(s.url), "online": now - s.last_frame_at < 600,
                       "network": network, "band": band, "camera_ip": cam_ip})
    if not status:
        return
    req = urllib.request.Request(
        cfg["saifsys_url"] + "/api/attendance_camera.php",
        data=json.dumps({"camera_status": status}).encode(),
        headers={"Content-Type": "application/json", "X-Camera-Key": cfg["camera_key"]},
        method="POST",
    )
    try:
        urllib.request.urlopen(req, timeout=15).close()
    except Exception as e:
        log(f"Could not send the camera status to saifsys: {e}")


def fetch_cameras(cfg):
    """From HR -> Administration -> Cameras: ({name: (id, rtsp_url)}, ticked employee codes,
    resend seconds or None for an older saifsys).

    The codes are None when saifsys does not have the People list yet (then
    every photo is used). The whole answer is None when saifsys cannot be
    reached or does not have the cameras table yet; the caller then keeps what
    it has, or falls back to config.ini. SAME when nothing changed since the
    last answer (saifsys then sends only that, not the whole list).
    """
    since = cfg.get("list_version")
    req = urllib.request.Request(
        cfg["saifsys_url"] + "/api/attendance_camera.php?action=cameras"
        + (f"&since={urllib.parse.quote(since)}" if since else ""),
        headers={"X-Camera-Key": cfg["camera_key"]},
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            body = json.loads(r.read().decode() or "{}")
    except Exception as e:
        log(f"Could not read the camera list from saifsys: {e}")
        return None
    if not body.get("ok") or not body.get("managed"):
        return None
    if body.get("changed") is False:
        return SAME
    cfg["list_version"] = body.get("version")
    if body.get("faces") is not None:
        sync_hr_faces(cfg, body["faces"])
    people = body.get("people")
    resend = body.get("resend_seconds")
    return ({c["name"]: (c["id"], c["rtsp_url"]) for c in body.get("cameras", [])},
            None if people is None else {p.upper() for p in people},
            int(resend) if resend else None)


def sync_hr_faces(cfg, faces):
    """Keep faces_hr/ the same as the photos HR keeps: {employee code: version}.

    A changed photo is downloaded again, a removed one deleted. The versions
    are remembered in faces_hr/versions.json so a restart does not download
    everything again.
    """
    folder = os.path.join(HERE, "faces_hr")
    os.makedirs(folder, exist_ok=True)
    vfile = os.path.join(folder, "versions.json")
    try:
        with open(vfile, encoding="utf-8") as f:
            have = json.load(f)
    except Exception:
        have = {}
    for code in list(have):
        if code not in faces:
            try:
                os.remove(os.path.join(folder, code + ".jpg"))
            except OSError:
                pass
            del have[code]
            log(f"{code}: photo removed in HR")
    for code, version in faces.items():
        path = os.path.join(folder, code + ".jpg")
        if have.get(code) == version and os.path.exists(path):
            continue
        req = urllib.request.Request(
            cfg["saifsys_url"] + "/api/attendance_camera.php?action=face&code=" + urllib.parse.quote(code),
            headers={"X-Camera-Key": cfg["camera_key"]},
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                data = r.read()
        except Exception as e:
            log(f"{code}: could not download the photo from HR: {e}")
            continue
        with open(path + ".tmp", "wb") as f:
            f.write(data)
        os.replace(path + ".tmp", path)
        have[code] = version
        log(f"{code}: photo downloaded from HR")
    with open(vfile, "w", encoding="utf-8") as f:
        json.dump(have, f)


def use_resend(cfg, seconds):
    """HR -> Cameras -> How often; config.ini's resend_seconds when saifsys does not send it."""
    if seconds and seconds != cfg["resend_seconds"]:
        cfg["resend_seconds"] = seconds
        log(f"Recording the same person at most every {seconds // 60} min")


def only_ticked(known, people):
    """The photos to match against: only people ticked for camera attendance in HR."""
    if people is None:
        return known
    active = {code: f for code, f in known.items() if code in people}
    missing = sorted(people - set(known))
    log(f"Matching {len(active)} people ticked for camera: {', '.join(sorted(active)) or 'none'}")
    if missing:
        log(f"Ticked for camera but no usable photo (add one in HR -> Cameras -> People): {', '.join(missing)}")
    return active


def main():
    cfg = load_config()
    det_path, rec_path = ensure_models()
    detector = cv2.FaceDetectorYN.create(det_path, "", (320, 320), 0.8, 0.3, 5000)
    recognizer = cv2.FaceRecognizerSF.create(rec_path, "")

    photos_seen = faces_signature()
    known = load_faces(detector, recognizer)
    if not known:
        log("No usable photos yet. Add them in HR -> Cameras -> People -> Photo;"
            " they are picked up without a restart.")
    else:
        log(f"Loaded {len(known)} employees: {', '.join(sorted(known))}")

    # Cameras come from HR -> Administration -> Cameras. config.ini is only the
    # fallback, for a saifsys that does not have that page yet.
    from_config = {f"Camera {i}": (None, url) for i, url in enumerate(cfg["rtsp_urls"], 1)}
    fetched = fetch_cameras(cfg)
    # The photos HR keeps were just brought down: read them in now, not in 15 s.
    if faces_signature() != photos_seen:
        photos_seen = faces_signature()
        known = load_faces(detector, recognizer)
        log(f"Loaded {len(known)} employees: {', '.join(sorted(known)) or 'none'}")
    people = None
    if fetched is None:
        log("Using the cameras in config.ini")
        wanted = from_config
    else:
        wanted, people, resend = fetched
        log(f"Cameras from HR: {', '.join(wanted) or 'none switched on'}")
        use_resend(cfg, resend)
    active = only_ticked(known, people)
    if not wanted:
        log("No cameras yet. Add one in HR -> Administration -> Cameras; picked up within 15 seconds.")
    streams = {name: LatestFrame(name, url, cam_id) for name, (cam_id, url) in wanted.items()}
    threading.Thread(target=live_loop, args=(cfg, streams, det_path), daemon=True).start()
    next_camera_check = time.time() + HR_CHECK_SECONDS
    next_status = time.time() + 60  # first report once the streams have had a minute to connect
    # Shared by every camera: someone walking between rooms is still sent once per resend_seconds.
    last_sent = {}   # code -> time we last told saifsys
    pending = []     # (code, seen_at, cam_id, photo) not yet delivered

    while True:
        time.sleep(cfg["check_every_seconds"])

        # Pick up cameras HR added, changed (a new code or address) or switched off.
        if time.time() >= next_camera_check:
            next_camera_check = time.time() + HR_CHECK_SECONDS
            fetched = fetch_cameras(cfg)  # also brings down photos HR added, changed or removed
            latest = None
            refresh = False
            if fetched is not None and fetched != SAME:
                latest, latest_people, resend = fetched
                use_resend(cfg, resend)
                # Someone ticked or unticked in HR -> Cameras -> People.
                if latest_people != people:
                    people = latest_people
                    refresh = True
            # A photo added, replaced or removed (HR or faces/): read them all again.
            # Before the ticked list is worked out, so a person ticked together
            # with their new photo is not reported as having none.
            if faces_signature() != photos_seen:
                photos_seen = faces_signature()
                known = load_faces(detector, recognizer)
                log(f"Photos changed; loaded {len(known)} employees: {', '.join(sorted(known)) or 'none'}")
                refresh = True
            if refresh:
                active = only_ticked(known, people)
            if latest is not None and latest != {n: (s.cam_id, s.url) for n, s in streams.items()}:
                for name, s in list(streams.items()):
                    if latest.get(name) != (s.cam_id, s.url):
                        s.stop()
                        del streams[name]
                for name, (cam_id, url) in latest.items():
                    if name not in streams:
                        log(f"{name}: picked up from HR")
                        streams[name] = LatestFrame(name, url, cam_id)

        if time.time() >= next_status:
            next_status = time.time() + 300
            threading.Thread(target=report_status, args=(cfg, list(streams.values())), daemon=True).start()

        # Anything that could not be delivered earlier goes first.
        still = []
        for item in pending:
            ok, _ = send(cfg, *item)
            if not ok:
                still.append(item)
        pending = still[-500:]

        for stream in list(streams.values()):
            frame = stream.get()
            if frame is None:
                continue
            h, w = frame.shape[:2]
            detector.setInputSize((w, h))
            _, faces = detector.detect(frame)
            if faces is None:
                continue

            now = time.time()
            for face in faces:
                feature = recognizer.feature(recognizer.alignCrop(frame, face))
                scores = {code: max(recognizer.match(feature, f, FR_COSINE) for f in features)
                          for code, features in active.items() if features}
                if not scores:
                    continue
                best_code = max(scores, key=scores.get)
                best_score = scores[best_code]
                runner_up = max((v for c, v in scores.items() if c != best_code), default=0.0)
                if best_score < cfg["match_threshold"]:
                    continue
                # With a low threshold two people can both pass; only a clear winner counts.
                if best_score - runner_up < cfg["match_margin"]:
                    continue
                if now - last_sent.get(best_code, 0) < cfg["resend_seconds"]:
                    continue
                last_sent[best_code] = now
                photo = snapshot(frame, face, f"{stream.name}  {time.strftime('%d %b %H:%M', time.localtime(now))}  match {best_score:.2f}")
                ok, answer = send(cfg, best_code, now, stream.cam_id, photo)
                log(f"{stream.name}: saw {best_code} (match {best_score:.2f}) -> {answer}")
                if not ok:
                    pending.append((best_code, now, stream.cam_id, photo))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
