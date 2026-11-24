@echo off
rem Store Camera: watches the store cameras set in saifsys (Operations - Store Camera) and sends each event. Leave this window open.
cd /d "%~dp0"
py -m pip install --quiet opencv-python anthropic
py store_camera.py
pause
