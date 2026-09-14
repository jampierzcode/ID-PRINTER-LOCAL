Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

baseDir = fso.GetParentFolderName(WScript.ScriptFullName)
phpExe = baseDir & "\php\php.exe"
docRoot = baseDir & "\public"
caddyExe = baseDir & "\caddy.exe"
caddyfile = baseDir & "\Caddyfile"
certFile = baseDir & "\cert\certificado.pem"
keyFile = baseDir & "\cert\llave.pem"
caBundle = baseDir & "\php\cert\cacert.pem"
logFile = baseDir & "\servidor.log"

' Modo silencioso: para cuando lo dispara el Programador de tareas al
' arrancar Windows, donde no hay nadie que pueda cerrar un MsgBox. En ese
' modo los avisos se escriben en servidor.log en vez de mostrarse en
' pantalla. Doble clic manual sigue mostrando los mensajes de siempre.
esSilencioso = False
For i = 0 To WScript.Arguments.Count - 1
    If LCase(WScript.Arguments(i)) = "/silent" Then esSilencioso = True
Next

Sub Avisar(mensaje, esError)
    If esSilencioso Then
        etiqueta = "OK"
        If esError Then etiqueta = "ERROR"
        Set logStream = fso.OpenTextFile(logFile, 8, True) ' 8 = ForAppending, True = crear si no existe
        logStream.WriteLine Now & " [" & etiqueta & "] " & mensaje
        logStream.Close
    ElseIf esError Then
        MsgBox mensaje, vbCritical, "Error"
    Else
        MsgBox mensaje, vbInformation, "Servidor iniciado"
    End If
End Sub

' Verificar archivos requeridos
If Not fso.FileExists(phpExe) Then
    Avisar "No se encontró PHP en: " & phpExe, True
    WScript.Quit
End If
If Not fso.FileExists(certFile) Then
    Avisar "No se encontró el certificado: " & certFile, True
    WScript.Quit
End If
If Not fso.FileExists(keyFile) Then
    Avisar "No se encontró la llave privada: " & keyFile, True
    WScript.Quit
End If
If Not fso.FileExists(caddyExe) Then
    Avisar "No se encontró caddy.exe en: " & caddyExe, True
    WScript.Quit
End If
If Not fso.FileExists(caddyfile) Then
    Avisar "No se encontró el archivo Caddyfile en: " & caddyfile, True
    WScript.Quit
End If
If Not fso.FileExists(caBundle) Then
    Avisar "No se encontró el CA bundle en: " & caBundle, True
    WScript.Quit
End If

' Iniciar PHP en background (puerto 8080)
' Las rutas de CA para OpenSSL y cURL se pasan con -d para que se resuelvan
' segun la ubicacion real del proyecto (no dependen de C:\printerapp\...)
phpCmd = """" & phpExe & """" & _
         " -d openssl.cafile=""" & caBundle & """" & _
         " -d curl.cainfo=""" & caBundle & """" & _
         " -S 0.0.0.0:8080 -t """ & docRoot & """"
shell.Run phpCmd, 0, False

' Iniciar Caddy en background (usando Caddyfile)
caddyCmd = """" & caddyExe & """" & " run --config """ & caddyfile & """ --adapter caddyfile"
shell.Run caddyCmd, 0, False

Avisar "Servidor iniciado en segundo plano." & vbCrLf & _
       "Acceso LAN: https://[tu-ip]:9443" & vbCrLf & _
       "Acceso local: https://localhost:9443", False
