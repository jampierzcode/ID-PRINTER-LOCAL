<?php

namespace App\Printing;

use PDO;
use Throwable;

/**
 * Bitácora local de trabajos de impresión (SQLite, en esta misma PC).
 *
 * Existe para contestar "¿se trabó?" con datos: cuándo llegó cada petición,
 * cuánto tardó en armarse el ticket, cuándo Windows lo aceptó en la cola y
 * cuándo lo terminó de mandar a la impresora. El POS consulta este registro
 * por GET /nprint/jobs y sube el resultado al backend.
 *
 * Vive en su propio archivo (`data/print_jobs.sqlite`) y no en la base de
 * plantillas: se escribe en cada impresión y no debe bloquear la otra.
 *
 * TODO lo de aquí es best-effort. Si SQLite falla, la impresión sigue: medir
 * nunca debe impedir que salga el ticket.
 *
 * Todas las marcas de tiempo son de ESTA PC (microtime), en milisegundos.
 */
class PrintJobLog
{
    /** Momento en que Slim recibió la petición de impresión en curso. */
    public static ?float $recibidoEn = null;
    /** Ruta de la petición en curso: /printers/print-comanda, etc. */
    public static ?string $endpoint = null;

    private static ?PDO $pdo = null;

    public static function iniciarPeticion(string $endpoint): void
    {
        self::$recibidoEn = microtime(true);
        self::$endpoint = $endpoint;
    }

