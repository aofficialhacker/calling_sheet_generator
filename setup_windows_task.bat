@echo off
echo Setting up Admin Follow-up Processor Task...

schtasks /create /tn "AdminFollowupProcessor" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\calling_sheet_generator11\admin_follow_up_processor.php" /sc hourly /mo 1 /st 09:00

echo.
echo Task created successfully!
echo The processor will run every hour starting at 9:00 AM
echo.
echo To verify the task was created, run:
echo schtasks /query /tn "AdminFollowupProcessor"
echo.
echo To delete the task if needed, run:
echo schtasks /delete /tn "AdminFollowupProcessor" /f
echo.
pause