<?php

namespace App\Printing;

use Exception;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

/**
 * El mismo conector de Windows de siempre, pero midiendo.
 *
 * `WindowsPrintConnector` junta todos los bytes del ticket en memoria y los
 * manda a Windows de un golpe al cerrar la impresora (`finalize`): copia un
 * archivo temporal a \\PC\Impresora. Así que:
 *
 *   creación → finalize   = armar el ticket           (render_ms)
 *   duración de finalize  = entregarlo a Windows      (spool_ms)
 *   fin de finalize       = Windows lo aceptó en cola (spooled)
 *
 * Ojo: que Windows lo acepte NO es que haya salido el papel. Eso se averigua
 * después, en `WindowsPrintStatus`, buscando el trabajo en la cola y en el log
 * de impresión de Windows. Para poder encontrarlo ahí se guarda el nombre del
 * archivo temporal (así lo ve la cola como "documento") y su tamaño.
 *
 * Sin `jobUid` se comporta exactamente como el conector original: los POS
 * viejos, que no mandan el identificador, siguen imprimiendo igual.
 */
class TrackedWindowsPrintConnector extends WindowsPrintConnector
{
    private ?string $jobUid;
    private float $creadoEn;
    private ?string $documento = null;
    private ?int $bytes = null;

    public function __construct($dest, $jobUid = null)
    {
        $this->creadoEn = microtime(true);
        $uid = is_string($jobUid) ? trim($jobUid) : '';
        $this->jobUid = preg_match('/^[0-9a-f-]{36}$/i', $uid) ? strtolower($uid) : null;
        if ($this->jobUid) {
            PrintJobLog::recibido($this->jobUid, (string) $dest, $this->creadoEn);
        }
        try {
            parent::__construct($dest);
        } catch (Exception $e) {
            $this->fallo('Nombre de impresora no válido: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function runCopy($from, $to)
    {
        $this->documento = basename((string) $from);
        $tam = @filesize($from);
        $this->bytes = $tam === false ? null : (int) $tam;
        return parent::runCopy($from, $to);
    }

    protected function runWrite($data, $filename)
    {
        $this->bytes = strlen((string) $data);
        return parent::runWrite($data, $filename);
    }

    public function finalize()
    {
        $inicio = microtime(true);
        try {
            parent::finalize();
        } catch (Exception $e) {
            $this->fallo($e->getMessage(), $inicio);
            throw $e;
        }
        $fin = microtime(true);
        if ($this->jobUid) {
            PrintJobLog::actualizar($this->jobUid, [
                'status' => 'enviado',
                'render_ms' => PrintJobLog::ms($inicio - $this->creadoEn),
                'spool_ms' => PrintJobLog::ms($fin - $inicio),
                'spooled_ms' => PrintJobLog::ms($fin),
                'documento' => $this->documento,
                'bytes' => $this->bytes,
            ]);
        }
    }

    private function fallo(string $mensaje, ?float $inicio = null): void
    {
        if (!$this->jobUid) return;
        $campos = ['status' => 'rechazado', 'message' => WindowsPrintStatus::recortar($mensaje, 500)];
        if ($inicio !== null) {
            $campos['render_ms'] = PrintJobLog::ms($inicio - $this->creadoEn);
            $campos['spool_ms'] = PrintJobLog::ms(microtime(true) - $inicio);
        }
        PrintJobLog::actualizar($this->jobUid, $campos);
    }
}
