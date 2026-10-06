@echo off
setlocal
title JEV UFC Profit Lab
cd /d "%~dp0"
echo.
echo JEV UFC PROFIT LAB
echo Jev kljuc se samodejno prebere iz .env.
echo Aplikacija: http://127.0.0.1:8787
echo Zapres jo s Ctrl+C.
start "" "http://127.0.0.1:8787"
php -S 127.0.0.1:8787 -t "%~dp0"
endlocal
