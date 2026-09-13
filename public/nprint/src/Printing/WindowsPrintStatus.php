<?php

namespace App\Printing;

use Throwable;

/**
 * ¿Qué pasó con un trabajo después de que Windows lo aceptó?
 *
 * Imprimir con WindowsPrintConnector es copiar un archivo a la impresora
 * compartida: Windows contesta en cuanto lo acepta en la cola, no cuando sale
 * el papel. Para saber lo que pasa después se le pregunta a Windows dos cosas:
 *
 *  1. La COLA de la impresora (Get-PrintJob). Si el trabajo sigue ahí, está
 *     atorado — apagada, sin papel, error — y JobStatus dice qué ve Windows.
 *  2. El LOG de impresión (Microsoft-Windows-PrintService/Operational, evento
 *     307 "documento impreso"). Trae la hora exacta en que Windows terminó de
 *     mandarlo a la impresora. Este log viene APAGADO de fábrica en Windows:
 *     se prende una vez con `habilitar_registro_impresion.bat` (como admin).
 *
 * El trabajo se reconoce por impresora + nombre de documento (el archivo
 * temporal que copió el conector) o, si Windows no lo reporta igual, por el
 * tamaño en bytes, siempre posterior a la hora en que se entregó.
 *
 * Límite honesto: "impreso" es que Windows lo terminó de mandar. Con drivers
 * que no reportan estado (ej. "Generic / Text Only") Windows puede darlo por
 * impreso aunque la impresora no tenga papel.
 */
class WindowsPrintStatus
{
    private const LOG = 'Microsoft-Windows-PrintService/Operational';

    /** Estados que ya no cambian: no se vuelve a preguntar a Windows. */
    public const FINALES = ['impreso', 'rechazado', 'sin_confirmar'];

