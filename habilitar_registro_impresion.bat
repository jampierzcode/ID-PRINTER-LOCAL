@echo off
REM ============================================================================
REM  Activa el registro de impresion de Windows (una sola vez por PC).
REM
REM  Sin este registro el ID-Printer sabe cuando Windows ACEPTO un ticket en la
REM  cola, pero no cuando lo termino de mandar a la impresora. Con el prendido,
REM  el POS puede mostrar si cada comanda, cuenta o corte de verdad se imprimio
REM  y cuanto tardo (evento 307 de Microsoft-Windows-PrintService/Operational).
REM
REM  Hay que ejecutarlo COMO ADMINISTRADOR: clic derecho > Ejecutar como
REM  administrador. No afecta la impresion ni requiere reiniciar.
REM ============================================================================

net session >nul 2>&1
if %errorlevel% neq 0 (
  echo.
  echo  Falta permiso: cierra esta ventana y ejecutalo con clic derecho
  echo  ^> "Ejecutar como administrador".
  echo.
  pause
  exit /b 1
)

wevtutil sl Microsoft-Windows-PrintService/Operational /e:true /ms:20971520
if %errorlevel% neq 0 (
  echo  No se pudo activar el registro de impresion. Codigo: %errorlevel%
  pause
  exit /b 1
)

echo.
echo  Listo: el registro de impresion de Windows quedo ACTIVADO.
echo  Estado actual:
wevtutil gl Microsoft-Windows-PrintService/Operational | findstr /i "enabled"
echo.
pause
