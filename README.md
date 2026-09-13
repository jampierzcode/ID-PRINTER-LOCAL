# ID-PRINTER LOCAL

Servidor local portátil para Windows que permite a una web remota imprimir tickets en impresoras conectadas a la PC del restaurante. Integra PHP + Caddy y expone una API REST en `https://<ip-local>/nprint`.

Arquitectura tipo monorepo: `caddy.exe` levanta HTTPS en el puerto `9443` y hace reverse-proxy al servidor PHP embebido (puerto interno `8080`), que sirve a la vez:
- **Frontend** (Vite) en `public/`
- **Backend / API** (PHP + Slim) en `public/nprint/`

---

## Requisitos previos

- Windows 8 / 10 / 11
- Permisos de administrador en la PC del restaurante
- IP local fija (se configura más abajo)
- [Git para Windows](https://git-scm.com/download/win) — solo necesario para clonar y actualizar
- [mkcert](https://github.com/FiloSottile/mkcert/releases) — para generar el certificado SSL local

> No requiere instalar PHP, Composer ni Caddy: todos los binarios vienen empaquetados en el repo.

---

## Instalación

### 1. Clonar el repositorio

Abre `cmd` o `PowerShell` y clona en una ubicación estable, por ejemplo `C:\printerapp`:

```bat
mkdir C:\printerapp
cd C:\printerapp
git clone https://github.com/jampierzcode/ID-PRINTER-LOCAL.git
```

Te quedará la carpeta `C:\printerapp\ID-PRINTER-LOCAL\`.

### 2. Crear el archivo `.env`

Dentro de `public\nprint\` copia `.env.example` y renómbralo a `.env`. Ajusta las URLs solo si el cliente apunta a un backend distinto del default.

```bat
cd public\nprint
copy .env.example .env
```

### 3. Configurar IP fija en la PC

La PC del restaurante **debe tener IP local fija**, porque el certificado SSL se emite para esa IP.

1. Abre una terminal y ejecuta `ipconfig`. Anota tu **Dirección IPv4** y la **Puerta de enlace predeterminada**.
2. Panel de control → Centro de redes y recursos compartidos → tu conexión (Wi-Fi o Ethernet) → **Propiedades** → **Protocolo de Internet versión 4 (TCP/IPv4)** → **Propiedades**.
3. Marca *"Usar la siguiente dirección IP"* y llena:
   - **Dirección IP**: la IPv4 que copiaste (ej. `10.158.50.19`)
   - **Máscara de subred**: `255.255.255.0`
   - **Puerta de enlace predeterminada**: la que copiaste
   - **DNS preferido**: `8.8.8.8`
   - **DNS alternativo**: `8.8.4.4`
4. Acepta y espera unos segundos a que la red se reinicie.

### 4. Compartir las impresoras

Para que las impresoras sean visibles desde la API:

1. Configuración → Bluetooth y dispositivos → Impresoras y escáneres → selecciona la impresora.
2. **Propiedades de impresora** → pestaña **Compartir** → marca *"Compartir esta impresora"* → Aceptar.

### 5. Generar el certificado SSL con mkcert

El navegador bloquea peticiones `https → http`. Como la web remota corre en HTTPS, la API local también debe estar en HTTPS, lo que requiere un certificado autofirmado válido para tu IP fija.

1. Descarga `mkcert-v1.4.4-windows-amd64.exe` desde la [página de releases](https://github.com/FiloSottile/mkcert/releases) y guárdalo en `C:\` como `mkcert.exe`.
2. Abre `cmd` **como administrador**:
   ```bat
   cd C:\
   mkcert.exe -install
   ```
   Esto instala una CA local en el almacén de confianza de Windows.
3. Genera el certificado para tu IP fija (reemplaza por la tuya):
   ```bat
   mkcert 10.158.50.19
   mkcert -pkcs12 10.158.50.19
   ```
   La contraseña del `.p12` es `changeit`.
4. En `C:\` aparecerán 3 archivos. Cópialos a la carpeta `cert\` del proyecto y **renómbralos**:

   | Archivo generado              | Renombrar a       |
   |-------------------------------|-------------------|
   | `10.158.50.19.pem`            | `certificado.pem` |
   | `10.158.50.19-key.pem`        | `llave.pem`       |
   | `10.158.50.19.p12`            | *(no renombrar)*  |

5. Haz **doble clic en el `.p12`** para importarlo. En el asistente:
   - **Ubicación del almacén**: *Usuario actual* → Siguiente.
   - **Archivo a importar**: deja el `.p12` seleccionado → Siguiente.
   - **Contraseña**: `changeit`. Deja marcado *"Incluir todas las propiedades extendidas"* → Siguiente.
   - **Almacén de certificados**: ⚠️ **NO dejes la opción automática**. Marca *"Colocar todos los certificados en el siguiente almacén"* → **Examinar** → selecciona **"Entidades de certificación raíz de confianza"** (en inglés: *Trusted Root Certification Authorities*) → Aceptar → Siguiente → Finalizar.
   - Windows mostrará una advertencia de seguridad — acepta con **Sí**.

> Si el navegador no reconoce la conexión como segura, **reinicia la PC** y vuelve a abrir el sitio. Si después de reiniciar Chrome/Edge sigue marcando "No seguro" pero la API responde bien con Postman/cURL, es caché del navegador: **borra el caché** del sitio (DevTools → pestaña Application/Aplicación → Storage → Clear site data) o prueba en una ventana de incógnito.

### 5.1 Distribuir el certificado a los demás dispositivos (POS Cash, POS Comandero, tablets, etc.)

El certificado lo instalas **una vez en el servidor de impresión** (la PC con ID-Printer corriendo). Pero **cada dispositivo cliente** que vaya a consumir la API local (POS Cash en otra laptop, POS Comandero en una tablet Android, otra PC de la barra, etc.) necesita reconocer ese mismo certificado como confiable; de lo contrario el navegador del cliente seguirá bloqueando las peticiones `https → https` por falta de cadena de confianza.

**Qué copiar:** el archivo `cert\certificado.pem` del servidor (o equivalentemente el `.p12` original que generó mkcert). Para máxima compatibilidad con Windows antiguos y tablets, **renómbralo con extensión `.crt`**:

```
certificado.pem  →  certificado.crt
```

Algunos Windows (especialmente 8 / Server) y varios Android no abren el `.pem` directamente con el instalador gráfico, pero sí reconocen `.crt`. El contenido es idéntico, solo cambia la extensión.

**Instalación por plataforma:**

- **Windows (cliente POS Cash, otra PC)**: doble clic en `certificado.crt` → *Instalar certificado* → **Usuario actual** → *Colocar todos los certificados en el siguiente almacén* → **Entidades de certificación raíz de confianza** → Finalizar → aceptar advertencia → **reiniciar la PC**.
- **Android (tablet POS Comandero)**: copia el `.crt` al dispositivo (USB, Drive, correo). Ajustes → *Seguridad* → *Cifrado y credenciales* → *Instalar un certificado* → *Certificado CA* → seleccionar el archivo → confirmar. En algunas marcas la ruta es Ajustes → Seguridad → *Instalar desde almacenamiento*. Después **reinicia la tablet**.
- **iPad / iPhone**: envía el `.crt` por correo o AirDrop → ábrelo → instala el perfil → luego Ajustes → General → *Acerca de* → *Configuración de confianza de certificados* → activa el switch del cert recién instalado → **reinicia**.

**Notas importantes:**

- Después de instalar el cert en cualquier dispositivo, **siempre reinicia**. Sin reiniciar el sistema operativo no refresca el almacén de confianza.
- Si tras reiniciar, **Chrome/Edge sigue mostrando "No seguro"** aunque la API responda correctamente: es caché HSTS o de cert del navegador. Borra el caché del sitio (DevTools → Application → Clear site data) o usa modo incógnito. El certificado **sí está bien instalado**; lo confirma que Postman, cURL o las llamadas `fetch` desde tu app funcionan sin errores de SSL.
- Si renuevas el certificado (por ejemplo porque cambió la IP fija), debes **reemplazarlo en todos los dispositivos** y reiniciar cada uno. No queda otra.

### 6. Iniciar el servidor

Ejecuta `Id-server.exe` (doble clic). Aparecerá la ventana **ID-Server**:

- **▶ Play**: inicia el servidor (estado pasa a verde).
- **■ Detener**: lo apaga.
- **⚙ Engranajes**: abre el frontend en el navegador.

Alternativamente puedes ejecutar `iniciar_servidor.vbs` directamente (sin GUI).

### 7. Configurar la IP dentro de la app

1. Abre `https://<tu-ip-fija>:9443/` en el navegador (ej. `https://10.158.50.19:9443/`).
2. Ve al menú **Configuración**.
3. Escribe la URL completa de la API: `https://<tu-ip-fija>:9443/nprint` y guarda. **Ojo**: el puerto `:9443` es obligatorio, sin él no resuelve.
4. Ve a **Prints**: deberías ver listadas todas las impresoras compartidas.
5. Ve a **Templates** y prueba imprimir un test.

### 8. Vincular con POS Admin (paso del lado de la nube)

La URL del servidor local de impresión hay que registrarla en el panel del proveedor del servicio (POS Centro), para que las apps remotas (POS Admin, POS Cash, POS Comandero) sepan a dónde mandar las peticiones de impresión.

**Pasos en POS Centro (superadmin del proveedor):**

1. Sección **Restaurantes** → edita el restaurante del cliente.
2. En **Url base** coloca: `https://<tu-ip-fija>:9443` ← **incluye el `:9443`**, sin él los clientes no llegarán al servidor. Sin barra final ni `/nprint`.
   - Ejemplo: `https://10.158.50.19:9443`
3. Guarda.

> 📞 Si tú no eres el proveedor, **avísale al proveedor del servicio** la IP fija + puerto que generaste (`https://<tu-ip-fija>:9443`) para que él la registre en los datos del restaurante. Sin este paso, las demás apps no detectan la API local.

**Pasos en POS Admin (front del restaurante):**

1. Una vez guardada la URL en POS Centro, entra a **POS Admin** del restaurante.
2. Ve a **Configuración** → asegúrate de que esté en modo **"Impresión"**.
   - ⚠️ Si está en modo **"QR"**, los tickets se mostrarán en pantalla dentro de la web pero **NO se enviarán a las impresoras físicas**. Es un error muy común — confírmalo antes de reportar fallos.
3. Crea las cajas y áreas de impresión seleccionando las impresoras locales que ya están compartidas en la PC del servidor.

### 9. Refrescar sesiones en dispositivos cliente

Si POS Admin / POS Cash / POS Comandero ya estaban abiertos antes de configurar la URL en POS Centro, los dispositivos tienen en caché la configuración antigua del restaurante (sin la `Url base` o con una vieja). En ese caso:

- **Cerrar sesión en TODOS los dispositivos** (POS Admin, POS Cash en cada caja, POS Comandero en cada tablet).
- Volver a iniciar sesión.

Solo al re-loguearse las apps recargan los datos del restaurante desde POS Centro y recogen la nueva `Url base` con la IP local. Sin este paso seguirán intentando imprimir contra el endpoint anterior (o ninguno) y no funcionará.

---

### 10. Activar el registro de impresión de Windows (una sola vez)

El POS mide cada impresión: si llegó a esta PC, cuánto tardó y **si de verdad se imprimió**. Para lo último necesita el registro de impresión de Windows, que viene **apagado** de fábrica.

1. Clic derecho en `habilitar_registro_impresion.bat` → **Ejecutar como administrador**
2. Debe decir `enabled: true`

Se hace **solo en esta PC** (la del ID-Printer), no en las cajas ni tablets. Para verificarlo: `https://<IP>:9443/nprint/jobs/diagnostico` debe mostrar `"logImpresionActivo": true`.

Sin el registro todo sigue imprimiendo igual; solo que el POS marcará los tickets como **"sin confirmar"** en lugar de **"impreso"**.

> **Límite:** "impreso" significa que Windows lo terminó de mandar a la impresora. Con drivers que no reportan estado (por ejemplo **Generic / Text Only**), Windows puede darlo por impreso aunque la impresora no tenga papel. Con drivers del fabricante (Epson TM, Star…) sí se detecta.

## Actualizar a la última versión

Desde la carpeta del proyecto:

```bat
git pull
```

Esto trae cambios de código y de dependencias PHP (el `vendor/` está versionado a propósito, justamente para que el cliente no tenga que instalar Composer).

> ⚠️ El `.gitignore` ya protege `cert/`, `logs/` y `.env` — no se sobrescriben al hacer `git pull`.

---

## Estructura del proyecto

```
ID-PRINTER-LOCAL/
├── Id-server.exe              GUI de control (Play / Stop / abrir front)
├── iniciar_servidor.vbs       Lanza php.exe + caddy.exe en background
├── detener_servidor.vbs       Mata ambos procesos
├── caddy.exe                  Servidor HTTPS (puerto 9443)
├── Caddyfile                  Config de Caddy → reverse-proxy a :8080
├── php/                       Runtime PHP 8.1 portable + extensiones
│   ├── php.exe
│   ├── php.ini
│   └── cert/cacert.pem        CA bundle para OpenSSL/cURL
├── cert/                      ← Tus certificados (generados con mkcert, no versionados)
│   ├── certificado.pem
│   └── llave.pem
├── logs/                      Logs de Caddy (no versionados)
└── public/                    Document root
    ├── index.html             Frontend Vite (build)
    ├── assets/
    └── nprint/                Backend API (Slim + escpos-php)
        ├── index.php
        ├── controllers/
        ├── models/
        ├── vendor/            Dependencias PHP (versionadas)
        ├── composer.json
        ├── .env               ← Crear desde .env.example
        └── .env.example
```

---

## Solución de problemas

**El navegador dice "no seguro" pese al certificado**
Recorre esta lista en orden:
1. ¿Importaste el `.p12` (o el `.crt`) específicamente al almacén **"Entidades de certificación raíz de confianza"**? Si lo dejaste en *Personal* o *Automático*, Windows no lo trata como CA y todo navegador lo marcará como inválido.
2. **Reinicia la PC / tablet.** Sin reiniciar, el almacén de confianza no se refresca a nivel sistema.
3. Si después de reiniciar Chrome/Edge sigue diciendo "No seguro" pero Postman/cURL responden OK → es caché del navegador (HSTS o cert pinning). Abre DevTools (F12) → pestaña *Application* → *Storage* → **Clear site data**. O prueba en modo incógnito.
4. Si estás en un dispositivo **distinto** al servidor (POS Cash en otra PC, tablet del comandero, etc.) y no instalaste el cert ahí, repite la sección **5.1** del README.

**No aparecen impresoras en `/prints`**
- Confirma que la impresora esté marcada como *compartida* (paso 4).
- Confirma que en `/configuracion` la URL guardada coincida con la IP de la barra del navegador.

**Las impresoras no aparecen en POS Admin**
- ID Printer debe estar **prendido** en la PC del restaurante.
- La impresora debe estar **compartida** desde propiedades de impresora.

**Windows bloqueó los `.vbs` o `.exe`**
Clic derecho → Propiedades → marca *"Desbloquear"* → Aceptar. Ejecuta los scripts como administrador.

**`git pull` pisa cambios locales**
No debería: `cert/`, `logs/` y `.env` están en `.gitignore`. Si tocas otros archivos del repo manualmente, hazlo bajo tu propia responsabilidad.

---

## Licencias

- PHP: [PHP License v3.01](https://www.php.net/license/3_01.txt)
- Caddy: [Apache License 2.0](https://github.com/caddyserver/caddy/blob/master/LICENSE)
- Slim, escpos-php, endroid/qr-code: ver `public/nprint/vendor/`

Consulta `LICENSE` para más detalles.

---

Para soporte o licencias comerciales: **willyruiz95@gmail.com**
