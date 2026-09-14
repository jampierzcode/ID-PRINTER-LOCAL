Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

baseDir = fso.GetParentFolderName(WScript.ScriptFullName)
logFile = baseDir & "\servidor.log"

' Mismo modo silencioso que iniciar_servidor.vbs: para el Programador de
' tareas, donde no hay nadie que pueda cerrar un MsgBox.
esSilencioso = False
For i = 0 To WScript.Arguments.Count - 1
    If LCase(WScript.Arguments(i)) = "/silent" Then esSilencioso = True
Next

' Detener PHP
shell.Run "taskkill /IM php.exe /F", 0, True

' Detener Caddy
shell.Run "taskkill /IM caddy.exe /F", 0, True

If esSilencioso Then
    Set logStream = fso.OpenTextFile(logFile, 8, True) ' 8 = ForAppending, True = crear si no existe
    logStream.WriteLine Now & " [OK] Servidor detenido (o ya no estaba corriendo)."
    logStream.Close
Else
    MsgBox "Servidor detenido correctamente.", vbInformation, "ID-Server"
End If