    public static function esWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Ejecuta un script de PowerShell y devuelve su salida JSON decodificada.
     * Va con -EncodedCommand para no pelear con comillas ni con nombres de
     * impresora con espacios.
     */
    private static function powershell(string $script): ?array
    {
        if (!self::esWindows()) return null;
        try {
            $utf16 = self::utf16le($script);
            $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '
                . base64_encode($utf16);
            $salida = shell_exec($cmd);
            if (!is_string($salida) || trim($salida) === '') return null;
            $json = json_decode(trim($salida), true);
            return is_array($json) ? $json : null;
        } catch (Throwable $e) {
            error_log('[WindowsPrintStatus] powershell: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * PowerShell -EncodedCommand espera UTF-16LE. `mbstring` NO está activado en
     * el PHP portátil del ID-Printer (ver php.ini), así que no se puede contar
     * con mb_convert_encoding: se usa iconv, que viene integrado, y como último
     * recurso la conversión a mano (vale para ASCII).
     */
    private static function utf16le(string $s): string
    {
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        if (function_exists('iconv')) {
            $r = @iconv('UTF-8', 'UTF-16LE', $s);
            if ($r !== false) return $r;
        }
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $out .= $s[$i] . "\0";
        return $out;
    }

    /** Recorta sin depender de mbstring (ver utf16le). */
    public static function recortar(string $s, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /** ConvertTo-Json devuelve un objeto suelto cuando la lista trae uno solo. */
    private static function lista($v): array
    {
        if (!is_array($v)) return [];
        return array_keys($v) === range(0, count($v) - 1) ? $v : [$v];
    }

    private static function comillas(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /** "2026-09-13T05:00:00.1234567Z" → ms epoch. PHP no acepta 7 decimales. */
    private static function isoAMs(?string $iso): ?int
    {
        if (!$iso) return null;
        $limpio = preg_replace('/(\.\d{6})\d+/', '$1', $iso);
        $t = strtotime((string) $limpio);
        if ($t === false) return null;
        $ms = 0;
        if (preg_match('/\.(\d{1,6})/', (string) $limpio, $m)) {
            $ms = (int) substr(str_pad($m[1], 3, '0'), 0, 3);
        }
        return $t * 1000 + $ms;
    }

    /** Diagnóstico para la pantalla de configuración / soporte. */
    public static function diagnostico(): array
    {
        if (!self::esWindows()) {
            return ['windows' => false, 'logImpresionActivo' => null];
        }
        $r = self::powershell(
            "\$ErrorActionPreference='SilentlyContinue'\n"
            . "\$l = Get-WinEvent -ListLog " . self::comillas(self::LOG) . "\n"
            . "@{ activo = [bool]\$l.IsEnabled; cmdletCola = [bool](Get-Command Get-PrintJob -ErrorAction SilentlyContinue) } | ConvertTo-Json -Compress"
        );
        return [
            'windows' => true,
            'logImpresionActivo' => isset($r['activo']) ? (bool) $r['activo'] : null,
            'puedeLeerCola' => isset($r['cmdletCola']) ? (bool) $r['cmdletCola'] : null,
        ];
    }

    /**
     * Revisa en Windows los trabajos que siguen sin resultado final y los
     * actualiza en PrintJobLog. Recibe los renglones de PrintJobLog::obtener.
     *
     * @param array<string, array> $jobs
     * @return array<string, array> los mismos renglones, ya actualizados
     */
    public static function refrescar(array $jobs): array
    {
        $ahora = PrintJobLog::ms(microtime(true));

        $pendientes = [];
        foreach ($jobs as $uid => $j) {
            if (in_array($j['status'], self::FINALES, true)) continue;

            // Llegó al conector pero nunca se entregó a Windows: algo tronó
            // armando el ticket (el POS ya recibió el error en la respuesta).
            if ($j['status'] === 'recibido') {
                if ($ahora - (int) $j['created_ms'] > 60000) {
                    $jobs[$uid] = self::guardar($j, [
                        'status' => 'rechazado',
                        'message' => $j['message'] ?: 'Falló antes de entregarse a Windows.',
                    ]);
                }
                continue;
            }
            // No preguntar dos veces en el mismo segundo y medio.
            if ($ahora - (int) ($j['checked_ms'] ?? 0) < 1500) continue;
            $pendientes[$uid] = $j;
        }
        if (!$pendientes) return $jobs;

        $masViejo = min(array_map(fn ($j) => (int) $j['spooled_ms'], $pendientes));
        $segundos = max(30, (int) ceil(($ahora - $masViejo) / 1000) + 30);
        $impresoras = array_values(array_unique(array_map(fn ($j) => (string) $j['printer_name'], $pendientes)));

        $windows = self::powershell(
            "\$ErrorActionPreference='SilentlyContinue'\n"
            . "\$out = @{ logActivo = \$null; eventos = @(); cola = @(); nombres = @{} }\n"
            . "try { \$out.logActivo = [bool](Get-WinEvent -ListLog " . self::comillas(self::LOG) . ").IsEnabled } catch {}\n"
            . "try {\n"
            . "  \$out.eventos = @(Get-WinEvent -FilterHashtable @{ LogName=" . self::comillas(self::LOG) . "; Id=307; StartTime=(Get-Date).AddSeconds(-$segundos) } -ErrorAction Stop | ForEach-Object {\n"
            . "    \$d = ([xml]\$_.ToXml()).Event.UserData.DocumentPrinted\n"
            . "    [pscustomobject]@{ t = \$_.TimeCreated.ToUniversalTime().ToString('o'); jobId = [string]\$d.Param1; doc = [string]\$d.Param2; printer = [string]\$d.Param5; size = [string]\$d.Param7 }\n"
            . "  })\n"
            . "} catch {}\n"
            . "foreach (\$p in @(" . implode(',', array_map([self::class, 'comillas'], $impresoras)) . ")) {\n"
            . "  \$real = Get-Printer | Where-Object { \$_.Name -eq \$p -or \$_.ShareName -eq \$p } | Select-Object -First 1\n"
            . "  \$nombre = if (\$real) { \$real.Name } else { \$p }\n"
            . "  \$out.nombres[\$p] = @{ nombre = \$nombre; estado = if (\$real) { [string]\$real.PrinterStatus } else { \$null } }\n"
            . "  try {\n"
            . "    \$out.cola += @(Get-PrintJob -PrinterName \$nombre -ErrorAction Stop | ForEach-Object {\n"
            . "      [pscustomobject]@{ printer = \$p; id = \$_.Id; doc = [string]\$_.DocumentName; status = [string]\$_.JobStatus; size = [string]\$_.Size }\n"
            . "    })\n"
            . "  } catch {}\n"
            . "}\n"
            . "\$out | ConvertTo-Json -Depth 5 -Compress"
        );

        $logActivo = $windows['logActivo'] ?? null;
        $eventos = self::lista($windows['eventos'] ?? []);
        $cola = self::lista($windows['cola'] ?? []);
        $nombres = is_array($windows['nombres'] ?? null) ? $windows['nombres'] : [];
        $usados = [];

        // Del más viejo al más nuevo: si dos comandas iguales van a la misma
        // impresora, el primer evento le toca al primer trabajo.
        uasort($pendientes, fn ($a, $b) => (int) $a['spooled_ms'] <=> (int) $b['spooled_ms']);

        foreach ($pendientes as $uid => $j) {
            $impresora = (string) $j['printer_name'];
            $nombreReal = strtolower((string) ($nombres[$impresora]['nombre'] ?? $impresora));
            $doc = strtolower((string) ($j['documento'] ?? ''));
            $bytes = (int) ($j['bytes'] ?? 0);
            $esSuyo = function (array $e, string $campoImpresora) use ($nombreReal, $impresora, $doc, $bytes) {
                $imp = strtolower((string) ($e[$campoImpresora] ?? ''));
                if ($imp !== $nombreReal && $imp !== strtolower($impresora)) return false;
                $mismoDoc = $doc !== '' && strpos(strtolower((string) ($e['doc'] ?? '')), $doc) !== false;
                $mismoTam = $bytes > 0 && (int) ($e['size'] ?? 0) === $bytes;
                return $mismoDoc || $mismoTam;
            };

            // 1) ¿Windows dejó constancia de que se imprimió?
            $impreso = null;
            foreach ($eventos as $i => $e) {
                if (isset($usados[$i]) || !$esSuyo($e, 'printer')) continue;
                $t = self::isoAMs($e['t'] ?? null);
                if ($t === null || $t < (int) $j['spooled_ms'] - 5000) continue;
                $impreso = ['i' => $i, 't' => $t, 'jobId' => (int) ($e['jobId'] ?? 0)];
                break;
            }
            if ($impreso) {
                $usados[$impreso['i']] = true;
                $jobs[$uid] = self::guardar($j, [
                    'status' => 'impreso',
                    'printed_ms' => $impreso['t'],
                    'windows_job_id' => $impreso['jobId'] ?: null,
                    'windows_status' => null,
                    'checked_ms' => $ahora,
                ]);
                continue;
            }

            // 2) ¿Sigue en la cola? Entonces está atorado.
            $enCola = null;
            foreach ($cola as $c) {
                if ($esSuyo($c, 'printer')) { $enCola = $c; break; }
            }
            if ($enCola) {
                $estadoImpresora = $nombres[$impresora]['estado'] ?? null;
                $detalle = trim((string) ($enCola['status'] ?? '')) ?: 'En cola';
                if ($estadoImpresora && stripos($detalle, (string) $estadoImpresora) === false) {
                    $detalle .= ' · impresora: ' . $estadoImpresora;
                }
                $jobs[$uid] = self::guardar($j, [
                    'status' => 'en_cola',
                    'windows_job_id' => (int) ($enCola['id'] ?? 0) ?: null,
                    'windows_status' => self::recortar($detalle, 120),
                    'checked_ms' => $ahora,
                ]);
                continue;
            }

            // 3) Ni impreso ni en cola.
            $edad = $ahora - (int) $j['spooled_ms'];
            if ($logActivo === true && $edad < 30000) {
                // Windows todavía no escribe el evento: se vuelve a preguntar.
                $jobs[$uid] = self::guardar($j, ['checked_ms' => $ahora]);
                continue;
            }
            $motivo = $logActivo === false
                ? 'Salió de la cola, pero el registro de impresión de Windows está apagado: no hay constancia de que se imprimió.'
                : ($logActivo === null
                    ? 'No se pudo consultar a Windows el estado del trabajo.'
                    : 'Salió de la cola sin que Windows lo registrara como impreso (el driver puede no reportarlo).');
            // Sin log no hay nada más que esperar; con log ya pasaron los 30 s de gracia.
            $jobs[$uid] = self::guardar($j, [
                'status' => 'sin_confirmar',
                'message' => $motivo,
                'checked_ms' => $ahora,
            ]);
        }

        return $jobs;
    }

    private static function guardar(array $j, array $campos): array
    {
        PrintJobLog::actualizar((string) $j['job_uid'], $campos);
        return array_merge($j, $campos);
    }
}
