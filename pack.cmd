@echo off
if exist Plugin.php (
for /F "tokens=3" %%i in ('type Plugin.php ^| findstr @version') do (SET VERSION=%%i)
for /F "tokens=3" %%i in ('type Plugin.php ^| findstr @package') do (SET THEME=%%i)
) else (
for /F "tokens=3" %%i in ('type index.php ^| findstr @version') do (SET VERSION=%%i)
for /F "tokens=3" %%i in ('type index.php ^| findstr @package') do (SET THEME=%%i)
)
if not exist pack (mkdir pack)
SET ARCHIVEPATH=.\pack\%THEME%.%VERSION%.%date:~0,4%%date:~5,2%%date:~8,2%.zip"
if exist %ARCHIVEPATH% (del /s /f /q %ARCHIVEPATH%)
C:\Progra~1\WinRAR\WinRar.exe a -afzip -r -x*\.git -x*\.git\* -x*\node_modules -x*\node_modules\* -x*\.idea -x*\files -x*\files\*  -x*\pack.cmd -x*\pack\* -x*\pack  -x*\.gitignore %ARCHIVEPATH% ..\%THEME%