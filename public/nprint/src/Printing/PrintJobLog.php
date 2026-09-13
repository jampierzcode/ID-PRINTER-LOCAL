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
