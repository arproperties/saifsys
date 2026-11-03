@echo off
rem Starts the entrance camera attendance. Put a shortcut to this in the Startup folder (Win+R, shell:startup).
cd /d "%~dp0"
py -m pip install --quiet opencv-python
py attendance_camera.py
pause
