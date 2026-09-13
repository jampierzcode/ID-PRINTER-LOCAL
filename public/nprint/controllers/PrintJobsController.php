<?php

namespace App\Controllers;

use App\Printing\PrintJobLog;
use App\Printing\WindowsPrintStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Estado de trabajos de impresión, para el rastreo que hace el POS.
 *
 *   GET /nprint/jobs?uids=uid1,uid2   estado de hasta 50 trabajos
 *   GET /nprint/jobs/diagnostico      ¿Windows? ¿log de impresión prendido?
 *
 * Las horas salen en ISO UTC pero son del reloj de ESTA PC. El POS no las
 * resta contra las suyas: usa las duraciones (render_ms, spool_ms, queue_ms).
 */
class PrintJobsController
{
    private static function iso(?int $ms): ?string
    {
        if (!$ms) return null;
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }

    private function json(Response $response, $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function status(Request $request, Response $response): Response
    {
        $raw = (string) ($request->getQueryParams()['uids'] ?? '');
        $uids = array_slice(array_values(array_unique(array_filter(
            array_map(fn ($u) => strtolower(trim($u)), explode(',', $raw)),
            fn ($u) => preg_match('/^[0-9a-f-]{36}$/', $u)
        ))), 0, 50);

        $jobs = WindowsPrintStatus::refrescar(PrintJobLog::obtener($uids));

        $out = [];
        foreach ($uids as $uid) {
            $j = $jobs[$uid] ?? null;
            if (!$j) {
                // No llegó a este servidor, o llegó sin pasar por el conector
                // (rechazado antes: sin impresora, datos inválidos).
                $out[] = ['jobUid' => $uid, 'status' => 'desconocido'];
                continue;
            }
            $spooled = $j['spooled_ms'] ? (int) $j['spooled_ms'] : null;
            $printed = $j['printed_ms'] ? (int) $j['printed_ms'] : null;
            $out[] = [
                'jobUid' => $uid,
                'status' => $j['status'],
                'message' => $j['message'],
                'printerName' => $j['printer_name'],
                'endpoint' => $j['endpoint'],
                'receivedAt' => self::iso($j['received_ms'] ? (int) $j['received_ms'] : null),
                'spooledAt' => self::iso($spooled),
                'printedAt' => self::iso($printed),
                'renderMs' => $j['render_ms'] !== null ? (int) $j['render_ms'] : null,
                'spoolMs' => $j['spool_ms'] !== null ? (int) $j['spool_ms'] : null,
                'queueMs' => ($spooled && $printed) ? max(0, $printed - $spooled) : null,
                'windowsStatus' => $j['windows_status'],
                'windowsJobId' => $j['windows_job_id'] !== null ? (int) $j['windows_job_id'] : null,
                'documento' => $j['documento'],
                'bytes' => $j['bytes'] !== null ? (int) $j['bytes'] : null,
            ];
        }
        return $this->json($response, $out);
    }

    public function diagnostico(Request $request, Response $response): Response
    {
        return $this->json($response, WindowsPrintStatus::diagnostico() + ['rastreo' => 1]);
    }
}
