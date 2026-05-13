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

' Verificar archivos requeridos
If Not fso.FileExists(phpExe) Then
    MsgBox "No se encontró PHP en: " & phpExe, vbCritical, "Error"
    WScript.Quit
End If
If Not fso.FileExists(certFile) Then
    MsgBox "No se encontró el certificado: " & certFile, vbCritical, "Error"
    WScript.Quit
End If
If Not fso.FileExists(keyFile) Then
    MsgBox "No se encontró la llave privada: " & keyFile, vbCritical, "Error"
    WScript.Quit
End If
If Not fso.FileExists(caddyExe) Then
    MsgBox "No se encontró caddy.exe en: " & caddyExe, vbCritical, "Error"
    WScript.Quit
End If
If Not fso.FileExists(caddyfile) Then
    MsgBox "No se encontró el archivo Caddyfile en: " & caddyfile, vbCritical, "Error"
    WScript.Quit
End If
If Not fso.FileExists(caBundle) Then
    MsgBox "No se encontró el CA bundle en: " & caBundle, vbCritical, "Error"
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

MsgBox "Servidor iniciado en segundo plano." & vbCrLf & _
       "Acceso LAN: https://[tu-ip]:9443" & vbCrLf & _
       "Acceso local: https://localhost:9443", vbInformation, "Servidor iniciado"