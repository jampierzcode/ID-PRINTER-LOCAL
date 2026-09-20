<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Controllers\PrinterController;
use App\Controllers\TemplateController;
use App\Controllers\HelloController;
use App\Controllers\FingerprintController;
use App\Support\Timezone;


require 'vendor/autoload.php';

// Toma la zona horaria del propio Windows de esta PC (la misma que usa el
// reloj del sistema), en vez de fijar una sola para todos los restaurantes.
Timezone::applyFromSystem();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->add(function (Request $request, $handler) {
    $origin = $request->getHeaderLine('Origin') ?: '*';
    $response = $handler->handle($request);
    if ($request->getMethod() === 'OPTIONS') {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')


            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Content-Length', '0')
            ->withStatus(204);
        return $response;
    }
    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')


        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

$app->setBasePath('/nprint');

/* Rastreo de impresiones: marca la hora en que llegó cada petición de
 * impresión, antes de parsear nada. Ver App\Printing\PrintJobLog.
 *
 * Y aquí mismo se cortan los REENVÍOS de una petición que ya se imprimió. Con
 * la red del restaurante intermitente, el navegador de la tablet reenvía el
 * mismo POST cuando la conexión muere antes de que vuelva la respuesta: el
 * ticket ya había salido y sale otra vez. Como el cuerpo reenviado trae los
 * mismos `jobUid`, se reconoce y se contesta "ya está impreso" sin tocar la
 * impresora. Ver PrintJobLog::reenviados(). */
$app->add(function (Request $request, $handler) {
    $ruta = $request->getUri()->getPath();
    if ($request->getMethod() === 'POST' && strpos($ruta, '/printers/') !== false) {
        $endpoint = substr($ruta, strpos($ruta, '/printers/'));
        \App\Printing\PrintJobLog::iniciarPeticion($endpoint);

        /* Se lee el cuerpo y se deja rebobinado: el parser de Slim corre
         * después y necesita leerlo completo. Si el flujo no se pudiera
         * rebobinar, no se toca nada y todo sigue como antes — medir o
         * deduplicar jamás debe costar una impresión. */
        $flujo = $request->getBody();
        $uids = [];
        if ($flujo->isSeekable()) {
            $flujo->rewind();
            $cuerpo = $flujo->getContents();
            $flujo->rewind();
            $uids = \App\Printing\PrintJobLog::uidsDelCuerpo($cuerpo);
        }
        $repetidos = $uids ? \App\Printing\PrintJobLog::reenviados($uids, $endpoint) : [];

        /* Solo se corta si la petición ENTERA es un reenvío. Si trae aunque sea
         * un trabajo nuevo, se deja pasar: nunca dejar a cocina sin su ticket. */
        if ($uids && count($repetidos) === count($uids)) {
            $resultados = array_map(function ($uid) {
                return [
                    'success' => 1,
                    'duplicate' => true,
                    'job_uid' => $uid,
                    'message' => 'Este ticket ya se habia impreso (reenvio de la misma peticion); no se imprimio otra vez.',
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }, $repetidos);

            $respuesta = new \Slim\Psr7\Response();
            $respuesta->getBody()->write(json_encode($resultados, JSON_UNESCAPED_UNICODE));
            /* Los encabezados CORS van a mano: este middleware envuelve al de
             * CORS, así que al contestar aquí aquel ya no corre y el navegador
             * descartaría la respuesta. */
            $origen = $request->getHeaderLine('Origin') ?: '*';
            return $respuesta
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Access-Control-Allow-Origin', $origen)
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        }
    }
    return $handler->handle($request);
});

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Define app routes
$app->get('/hello/{name}', function (Request $request, Response $response, $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello, $name");
    return $response;
});


$app->get('/hello', [HelloController::class, 'index']);

// Rutas de impresoras
$app->group('/printers', function ($group) {
    $group->get('', [PrinterController::class, 'list']);
    $group->post('/print-test/{id}', [PrinterController::class, 'printTemplateTest']);
    $group->post('/print', [PrinterController::class, 'print']);
    $group->post('/print-comanda', [PrinterController::class, 'printComanda']);
    $group->post('/print-cancelacion', [PrinterController::class, 'printCancelacion']);
    $group->post('/open-drawer', [PrinterController::class, 'openDrawer']);
    $group->post('/print-propinas', [PrinterController::class, 'printPropinas']);
    $group->post('/print-movtos', [PrinterController::class, 'printMovtos']);
    $group->post('/print-corte-x', [PrinterController::class, 'printCorteX']);
    $group->post('/print-consumo', [PrinterController::class, 'printConsumo']);
    $group->post('/print-nota-venta', [PrinterController::class, 'printNotaVenta']);
    $group->post('/print-factura', [PrinterController::class, 'printFactura']);
    $group->post('/print-split-preview', [PrinterController::class, 'printSplitPreview']);
    $group->post('/print-split-final', [PrinterController::class, 'printSplitFinal']);
});

// Rutas de huellas
// Estado de cada trabajo de impresión (rastreo del POS).
$app->get('/jobs/diagnostico', [\App\Controllers\PrintJobsController::class, 'diagnostico']);
$app->get('/jobs', [\App\Controllers\PrintJobsController::class, 'status']);

$app->group('/fingerprint', function ($group) {
    $group->get('/list-readers', [FingerprintController::class, 'listReaders']);
    $group->post('/enroll', [FingerprintController::class, 'enroll']);
    $group->post('/verify', [FingerprintController::class, 'verify']);
    $group->post('/identify', [FingerprintController::class, 'identify']);
    $group->get('/enroll/debug', [FingerprintController::class, 'enrollDebug']);
});



// Rutas de templates
$app->group('/templates', function ($group) {
    $group->get('', [TemplateController::class, 'getAll']);
    $group->get('/{id}', [TemplateController::class, 'getById']);
    $group->post('/create', [TemplateController::class, 'create']);
    $group->post('/update/{id}', [TemplateController::class, 'update']);
    $group->put('/update/{id}', [TemplateController::class, 'update']);
    $group->put('/{id}', [TemplateController::class, 'update']);
    $group->delete('/{id}', [TemplateController::class, 'delete']);
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});


// Run app
$app->run();
