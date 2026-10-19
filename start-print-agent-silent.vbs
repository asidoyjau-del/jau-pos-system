Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
psPath = scriptDir & "\pos-print-agent.ps1"

' Check if agent is already running on port 9100 before launching a new one
' This prevents duplicate agent processes on Windows 11
Dim alreadyRunning
alreadyRunning = False
On Error Resume Next
Dim http
Set http = CreateObject("MSXML2.XMLHTTP")
http.open "GET", "http://127.0.0.1:9100/status", False
http.setRequestHeader "User-Agent", "POS-Agent-Check/1.0"
http.send
If Err.Number = 0 Then
    If http.status = 200 Then
        alreadyRunning = True
    End If
End If
Err.Clear
On Error GoTo 0

If Not alreadyRunning Then
    WshShell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & psPath & """", 0, False
End If
