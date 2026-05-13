<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class FingerprintController
{
    // Ajusta estas rutas a tu instalación
    private string $exePath = __DIR__ . '../../bin/finger/FingerPrint.exe';

    // ✅ Cloud API (Adonis)
    private string $cloudBaseUrl;
    private int $cloudTimeoutSec = 15;

    public function __construct()
    {
        $this->cloudBaseUrl = $this->resolveCloudBaseUrl();
    }

    private function resolveCloudBaseUrl(): string
    {
        $value = $this->loadEnvValue('CLOUD_BASE_URL');
        if ($value !== null && $value !== '') {
            return rtrim($value, '/');
        }

        return 'http://localhost:3333/api';
    }

    private function loadEnvValue(string $key): ?string
    {
        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        $envPath = __DIR__ . '/../.env';
        if (!is_file($envPath) || !is_readable($envPath)) {
            return null;
        }

        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }

            [$name, $raw] = explode('=', $line, 2);
            if (trim($name) !== $key) {
                continue;
            }

            $value = trim($raw);
            if ($value === '') {
                return '';
            }

            $first = $value[0];
            if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                $value = substr($value, 1, -1);
            }

            return str_replace('\n', "\n", $value);
        }

        return null;
    }

    private function httpGetJson(string $url, ?string $authHeader = null): array
    {
        $headers = "Accept: application/json\r\n";
        if ($authHeader) $headers .= "Authorization: " . $authHeader . "\r\n";

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $headers,
                'timeout' => $this->cloudTimeoutSec,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            // 👇 log extra: headers HTTP si existen
            $hdrs = isset($http_response_header) ? $http_response_header : [];

            return [
                'ok' => false,
                'error' => 'cloud_request_failed',
                'detail' => $err['message'] ?? 'unknown',
                'http_response_header' => $hdrs,
            ];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'cloud_invalid_json', 'raw' => $raw];
        }

        return $json;
    }





    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function runExe(array $args, int $timeoutSec = 120): array
    {
        if (!file_exists($this->exePath)) {
            return ['ok' => false, 'error' => 'finger_exe_not_found', 'exePath' => $this->exePath];
        }

        $cmd = '"' . $this->exePath . '" ' . implode(' ', array_map('escapeshellarg', $args));
        file_put_contents('C:\temp\nprint_cmd.log', $cmd . PHP_EOL, FILE_APPEND);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'proc_open_failed'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = time();
        $out = '';
        $err = '';

        while (true) {
            $status = proc_get_status($proc);
            $out .= stream_get_contents($pipes[1]) ?: '';
            $err .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) break;

            if ((time() - $start) > $timeoutSec) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['ok' => false, 'error' => 'exe_timeout', 'timeoutSec' => $timeoutSec, 'stderr' => trim($err)];
            }

            usleep(200000); // 200ms
        }

        $exitCode = $status['exitcode'];
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $lines = preg_split("/\r\n|\n|\r/", trim($out));
        $lastJson = null;

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            $try = json_decode($line, true);
            if (is_array($try)) {
                $lastJson = $try;
                break;
            }
        }

        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => 'finger_exe_failed', 'exitCode' => $exitCode, 'stderr' => trim($err), 'raw' => trim($out)];
        }

        if (!$lastJson) {
            return ['ok' => false, 'error' => 'finger_exe_invalid_json', 'stderr' => trim($err), 'raw' => trim($out)];
        }

        return $lastJson;
    }


    private function runExeLines(array $args): array
    {
        if (!file_exists($this->exePath)) {
            return ['ok' => false, 'error' => 'finger_exe_not_found', 'exePath' => $this->exePath, 'lines' => []];
        }

        $cmd = '"' . $this->exePath . '" ' . implode(' ', array_map('escapeshellarg', $args));
        $output = [];
        $exitCode = 0;

        exec($cmd, $output, $exitCode);
        $raw = trim(implode("\n", $output));

        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => 'finger_exe_failed', 'exitCode' => $exitCode, 'raw' => $raw, 'lines' => $output];
        }

        return ['ok' => true, 'lines' => $output, 'raw' => $raw];
    }

    public function enrollDebug(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $reader = (string)($q['readerSerial'] ?? '');
        $fingerIndex = (int)($q['fingerIndex'] ?? 0);

        if (!$reader || $fingerIndex < 1 || $fingerIndex > 4) {
            $response->getBody()->write("missing readerSerial or fingerIndex");
            return $response->withStatus(400);
        }

        // Streaming plain text (no JSON)
        $res = $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache');

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        $cmd = '"' . $this->exePath . '" ' . implode(' ', array_map('escapeshellarg', [
            'enroll',
            '--reader',
            $reader,
            '--fingerIndex',
            (string)$fingerIndex,
            '--samples',
            '4',
            '--timeoutMs',
            '90000'
        ]));

        // Esto manda stdout del exe directo al HTTP response (debug)
        passthru($cmd);

        return $res;
    }



    // POST /fingerprint/enroll  body: { "userId": 123, "fingerIndex": 1 }
    public function enroll(Request $request, Response $response): Response
    {
        file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] enroll start\n", FILE_APPEND);

        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) $body = [];

            // Fallback robusto: leer body crudo correctamente (PSR-7 stream)
            if (empty($body)) {
                $stream = $request->getBody();
                if ($stream->isSeekable()) $stream->rewind();
                $raw = $stream->getContents();

                // Log útil para ver qué llegó
                file_put_contents(
                    'C:\temp\nprint_http.log',
                    "[" . date('c') . "] content-type=" . $request->getHeaderLine('Content-Type') .
                        " rawLen=" . strlen($raw) .
                        " raw=" . $raw . "\n",
                    FILE_APPEND
                );

                $try = json_decode($raw, true);
                if (is_array($try)) $body = $try;
            }


            $fingerIndex = (int)($body['fingerIndex'] ?? 0);
            if ($fingerIndex < 1 || $fingerIndex > 4) {
                return $this->json($response, ['ok' => false, 'error' => 'finger_index_invalid', 'hint' => 'use 1..4'], 400);
            }
            $reader = (string)($body['readerSerial'] ?? '');

            if (!$reader) return $this->json($response, ['ok' => false, 'error' => 'readerSerial_required'], 400);
            file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] running exe\n", FILE_APPEND);

            $result = $this->runExe([
                'enroll',
                '--reader',
                $reader,
                '--fingerIndex',
                (string)$fingerIndex,
                '--samples',
                '4',
                '--timeoutMs',
                '90000'
            ], 180);
            file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] exe done\n", FILE_APPEND);



            return $this->json($response, $result, $result['ok'] ? 200 : 500);
        } catch (\Exception $e) {
            $code = 500;

            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $code);
        }
    }

    // POST /fingerprint/verify body: { "templateBase64": "...", "userId": 123 }
    public function verify(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody() ?? [];
            $tpl = (string)($body['templateBase64'] ?? '');
            if (!$tpl) return $this->json($response, ['ok' => false, 'error' => 'template_required'], 400);

            $reader = (string)($body['readerSerial'] ?? '');
            if (!$reader) return $this->json($response, ['ok' => false, 'error' => 'readerSerial_required'], 400);

            $result = $this->runExe([
                'verify',
                '--reader',
                $reader,
                '--templateBase64',
                $tpl,
                '--timeoutMs',
                '60000'
            ]);


            return $this->json($response, $result, $result['ok'] ? 200 : 500);
        } catch (\Exception $e) {
            $code = 500;

            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $code);
        }
    }

    // POST /fingerprint/identify body:
    // { "restaurantId": 123, "readerSerial": "SERIAL" }
    // El local server trae candidates desde la nube y ejecuta Identify 1:N con el EXE.
    public function identify(Request $request, Response $response): Response
    {
        try {
            $stream = $request->getBody();
            if ($stream->isSeekable()) $stream->rewind();
            $raw = $stream->getContents();

            file_put_contents(
                'C:\temp\nprint_http.log',
                "[" . date('c') . "] identify DEBUG ct=" . $request->getHeaderLine('Content-Type') .
                    " len=" . strlen($raw) .
                    " raw=" . $raw . "\n",
                FILE_APPEND
            );

            $body = $request->getParsedBody();
            if (!is_array($body)) $body = [];

            // Fallback robusto: leer body crudo (igual que enroll)
            if (empty($body)) {
                $stream = $request->getBody();
                if ($stream->isSeekable()) $stream->rewind();
                $raw = $stream->getContents();

                file_put_contents(
                    'C:\temp\nprint_http.log',
                    "[" . date('c') . "] identify content-type=" . $request->getHeaderLine('Content-Type') .
                        " rawLen=" . strlen($raw) .
                        " raw=" . $raw . "\n",
                    FILE_APPEND
                );

                $try = json_decode($raw, true);
                if (is_array($try)) $body = $try;
            }

            $restaurantId = (int)($body['restaurantId'] ?? 0);
            $reader = (string)($body['readerSerial'] ?? '');

            if ($restaurantId <= 0) {
                return $this->json($response, ['ok' => false, 'error' => 'restaurantId_required'], 400);
            }
            if (!$reader) {
                return $this->json($response, ['ok' => false, 'error' => 'readerSerial_required'], 400);
            }

            // ✅ Passthrough Authorization del front hacia la nube (si aplica)
            $authHeader = $request->getHeaderLine('Authorization');
            $cloudUrl = rtrim($this->cloudBaseUrl, '/') . "/restaurants/{$restaurantId}/fingerprint-candidates";

            // (Opcional) log para debug
            file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] identify fetch cloud: {$cloudUrl}\n", FILE_APPEND);

            $cloud = $this->httpGetJson($cloudUrl, $authHeader ?: null);

            if (!isset($cloud['ok']) || $cloud['ok'] !== true) {
                return $this->json($response, [
                    'ok' => false,
                    'error' => 'cloud_candidates_failed',
                    'cloud' => $cloud
                ], 502);
            }

            $candidates = $cloud['candidates'] ?? [];
            if (!is_array($candidates) || count($candidates) === 0) {
                return $this->json($response, [
                    'ok' => false,
                    'error' => 'no_fingerprints_for_restaurant',
                    'restaurantId' => $restaurantId
                ], 404);
            }

            // ✅ Guardamos candidates en archivo temporal para el EXE
            $tmp = tempnam(sys_get_temp_dir(), 'fp_');
            file_put_contents($tmp, json_encode(['candidates' => $candidates], JSON_UNESCAPED_UNICODE));

            // ✅ Ejecutar EXE Identify
            $result = $this->runExe([
                'identify',
                '--reader',
                $reader,
                '--candidatesFile',
                $tmp,
                '--timeoutMs',
                '60000'
            ]);

            @unlink($tmp);

            // ✅ Respuesta enriquecida para el front
            // El EXE ya retorna: { ok:true, mode:'identify', matched:true/false, userId? }
            $result['restaurantId'] = $restaurantId;
            $result['candidatesUsers'] = count($candidates);

            return $this->json($response, $result, $result['ok'] ? 200 : 500);
        } catch (\Exception $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }


    // GET /fingerprint/list-readers
    // GET /fingerprint/list-readers
    public function listReaders(Request $request, Response $response): Response
    {
        try {

            $r = $this->runExeLines(['list-readers']);
            if (!$r['ok']) return $this->json($response, $r, 500);

            $readers = [];
            $count = 0;

            foreach ($r['lines'] as $line) {
                $line = trim($line);
                if ($line === '') continue;

                $j = json_decode($line, true);
                if (!is_array($j)) continue;

                if (isset($j['count'])) $count = (int)$j['count'];

                // cada item trae serial + name
                if (isset($j['serial']) && isset($j['name'])) {
                    $readers[] = [
                        'serial' => (string)$j['serial'],
                        'name' => (string)$j['name'],
                    ];
                }
            }

            return $this->json($response, ['ok' => true, 'count' => $count, 'readers' => $readers], 200);
        } catch (\Exception $e) {
            $code = 500;

            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $code);
        }
    }
}
