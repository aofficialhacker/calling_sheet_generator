@echo off
echo Checking Admin Follow-up Processor Task Status...
echo.

schtasks /query /tn "AdminFollowupProcessor" /fo LIST

echo.
echo Checking recent log entries...
echo.

if exist "logs\admin_followup_processor.log" (
    echo Last 10 lines from log file:
    powershell -command "Get-Content 'logs\admin_followup_processor.log' -Tail 10"
) else (
    echo No log file found yet. Task may not have run.
)

echo.
pause