    private static function db(): ?PDO
    {
        if (self::$pdo) return self::$pdo;
        try {
            $dir = __DIR__ . '/../../data';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $pdo = new PDO('sqlite:' . $dir . '/print_jobs.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Varias cajas imprimiendo a la vez: que esperen el candado un rato
            // en vez de fallar al instante.
            $pdo->exec('PRAGMA busy_timeout = 3000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS print_jobs (
                job_uid TEXT PRIMARY KEY,
                endpoint TEXT,
                printer_name TEXT,
                status TEXT NOT NULL,
                message TEXT,
                received_ms INTEGER,
                created_ms INTEGER,
                spooled_ms INTEGER,
                printed_ms INTEGER,
                render_ms INTEGER,
                spool_ms INTEGER,
                documento TEXT,
                bytes INTEGER,
                windows_job_id INTEGER,
                windows_status TEXT,
                checked_ms INTEGER
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS print_jobs_created ON print_jobs(created_ms)');
            self::$pdo = $pdo;
            // Limpieza ocasional: una semana alcanza para diagnosticar.
            if (mt_rand(1, 200) === 1) {
                $limite = (int) round((microtime(true) - 7 * 86400) * 1000);
                $pdo->prepare('DELETE FROM print_jobs WHERE created_ms < ?')->execute([$limite]);
            }
            return $pdo;
        } catch (Throwable $e) {
            error_log('[PrintJobLog] sin base local: ' . $e->getMessage());
            return null;
        }
    }

    public static function ms(float $t): int
    {
        return (int) round($t * 1000);
    }

    /* ── Reenvíos de la MISMA petición ──────────────────────────────────────
     *
     * Con la red del restaurante intermitente, la tablet manda la comanda, el
     * ID-Printer la imprime, y la respuesta no alcanza a volver porque el WiFi
     * se cayó en ese instante. El navegador entonces REENVÍA el mismo POST por
     * una conexión nueva (Chrome lo hace cuando la conexión muere antes del
     * primer byte de respuesta) y salen dos papeles idénticos.
     *
     * Ni el POS ni esta bitácora lo veían: el cuerpo reenviado trae el MISMO
     * `jobUid`, y `job_uid` es la llave primaria, así que el segundo registro
     * pisaba al primero y quedaba una sola fila.
     *
     * La regla: un `jobUid` = un ticket. Si ese uid ya se le entregó a Windows,
     * el reenvío se ignora. Si el intento anterior nunca llegó a entregarse y
     * ya pasó rato (el PHP se murió a medias), se deja imprimir: es peor que
     * cocina no reciba nada a que reciba dos papeles.
     */

    /** Estados en los que Windows YA tiene el ticket: reimprimir sería doble. */
    private const ENTREGADOS = ['enviado', 'en_cola', 'impreso', 'sin_confirmar'];

    /** Cuánto se espera a un intento que quedó a medias antes de reimprimirlo. */
    private const MS_INTENTO_MUERTO = 120000;

    /** Los `jobUid` de un cuerpo de impresión (lista de trabajos o uno suelto). */
    public static function uidsDelCuerpo(string $cuerpo): array
    {
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) return [];
        $trabajos = isset($datos['jobUid']) ? [$datos] : $datos;
        $uids = [];
        foreach ($trabajos as $t) {
            if (!is_array($t)) continue;
            $uid = is_string($t['jobUid'] ?? null) ? strtolower(trim($t['jobUid'])) : '';
            if (preg_match('/^[0-9a-f-]{36}$/', $uid)) $uids[] = $uid;
        }
        return array_values(array_unique($uids));
    }

    /**
     * Aparta estos uid para esta petición y devuelve los que son un reenvío de
     * algo ya impreso. Si devuelve tantos como se le pasaron, la petición
     * entera es un duplicado y no hay que imprimir nada.
     */
    public static function reenviados(array $uids, ?string $endpoint = null): array
    {
        $db = self::db();
        if (!$db || !$uids) return [];
        $ahora = self::ms(microtime(true));
        $repetidos = [];
        foreach ($uids as $uid) {
            try {
                /* Atómico a propósito: si dos copias de la misma petición entran
                 * a la vez, solo una logra insertar y la otra ve la fila. */
                $stmt = $db->prepare('INSERT OR IGNORE INTO print_jobs
                    (job_uid, endpoint, status, received_ms, created_ms)
                    VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$uid, $endpoint ?? self::$endpoint, 'recibido', $ahora, $ahora]);
                if ($stmt->rowCount() > 0) continue; // nunca se había visto

                $fila = $db->prepare('SELECT status, spooled_ms, received_ms FROM print_jobs WHERE job_uid = ?');
                $fila->execute([$uid]);
                $row = $fila->fetch(PDO::FETCH_ASSOC) ?: [];
                $estado = (string) ($row['status'] ?? '');
                $entregado = !empty($row['spooled_ms']) || in_array($estado, self::ENTREGADOS, true);
                $reciente = $ahora - (int) ($row['received_ms'] ?? 0) < self::MS_INTENTO_MUERTO;

                /* El intento anterior FALLO: Windows no lo quiso, la impresora
                 * no existe, el papel nunca salio. Aqui repetir no es duplicar,
                 * es la segunda oportunidad — se deja pasar. */
                if ($estado === 'rechazado') continue;

                /* Nunca se entrego y ya paso rato (el PHP se murio a medias):
                 * tambien se imprime. Es peor que cocina no reciba nada. */
                if (!$entregado && !$reciente) continue;
                $repetidos[] = $uid;
                /* Que quede por escrito en la bitácora del POS: así se ve
                 * cuántas veces la red repitió una comanda, sin pisar un
                 * mensaje de error anterior, que importa más. */
                $db->prepare("UPDATE print_jobs
                    SET message = COALESCE(NULLIF(message, ''), ?)
                    WHERE job_uid = ?")
                    ->execute(['Se ignoro un reenvio de esta misma peticion: el ticket ya habia salido.', $uid]);
            } catch (Throwable $e) {
                error_log('[PrintJobLog] reenviados: ' . $e->getMessage());
                // Sin bitácora no se puede saber: se imprime, como siempre.
            }
        }
        return $repetidos;
    }

    /** El trabajo entró al conector: ya se sabe a qué impresora va. */
    public static function recibido(string $uid, string $printerName, float $creadoEn): void
    {
        $db = self::db();
        if (!$db) return;
        try {
            $db->prepare('INSERT OR REPLACE INTO print_jobs
                (job_uid, endpoint, printer_name, status, received_ms, created_ms)
                VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $uid,
                    self::$endpoint,
                    $printerName,
                    'recibido',
                    self::ms(self::$recibidoEn ?? $creadoEn),
                    self::ms($creadoEn),
                ]);
        } catch (Throwable $e) {
            error_log('[PrintJobLog] recibido: ' . $e->getMessage());
        }
    }

    /** Actualiza columnas sueltas de un trabajo. */
    public static function actualizar(string $uid, array $campos): void
    {
        $db = self::db();
        if (!$db || !$campos) return;
        try {
            $sets = [];
            $vals = [];
            foreach ($campos as $col => $val) {
                $sets[] = $col . ' = ?';
                $vals[] = $val;
            }
            $vals[] = $uid;
            $db->prepare('UPDATE print_jobs SET ' . implode(', ', $sets) . ' WHERE job_uid = ?')
                ->execute($vals);
        } catch (Throwable $e) {
            error_log('[PrintJobLog] actualizar: ' . $e->getMessage());
        }
    }

    /** @return array<string, array> indexado por job_uid */
    public static function obtener(array $uids): array
    {
        $db = self::db();
        if (!$db || !$uids) return [];
        try {
            $marcas = implode(',', array_fill(0, count($uids), '?'));
            $stmt = $db->prepare("SELECT * FROM print_jobs WHERE job_uid IN ($marcas)");
            $stmt->execute(array_values($uids));
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$row['job_uid']] = $row;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[PrintJobLog] obtener: ' . $e->getMessage());
            return [];
        }
    }
}
