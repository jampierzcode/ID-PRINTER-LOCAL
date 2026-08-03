<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\TemplateModel;

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\GdEscposImage;

use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Builder\Builder;

use Exception;
use DateTime;


class PrinterController
{
    /**
     * Imprime múltiples tickets según el JSON recibido
     * POST /print
     * Body: array de objetos { templateId, printerName, data }
     */
    public function print(Request $request, Response $response, $args = [])
    {
        $jobs = $request->getParsedBody();
        if (!is_array($jobs)) {
            $rawBody = (string) $request->getBody();
            $jobs = json_decode($rawBody, true);
        }
        if (!is_array($jobs)) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'El cuerpo debe ser un array JSON válido.'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $results = [];
        foreach ($jobs as $job) {
            $templateId = $job['templateId'] ?? null;
            $printerName = $job['printerName'] ?? null;
            $data = $job['data'] ?? [];

            if (!$templateId) {
                $results[] = [
                    'success' => 0,
                    'message' => 'ID de template es requerido',
                    'template_id' => $templateId,
                    'printer_name' => $printerName
                ];
                continue;
            }
            if (!$printerName) {
                $results[] = [
                    'success' => 0,
                    'message' => 'Nombre de impresora es requerido',
                    'template_id' => $templateId,
                    'printer_name' => $printerName
                ];
                continue;
            }

            try {
                $model = new TemplateModel();
                $template = $model->getTemplateById($templateId);

                if (!$template) {
                    $response->getBody()->write(json_encode(['success' => 0, 'message' => 'Template no encontrado']));
                    return $response->withHeader('Content-Type', 'application/json');
                }

                $templateJson = json_decode($template['template_json'], true);
                $exampleJson = json_decode($template['example_json'], true);
                $caracteres = $template['caracteres'] ?? 48;
                $paperWidth = $caracteres * 8; // Calcula el ancho real en píxeles

                $connector = new WindowsPrintConnector($printerName);
                //$profile = CapabilityProfile::load("simple");
                $printer = new Printer($connector);

                // Inicializar impresora
                $printer->initialize();
                $printer->setJustification(Printer::JUSTIFY_LEFT);



                // Procesar cada elemento del template
                foreach ($templateJson as $item) {
                    $type = $item['type'] ?? 'text';
                    $align = $item['align'] ?? 'left';
                    $fontSize = $item['fontSize'] ?? '1x1';
                    $columns = $item['columns'] ?? [];

                    $textType = $item['textType'] ?? 'static';
                    $field = $item['field'] ?? '';
                    $barcodeFormat = $item['formatBarcode'] ?? 'CODE128';
                    $barcodeSizeMap = [
                        '1x' => ['width' => 1.0, 'height' => 30, 'fontSize' => 11],
                        '2x' => ['width' => 1.8, 'height' => 45, 'fontSize' => 14],
                        '3x' => ['width' => 2.6, 'height' => 60, 'fontSize' => 17],
                        '4x' => ['width' => 3.4, 'height' => 80, 'fontSize' => 20],
                        '5x' => ['width' => 4.2, 'height' => 100, 'fontSize' => 23]
                    ];
                    $barcodeSize = $barcodeSizeMap[$item['size'] ?? '1x'];

                    // Configurar alineación
                    switch ($align) {
                        case 'center':
                            $printer->setJustification(Printer::JUSTIFY_CENTER);
                            break;
                        case 'right':
                            $printer->setJustification(Printer::JUSTIFY_RIGHT);
                            break;
                        default:
                            $printer->setJustification(Printer::JUSTIFY_LEFT);
                            break;
                    }

                    // Configurar tamaño de fuente
                    $fontSizeMap = [
                        '10px'  => [1, 1],
                        '12px' => [2, 1],
                        '16px' => [1, 2],
                        '24px' => [2, 2],
                        '32px' => [4, 4]
                    ];
                    $fontSizeFrontend = $item['fontSize'] ?? '8px';
                    $size = $fontSizeMap[$fontSizeFrontend] ?? [1, 1];
                    $printer->setTextSize($size[0], $size[1]);

                    // Configurar alineación
                    switch ($align) {
                        case 'center':
                            $printer->setJustification(Printer::JUSTIFY_CENTER);
                            break;
                        case 'right':
                            $printer->setJustification(Printer::JUSTIFY_RIGHT);
                            break;
                        default:
                            $printer->setJustification(Printer::JUSTIFY_LEFT);
                            break;
                    }

                    // Configurar tamaño de fuente
                    $fontSizeMap = [
                        '11px' => [1, 1],
                        '12px' => [2, 1],
                        '16px' => [1, 2],
                        '24px' => [2, 2],
                        '32px' => [4, 4]
                    ];
                    $fontSizeFrontend = $item['fontSize'] ?? '8px';
                    $size = $fontSizeMap[$fontSizeFrontend] ?? [1, 1];
                    $printer->setTextSize($size[0], $size[1]);

                    // Procesar según tipo de elemento                
                    switch ($type) {
                        case 'text':
                            /* $text = $item['text'] ?? '';
                            if (is_array($text)) {
                                $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                            }
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            $printer->text($text . "\n");
                            // Restablecer estilos
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);*/
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            if (isset($item['leftText']) && isset($item['rightText'])) {
                                // Imprimir en dos columnas
                                $this->printTwoColumnLine($printer, $item['leftText'], $item['rightText']);
                            } else {
                                $text = $item['text'] ?? '';
                                if (is_array($text)) {
                                    $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                                }
                                $printer->text($text . "\n");
                            }
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);
                            break;

                        case 'field':
                            /*$field = $item['field'] ?? '';
                            $textBefore = $item['textBefore'] ?? '';
                            $textAfter = $item['textAfter'] ?? '';
                            $value = $this->getFieldValue($exampleJson, $field);
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            if (!empty($columns) && is_array($value)) {
                                $this->printTable($printer, $columns, $value);
                            } else {
                                if (is_array($value)) {
                                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                                }
                                $printer->text($textBefore . $value . $textAfter . "\n");
                            }
                            // Restablecer estilos
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);*/
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            if (isset($item['leftField']) && isset($item['rightField'])) {
                                // Obtener valores de los campos
                                $leftValue = $this->getFieldValue($data, $item['leftField']);
                                $rightValue = $this->getFieldValue($data, $item['rightField']);
                                $this->printTwoColumnLine($printer, $leftValue, $rightValue);
                            } else {
                                $field = $item['field'] ?? '';
                                $textBefore = $item['textBefore'] ?? '';
                                $textAfter = $item['textAfter'] ?? '';
                                $value = $this->getFieldValue($data, $field);
                                if (!empty($columns) && is_array($value)) {
                                    $this->printTable($printer, $columns, $value);
                                } else {
                                    if (is_array($value)) {
                                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                                    }
                                    $printer->text($textBefore . $value . $textAfter . "\n");
                                }
                            }
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);
                            break;

                        case 'line':
                            $printer->text(str_repeat('-', $caracteres) . "\n");
                            break;

                        case 'doubleline':
                            $printer->text(str_repeat('=', $caracteres) . "\n");
                            break;


                        case 'feed':
                            $lines = $item['lines'] ?? 1;
                            $printer->feed($lines);
                            break;

                        case 'newline':
                            $printer->feed(1);
                            break;
                    }
                }

                // Finalizar impresión
                $printer->feed(3);
                // buscar en templateJson en la columna type cut, si existe habilitar el corte
                if (in_array('cut', array_column($templateJson, 'type'))) {
                    $printer->cut();
                }

                $printer->close();

                $results[] = [
                    'success' => 1,
                    'message' => 'Ticket impreso correctamente en ' . $printerName,
                    'template_id' => $templateId,
                    'printer_name' => $printerName,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            } catch (Exception $e) {
                $results[] = [
                    'success' => 0,
                    'message' => 'Error al imprimir: ' . $e->getMessage(),
                    'template_id' => $templateId,
                    'printer_name' => $printerName,
                    'error_type' => 'general'
                ];
            }
        }

        $response->getBody()->write(json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Imprime la plantilla fija COMANDA TICKET usando datos directos del front sin templateId.
     * POST /printers/print-comanda
     */
    public function printComanda(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);

                    $printer->initialize();
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->text("COMANDA TICKET\n");
                    $printer->feed(1);
                    $printer->setTextSize(1, 1); // fuente pequeña (≈ tamaño 8) para contenidos
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->text("Area: " . ($data['areaName'] ?? '') . "\n");
                    $printer->text("Mesa: " . ($data['tableName'] ?? '') . "\n");
                    $printer->text("Orden: " . ($data['orderId'] ?? '') . "\n");
                    $printer->text(str_repeat('-', 48) . "\n");
                    $printer->text("Pedidos: \n");

                    $rawItems = $data['items'] ?? [];
                    $items = [];
                    if (is_array($rawItems)) {
                        foreach ($rawItems as $item) {
                            if (is_array($item)) {
                                $items[] = $item;
                            }
                        }
                    }

                    $modifiersByCompositeId = [];
                    $mainItems = [];
                    foreach ($items as $item) {
                        if (!empty($item['isModifier'])) {
                            $compositeKey = $item['compositeProductId'] ?? '';
                            if ($compositeKey !== '') {
                                $modifiersByCompositeId[$compositeKey][] = $item;
                            }
                            continue;
                        }
                        $mainItems[] = $item;
                    }

                    if (empty($mainItems)) {
                        $printer->text("Sin items registrados\n");
                    } else {
                        usort($mainItems, function ($a, $b) {
                            $courseA = $a['course'] ?? PHP_INT_MAX;
                            $courseB = $b['course'] ?? PHP_INT_MAX;
                            return $courseA <=> $courseB;
                        });

                        foreach ($mainItems as $item) {
                            $printer->text(str_repeat('-', 48) . "\n");

                            $courseLabel = $this->formatCourseLabel($item['course'] ?? null);
                            if ($courseLabel !== '') {
                                $printer->text("Tiempo: " . $courseLabel . "\n");
                            }

                            $qty = trim((string) ($item['qty'] ?? ''));
                            $name = trim((string) ($item['name'] ?? ''));
                            $itemLine = trim(($qty !== '' ? $qty . ' ' : '') . $name);
                            if ($itemLine === '') {
                                $itemLine = 'Producto sin nombre';
                            }
                            $printer->text($itemLine . "\n");

                            $notes = $item['notes'] ?? null;
                            if (!empty($notes)) {
                                $printer->text("Nota: " . $notes . "\n");
                            }

                            $compositeKey = $item['compositeProductId'] ?? '';
                            $modifiers = [];
                            if (!empty($item['isCompositeProductMain']) && $compositeKey !== '') {
                                $modifiers = $modifiersByCompositeId[$compositeKey] ?? [];
                            }

                            if (!empty($modifiers)) {
                                $printer->text("Modificadores:\n");
                                foreach ($modifiers as $modifier) {
                                    $halfLabel = $this->formatHalfLabel($modifier['half'] ?? null);
                                    $modifierName = (string) ($modifier['name'] ?? '');
                                    $modifierLabel = trim(($halfLabel !== '' ? $halfLabel . ' - ' : '') . $modifierName);
                                    if ($modifierLabel === '') {
                                        continue;
                                    }
                                    $printer->text('   ' . $modifierLabel . "\n");

                                    $modifierNotes = $modifier['notes'] ?? null;
                                    if (!empty($modifierNotes)) {
                                        $printer->text("      Nota: " . $modifierNotes . "\n");
                                    }
                                }
                            }

                            $printer->text("\n");
                        }
                    }

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket COMANDA impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'comanda_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                            // Intencionalmente silencioso para no interrumpir la respuesta
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    /**
     * Imprime el resumen de pagos de propinas por mesero.
     * POST /printers/print-propinas
     */
    public function printPropinas(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $printName = $job['printName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $propinas = [];
                if (isset($data['propinas']) && is_array($data['propinas'])) {
                    foreach ($data['propinas'] as $entry) {
                        if (is_array($entry)) {
                            $propinas[] = $entry;
                        }
                    }
                }

                $declaredTotal = $data['total'] ?? null;
                $calculatedTotal = 0;
                foreach ($propinas as $entry) {
                    $calculatedTotal += (float) ($entry['amount'] ?? 0);
                }
                $totalPropinas = $declaredTotal !== null ? (float) $declaredTotal : $calculatedTotal;

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);

                    $printer->initialize();
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->setEmphasis(true);
                    $printer->text("PAGO DE PROPINAS\n");
                    $printer->setEmphasis(false);
                    $printer->feed(1);
                    $printer->setTextSize(1, 1);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    if ($printName) {
                        $printer->text("Impresión: " . $printName . "\n");
                    }
                    $printer->text("Fecha: " . date('d/m/Y H:i:s') . "\n");
                    $printer->text(str_repeat('-', 48) . "\n");
                    $printer->text("Detalle de pagos:\n");

                    if (empty($propinas)) {
                        $printer->text("Sin registros de propinas.\n");
                    } else {
                        foreach ($propinas as $entry) {
                            $printer->text(str_repeat('-', 48) . "\n");
                            $printer->text("Orden: " . ($entry['orderId'] ?? '') . "   Mesa: " . ($entry['tableName'] ?? '') . "\n");
                            $printer->text("Mesero: " . ($entry['waiterFullName'] ?? '') . "\n");
                            $printer->text(
                                "Propina: " . $this->formatMoney($entry['amount'] ?? 0) .
                                    "  Cobrado: " . $this->formatMoney($entry['collected'] ?? 0) .
                                    "  Pagado: " . $this->formatMoney($entry['paid'] ?? 0) . "\n"
                            );
                        }
                        $printer->text(str_repeat('-', 48) . "\n");
                    }

                    $printer->feed(1);
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->setEmphasis(true);
                    $printer->text("TOTAL PROPINAS PAGADAS\n");
                    $printer->text($this->formatMoney($totalPropinas) . "\n");
                    $printer->setEmphasis(false);
                    $printer->setTextSize(1, 1);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket de propinas impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'propinas_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Imprime un reporte de movimiento de caja con el monto destacado.
     * POST /printers/print-movtos
     */
    public function printMovtos(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $restaurantId = $job['restaurantId'] ?? $job['restaurant_id'] ?? '';
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $movementType = strtoupper((string) ($data['type'] ?? ''));
                $amount = (float) ($data['amount'] ?? 0);
                $reason = (string) ($data['reason'] ?? '');
                $shiftId = $data['shiftId'] ?? '';
                $stationId = $data['stationId'] ?? '';
                $printerStationName = (string) ($data['printerStationName'] ?? '');
                $createdAt = $data['createdAt'] ?? '';
                $createdAtFormatted = '';
                if (!empty($createdAt)) {
                    try {
                        $dt = new DateTime($createdAt);
                        $createdAtFormatted = $dt->format('d/m/Y H:i:s');
                    } catch (Exception $e) {
                        $createdAtFormatted = $createdAt;
                    }
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);

                    $printer->initialize();
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->setEmphasis(true);
                    $printer->text("MOVIMIENTO DE CAJA\n");
                    $printer->setEmphasis(false);
                    $printer->feed(1);

                    $printer->setTextSize(1, 1);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);
                    $printer->text("Tipo: " . $movementType . "\n");
                    $printer->text("Estación: " . $printerStationName . "\n");
                    $printer->text("Shift ID: " . $shiftId . "   Station ID: " . $stationId . "\n");
                    $printer->text("Razón: " . $reason . "\n");
                    if ($createdAtFormatted !== '') {
                        $printer->text("Registrado: " . $createdAtFormatted . "\n");
                    }

                    $printer->feed(1);
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->setEmphasis(true);
                    $printer->text("MONTO\n");
                    $printer->text($this->formatMoney($amount) . "\n");
                    $printer->setEmphasis(false);
                    $printer->setTextSize(1, 1);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket de movimiento impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'movimiento_caja',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Imprime la CUENTA / NOTA DE CONSUMO (estilo SoftRestaurant) usando datos directos del front sin templateId.
     * POST /printers/print-consumo
     * Body: array de objetos { printerName, data }
     */
    /**
     * Cuerpo compartido por los 3 tickets de cuenta (consumo / nota de venta /
     * factura): cabecera del restaurante, bloque de orden, tabla de items,
     * descuento de orden, TOTAL grande, total en letra y subtotal/IVA. Cada
     * endpoint le agrega su propio footer (nada, QR de autofactura, o QR +
     * datos de timbrado).
     */
    private function printReceiptBody($printer, array $data, int $W): void
    {
        $rest = $data['restaurante'] ?? [];
        $ord  = $data['orden'] ?? [];
        $items = $data['items'] ?? [];
        $tot = $data['totales'] ?? [];
        $descuentoOrden = $data['descuentoOrden'] ?? null;

        $printer->initialize();

        /* ===== Cabecera Restaurante ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->setEmphasis(true);
        $printer->text(($rest['nombre'] ?? '') . "\n");
        $printer->setEmphasis(false);

        $printer->setTextSize(1, 1);
        if (!empty($rest['rfc'])) $printer->text($rest['rfc'] . "\n");
        if (!empty($rest['cp']))  $printer->text("CP " . $rest['cp'] . "\n");
        if (!empty($rest['direccion'])) $printer->text($rest['direccion'] . "\n");
        if (!empty($rest['tel'])) $printer->text("TEL: " . $rest['tel'] . "\n");

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Bloque Orden ===== */
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text("MESA:" . ($ord['mesa'] ?? '') . "\n");
        $printer->text("MESERO:" . ($ord['mesero'] ?? '') . "\n");

        $this->printTwoColumnLine(
            $printer,
            "PERSONAS:" . (string)($ord['personas'] ?? ''),
            "ORDEN:" . (string)($ord['orden'] ?? ''),
            $W
        );

        $printer->text("FOLIO:" . ($ord['folioSerie'] ?? '') . ' N°:' . ($ord['folioNumber'] ?? '') . "\n");

        if (!empty($ord['fechaCreacion'])) {
            $printer->text('Fecha Creacion: ' . $ord['fechaCreacion'] . "\n");
        }
        if (!empty($ord['fechaImpresion'])) {
            $printer->text('Fecha Impresión: ' . $ord['fechaImpresion'] . "\n");
        }

        $printer->text("CAJERO:" . ($ord['cajero'] ?? '') . "\n");

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Encabezado Tabla ===== */
        $printer->setEmphasis(true);
        $header =
            str_pad("CANT.", 5) . " " .
            str_pad("DESCRIPCION", 31) . " " .
            str_pad("IMPORTE", 10, " ", STR_PAD_LEFT);
        $printer->text($header . "\n");
        $printer->setEmphasis(false);

        /* ===== Items ===== */
        if (!is_array($items) || empty($items)) {
            $printer->text("Sin items\n");
        } else {
            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $qty  = $it['cantidad'] ?? '';
                $desc = (string)($it['descripcion'] ?? '');
                $imp  = $it['importe'] ?? 0;

                // Si es cortesía, marcar en la descripción
                if (!empty($it['isCourtesy'])) {
                    $desc = $desc . ' [CORTESIA]';
                }

                $this->printItemRow($printer, $qty, $desc, $imp, $W);

                // Imprimir línea de descuento por item si aplica
                $descuento = isset($it['descuento']) ? (float)$it['descuento'] : 0;
                $descuentoLabel = (string)($it['descuentoLabel'] ?? '');
                if ($descuento > 0 && $descuentoLabel !== '') {
                    $discLine = str_pad('', 6) .
                        str_pad($descuentoLabel, 30) . ' ' .
                        str_pad('-' . $this->formatMoney($descuento), 10, ' ', STR_PAD_LEFT);
                    $printer->text($discLine . "\n");
                }
            }
        }

        $printer->text(str_repeat('-', $W) . "\n");

        /* ===== Descuento de orden ===== */
        if (is_array($descuentoOrden) && ($descuentoOrden['monto'] ?? 0) > 0) {
            $dTipo = $descuentoOrden['tipo'] ?? '';
            $dValor = $descuentoOrden['valor'] ?? 0;
            $dMonto = $descuentoOrden['monto'] ?? 0;

            $dLabel = 'DCTO ORDEN';
            if ($dTipo === 'percent') {
                $dLabel = 'DCTO ORDEN (' . $dValor . '%)';
            }

            $this->printTwoColumnLine(
                $printer,
                $dLabel,
                '-' . $this->formatMoney($dMonto),
                $W
            );
            $printer->text(str_repeat('-', $W) . "\n");
        }

        /* ===== TOTAL grande ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->text("TOTAL: " . $this->formatMoney($tot['total'] ?? 0) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Total en letra ===== */
        if (!empty($tot['totalEnLetra'])) {
            $printer->text($tot['totalEnLetra'] . "\n\n");
        }

        /* ===== Subtotal / IVA ===== */
        $this->printTwoColumnLine(
            $printer,
            "SUBTOTAL:" . $this->formatMoney($tot['subtotal'] ?? 0),
            "IVA:" . $this->formatMoney($tot['iva'] ?? 0),
            $W
        );
    }

    /**
     * Ticket de cuenta simple — SIN QR de facturación. Se imprime al
     * comandar/imprimir cuenta, antes de cobrar. La invitación a facturar
     * vive ahora solo en la nota de venta (print-nota-venta), al cobrar.
     */
    public function printConsumo(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);
                    $W = 48; // ancho 80mm típico

                    $this->printReceiptBody($printer, $data, $W);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket CONSUMO impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'consumo_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Nota de venta — se imprime AL COBRAR cuando el cliente no pidió factura
     * en el momento. Mismo cuerpo que el ticket + QR de autofactura futura,
     * con el mensaje de cuántos días tiene para pedirla (data.diasParaFacturar).
     */
    public function printNotaVenta(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = ['success' => 0, 'message' => 'Nombre de impresora es requerido', 'printer_name' => $printerName];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = ['success' => 0, 'message' => 'El campo data debe ser un objeto', 'printer_name' => $printerName];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);
                    $W = 48;

                    $this->printReceiptBody($printer, $data, $W);

                    $facturarUrl = $this->buildFacturarUrl($job['restaurantId'] ?? null);
                    $diasParaFacturar = $data['diasParaFacturar'] ?? null;

                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->feed(1);

                    $printer->setEmphasis(true);
                    $printer->text("ESTO NO ES UN COMPROBANTE FISCAL\n");
                    $printer->feed(2);

                    $printer->text("ESCANEA EL SIGUIENTE CODIGO QR\n");
                    $printer->text("PARA PODER EMITIR TU FACTURA\n");
                    $printer->text("ELECTRONICA\n");
                    $printer->setEmphasis(false);

                    if (!empty($diasParaFacturar)) {
                        $printer->feed(1);
                        $printer->setEmphasis(true);
                        $printer->text("TIENES " . (int)$diasParaFacturar . " DIAS PARA FACTURAR\n");
                        $printer->setEmphasis(false);
                    }

                    $printer->feed(1);
                    if (!empty($facturarUrl)) {
                        $printer->qrCode($facturarUrl, Printer::QR_ECLEVEL_M, 6, Printer::QR_MODEL_2);
                        $printer->feed(1);
                    }

                    $printer->feed(1);
                    $printer->text("POS GROWTHSUITE\n");
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Nota de venta impresa correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'factura_url' => $facturarUrl,
                        'template' => 'nota_venta_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = ['success' => 0, 'message' => 'Error al imprimir: ' . $e->getMessage(), 'printer_name' => $printerName, 'error_type' => 'general'];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Comprobante de factura YA generada — se imprime al cobrar cuando el
     * cliente sí pidió factura y Facturapi la timbró con éxito. Mismo cuerpo
     * + nombre/RFC del cliente, datos de timbrado (serie/folio propio + UUID)
     * y un QR que apunta al PDF ya generado (no a crear una nueva).
     * Espera en data.factura: { legalName, taxId, series, folioNumber, uuid, pdfUrl }
     */
    public function printFactura(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = ['success' => 0, 'message' => 'Nombre de impresora es requerido', 'printer_name' => $printerName];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = ['success' => 0, 'message' => 'El campo data debe ser un objeto', 'printer_name' => $printerName];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);
                    $W = 48;

                    $this->printReceiptBody($printer, $data, $W);

                    $fac = $data['factura'] ?? [];
                    $pdfUrl = $fac['pdfUrl'] ?? null;

                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->feed(1);

                    $printer->setEmphasis(true);
                    $printer->text("FACTURA ELECTRONICA (CFDI)\n");
                    $printer->setEmphasis(false);
                    $printer->feed(1);

                    $printer->setJustification(Printer::JUSTIFY_LEFT);
                    if (!empty($fac['legalName'])) $printer->text("FACTURADO A: " . $fac['legalName'] . "\n");
                    if (!empty($fac['taxId'])) $printer->text("RFC: " . $fac['taxId'] . "\n");
                    if (!empty($fac['series']) || !empty($fac['folioNumber'])) {
                        $printer->text("SERIE-FOLIO: " . ($fac['series'] ?? '') . '-' . ($fac['folioNumber'] ?? '') . "\n");
                    }
                    if (!empty($fac['uuid'])) $printer->text("UUID: " . $fac['uuid'] . "\n");

                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->feed(1);

                    if (!empty($pdfUrl)) {
                        $printer->text("ESCANEA PARA VER TU FACTURA\n");
                        $printer->feed(1);
                        $printer->qrCode($pdfUrl, Printer::QR_ECLEVEL_M, 6, Printer::QR_MODEL_2);
                        $printer->feed(1);
                    }

                    $printer->feed(1);
                    $printer->text("POS GROWTHSUITE\n");
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Factura impresa correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'pdf_url' => $pdfUrl,
                        'template' => 'factura_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = ['success' => 0, 'message' => 'Error al imprimir: ' . $e->getMessage(), 'printer_name' => $printerName, 'error_type' => 'general'];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Imprime una fila de item tipo: [CANT] [DESCRIPCION] [IMPORTE]
     * Con wrap de descripción si se pasa del ancho.
     */
    private function printItemRow($printer, $qty, $desc, $importe, $totalWidth = 48)
    {
        $wQty = 5;
        $wDesc = 31;
        $wImp = 10;

        $qtyStr = (string)$qty;
        $impStr = $this->formatMoney($importe);

        // Recorta/parte descripción para wrap
        $desc = trim((string)$desc);
        if ($desc === '') $desc = 'Producto';

        $first = $this->safeSubstr($desc, 0, $wDesc);
        $line =
            str_pad($qtyStr, $wQty) . " " .
            str_pad($first, $wDesc) . " " .
            str_pad($impStr, $wImp, " ", STR_PAD_LEFT);

        $printer->text($line . "\n");

        // Si sobra descripción, imprimir líneas extra indentadas (sin importe)
        $rest = $this->safeSubstr($desc, $wDesc);
        while (!empty($rest)) {
            $chunk = $this->safeSubstr($rest, 0, $wDesc);
            $rest = $this->safeSubstr($rest, $wDesc);

            $printer->text(
                str_pad("", $wQty) . " " .
                    str_pad($chunk, $wDesc) . " " .
                    str_pad("", $wImp) . "\n"
            );
        }
    }

    /**
     * Formatea dinero como $130.00
     */
    private function formatMoney($value)
    {
        $n = is_numeric($value) ? (float)$value : 0;
        return '$' . number_format($n, 2, '.', '');
    }


    /**
     * Construye la URL completa para facturación usando la variable de entorno disponible.
     */
    private function buildFacturarUrl($restaurantId)
    {
        $restaurantId = trim((string) $restaurantId);
        if ($restaurantId === '') {
            return '';
        }
        $envKeys = ['FACTURACION_BASE_URL', 'POS_BASE_URL', 'APP_URL', 'BASE_URL'];
        foreach ($envKeys as $key) {
            $baseUrl = $this->getEnvValue($key);
            if ($baseUrl === '') {
                continue;
            }
            $base = rtrim($baseUrl, '/');
            return $base . '/' . ltrim($restaurantId, '/') . '/facturar';
        }
        return '';
    }

    private function getEnvValue($key)
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        static $cachedLocalEnv = null;
        if ($cachedLocalEnv === null) {
            $cachedLocalEnv = $this->parseLocalEnvFile();
        }
        return $cachedLocalEnv[$key] ?? '';
    }

    private function parseLocalEnvFile()
    {
        $path = realpath(__DIR__ . '/../.env');
        if ($path === false || !file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            list($rawKey, $rawValue) = explode('=', $line, 2);
            $key = trim($rawKey);
            $value = trim($rawValue);
            if ($value === '') {
                continue;
            }
            $length = strlen($value);
            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, $length - 2);
                }
            }
            $values[$key] = $value;
        }
        return $values;
    }

    public function list(Request $request, Response $response, $args = [])
    {
        $printers = [];
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Obtener detalles adicionales de las impresoras en Windows
            exec('wmic printer get Name,Shared,WorkOffline,Default,Status,Network,Availability /format:csv', $output);
            $headers = [];
            foreach ($output as $i => $line) {
                $line = trim($line);
                if ($line === '' || stripos($line, 'Node,Name') !== false) continue;
                if (empty($headers) && strpos($line, ',') !== false) {
                    $headers = array_map('trim', explode(',', $line));
                    continue;
                }
                if ($line && strpos($line, ',') !== false) {
                    $cols = array_map('trim', explode(',', $line));
                    // Si hay más columnas que headers, ajusta
                    if (count($cols) > count($headers)) {
                        $cols = array_slice($cols, -count($headers));
                    }
                    $printer = [];
                    foreach ($headers as $idx => $header) {
                        $printer[$header] = $cols[$idx] ?? null;
                    }
                    if (!empty($printer['Name'])) {
                        $printers[] = [
                            'name'        => $printer['Name'],
                            'shared'      => $printer['Shared'],
                            'work_offline' => $printer['WorkOffline'],
                            'default'     => $printer['Default'],
                            'status'      => $printer['Status'],
                            'network'     => $printer['Network'],
                            'availability' => $printer['Availability'],
                        ];
                    }
                }
            }
        } else {
            // Linux: obtener nombre y estado de impresoras
            exec('lpstat -p', $output);
            foreach ($output as $line) {
                if (preg_match('/^printer\s+(\S+)\s+(.*)$/', $line, $matches)) {
                    $printers[] = [
                        'name'   => $matches[1],
                        'status' => $matches[2],
                    ];
                }
            }
        }
        $payload = json_encode(['printers' => $printers], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function printTemplateTest(Request $request, Response $response, $args)
    {

        $id = $args['id'] ?? null;
        $printerName = $request->getParsedBody()['printerName'] ?? null;

        if (!$id) {
            throw new Exception('ID de template es requerido');
        }

        if (!$printerName) {
            throw new Exception('Nombre de impresora es requerido');
        }

        try {
            $model = new TemplateModel();
            $template = $model->getTemplateById($id);

            if (!$template) {
                $response->getBody()->write(json_encode(['success' => 0, 'message' => 'Template no encontrado']));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $templateJson = json_decode($template['template_json'], true);
            $exampleJson = json_decode($template['example_json'], true);
            $caracteres = $template['caracteres'] ?? 48;
            $paperWidth = $caracteres * 8; // Calcula el ancho real en píxeles

            $connector = new WindowsPrintConnector($printerName);
            //$profile = CapabilityProfile::load("simple");
            $printer = new Printer($connector);

            // Inicializar impresora
            $printer->initialize();
            $printer->setJustification(Printer::JUSTIFY_LEFT);

            // Procesar cada elemento del template
            foreach ($templateJson as $item) {
                $type = $item['type'] ?? 'text';
                $align = $item['align'] ?? 'left';
                $fontSize = $item['fontSize'] ?? '1x1';
                $columns = $item['columns'] ?? [];

                $textType = $item['textType'] ?? 'static';
                $field = $item['field'] ?? '';
                $barcodeFormat = $item['formatBarcode'] ?? 'CODE128';
                $barcodeSizeMap = [
                    '1x' => ['width' => 1.0, 'height' => 30, 'fontSize' => 11],
                    '2x' => ['width' => 1.8, 'height' => 45, 'fontSize' => 14],
                    '3x' => ['width' => 2.6, 'height' => 60, 'fontSize' => 17],
                    '4x' => ['width' => 3.4, 'height' => 80, 'fontSize' => 20],
                    '5x' => ['width' => 4.2, 'height' => 100, 'fontSize' => 23]
                ];
                $barcodeSize = $barcodeSizeMap[$item['size'] ?? '1x'];

                // Configurar alineación
                switch ($align) {
                    case 'center':
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        break;
                    case 'right':
                        $printer->setJustification(Printer::JUSTIFY_RIGHT);
                        break;
                    default:
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                        break;
                }

                // Configurar tamaño de fuente
                $fontSizeMap = [
                    '11px' => [1, 1],
                    '12px' => [2, 1],
                    '16px' => [1, 2],
                    '24px' => [2, 2],
                    '32px' => [4, 4]
                ];
                $fontSizeFrontend = $item['fontSize'] ?? '8px';
                $size = $fontSizeMap[$fontSizeFrontend] ?? [1, 1];
                $printer->setTextSize($size[0], $size[1]);

                // Procesar según tipo de elemento
                switch ($type) {
                    case 'text':
                        /* $text = $item['text'] ?? '';
                        if (is_array($text)) {
                            $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                        }
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        $printer->text($text . "\n");
                        // Restablecer estilos
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);*/
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        if (isset($item['leftText']) && isset($item['rightText'])) {
                            // Imprimir en dos columnas
                            $this->printTwoColumnLine($printer, $item['leftText'], $item['rightText']);
                        } else {
                            $text = $item['text'] ?? '';
                            if (is_array($text)) {
                                $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                            }
                            $printer->text($text . "\n");
                        }
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);
                        break;

                    case 'field':
                        /*$field = $item['field'] ?? '';
                        $textBefore = $item['textBefore'] ?? '';
                        $textAfter = $item['textAfter'] ?? '';
                        $value = $this->getFieldValue($exampleJson, $field);
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        if (!empty($columns) && is_array($value)) {
                            $this->printTable($printer, $columns, $value);
                        } else {
                            if (is_array($value)) {
                                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                            }
                            $printer->text($textBefore . $value . $textAfter . "\n");
                        }
                        // Restablecer estilos
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);*/
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        if (isset($item['leftField']) && isset($item['rightField'])) {
                            // Obtener valores de los campos
                            $leftValue = $this->getFieldValue($exampleJson, $item['leftField']);
                            $rightValue = $this->getFieldValue($exampleJson, $item['rightField']);
                            $this->printTwoColumnLine($printer, $leftValue, $rightValue);
                        } else {
                            $field = $item['field'] ?? '';
                            $textBefore = $item['textBefore'] ?? '';
                            $textAfter = $item['textAfter'] ?? '';
                            $value = $this->getFieldValue($exampleJson, $field);
                            if (!empty($columns) && is_array($value)) {
                                $this->printTable($printer, $columns, $value);
                            } else {
                                if (is_array($value)) {
                                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                                }
                                $printer->text($textBefore . $value . $textAfter . "\n");
                            }
                        }
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);
                        break;

                    case 'line':
                        $printer->text(str_repeat('-', $caracteres) . "\n");
                        break;

                    case 'doubleline':
                        $printer->text(str_repeat('=', $caracteres) . "\n");
                        break;

                    case 'feed':
                        $lines = $item['lines'] ?? 1;
                        $printer->feed($lines);
                        break;

                    case 'newline':
                        $printer->feed(1);
                        break;
                }
            }

            // Finalizar impresión
            $printer->feed(3);
            // buscar en templateJson en la columna type cut, si existe habilitar el corte
            if (in_array('cut', array_column($templateJson, 'type'))) {
                $printer->cut();
            }

            $printer->close();

            $response->getBody()->write(json_encode([
                'success' => 1,
                'message' => 'Ticket impreso correctamente en ' . $printerName,
                'template_id' => $id,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error de conexión con impresora: ' . $e->getMessage(),
                'error_type' => 'printer_connection'
            ]));
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al imprimir: ' . $e->getMessage(),
                'error_type' => 'general'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Imprime una línea con dos columnas (izquierda y derecha) ajustadas al ancho total
     */

    private function printTwoColumnLine($printer, $left, $right, $totalWidth = 48)
    {
        $maxLeft = $totalWidth - strlen($right) - 1;
        $left = $this->safeSubstr($left, 0, $maxLeft);
        $right = $this->safeSubstr($right, 0, $totalWidth - strlen($left) - 1);
        $spaces = $totalWidth - strlen($left) - strlen($right);
        $line = $left . str_repeat(' ', $spaces) . $right . "\n";
        $printer->text($line);
    }

    /**
     * Obtiene el valor de un campo, soportando notación punto para campos anidados
     */
    private function getFieldValue($data, $field)
    {
        if (strpos($field, '.') === false) {
            return $data[$field] ?? '';
        }

        $parts = explode('.', $field);
        $value = $data;
        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                return '';
            }
        }
        return $value;
    }

    /**
     * Fallback para substitución de subcadenas cuando faltan las funciones multibyte.
     */
    private function safeSubstr($text, $start, $length = null)
    {
        if (!is_string($text)) {
            $text = (string) $text;
        }
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($text, $start) : mb_substr($text, $start, $length);
        }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }

    /**
     * Imprime una tabla con columnas específicas
     */
    private function printTable($printer, $columns, $data)
    {
        // Calcular ancho disponible (48 caracteres para papel 80mm)
        $totalWidth = 48;
        $colCount = count($columns);
        $colWidth = floor(($totalWidth - $colCount + 1) / $colCount); // -1 por separadores

        // Imprimir encabezados
        $header = '';
        foreach ($columns as $i => $col) {
            $header .= str_pad(substr($col, 0, $colWidth), $colWidth);
            if ($i < $colCount - 1) $header .= ' ';
        }
        $printer->setEmphasis(true);
        $printer->text($header . "\n");
        $printer->setEmphasis(false);

        // Línea separadora
        $printer->text(str_repeat('-', $totalWidth) . "\n");

        // Imprimir filas de datos
        foreach ($data as $row) {
            $line = '';
            foreach ($columns as $i => $col) {
                $cellValue = isset($row[$col]) ? $row[$col] : '';
                $line .= str_pad(substr($cellValue, 0, $colWidth), $colWidth);
                if ($i < $colCount - 1) $line .= ' ';
            }
            $printer->text($line . "\n");
        }
    }
    /**
     * Traduce el formato de código de barras del frontend al formato de Mike42
     */
    public static function translateBarcodeFormat($frontendFormat)
    {
        $map = [
            'CODE128' => 'BARCODE_CODE128',
            'CODE39'  => 'BARCODE_CODE39',
            'EAN13'   => 'BARCODE_JAN13',
            'EAN8'    => 'BARCODE_JAN8',
            'UPC'     => 'BARCODE_UPCA',
            'ITF'     => 'BARCODE_ITF'
        ];
        return $map[$frontendFormat] ?? null;
    }

    /**
     * Devuelve la etiqueta de curso legible para la plantilla fija.
     */
    private function formatCourseLabel($course)
    {
        $map = [
            1 => '1er tiempo',
            2 => '2do tiempo',
            3 => '3er tiempo'
        ];
        if ($course !== null && isset($map[$course])) {
            return $map[$course];
        }
        if (is_numeric($course)) {
            return 'Tiempo ' . intval($course);
        }
        return '';
    }

    /**
     * Convierte el valor de half en una etiqueta legible.
     */
    private function formatHalfLabel($half)
    {
        $map = [
            1 => 'TODO',
            2 => '1ERA MITAD',
            3 => '2DA MITAD'
        ];
        if ($half !== null && isset($map[$half])) {
            return $map[$half];
        }
        return '';
    }

    /* ════════════════════════════════════════════════════════════════
     *  SPLIT-PAY: PRE-IMPRESIÓN (preview, antes de cobrar)
     *  POST /printers/print-split-preview
     *
     *  Body: array de jobs. Cada job imprime:
     *    1) Ticket consolidado de la cuenta + lista de DIVISIÓN propuesta
     *    2) N vouchers preliminares (uno por cada persona) marcados
     *       "PRELIMINAR — NO PAGADO"
     *
     *  data esperada por job:
     *    {
     *      restaurante: { nombre, rfc, cp, direccion, tel },
     *      orden: { mesa, mesero, personas, orden, folioSerie, folioNumber,
     *               fechaCreacion, fechaImpresion, cajero },
     *      items: [...],            // mismo formato que printConsumo
     *      totales: { subtotal, iva, total, totalEnLetra },
     *      descuentoOrden: ...,     // opcional
     *      splits: [ { payerName, amount, paymentMethod? }, ... ]
     *    }
     * ════════════════════════════════════════════════════════════════ */
    public function printSplitPreview(Request $request, Response $response, $args = [])
    {
        return $this->printSplitInternal($request, $response, /*final*/ false);
    }

    /* ════════════════════════════════════════════════════════════════
     *  SPLIT-PAY: IMPRESIÓN FINAL (después de cobrar)
     *  POST /printers/print-split-final
     *
     *  Body: array de jobs. Cada job imprime:
     *    1) Ticket consolidado de la cuenta con la lista de pagos (nombre,
     *       monto y método por persona)
     *    2) N vouchers finales (uno por persona) marcados "PAGADO" con su
     *       método de pago
     *
     *  data esperada por job: igual a printSplitPreview, pero cada split
     *  debe traer paymentMethod (texto). Se asume que todos están pagados.
     * ════════════════════════════════════════════════════════════════ */
    public function printSplitFinal(Request $request, Response $response, $args = [])
    {
        return $this->printSplitInternal($request, $response, /*final*/ true);
    }

    /* Implementación común para preview y final. */
    private function printSplitInternal(Request $request, Response $response, bool $isFinal)
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $splits = $data['splits'] ?? [];
                if (!is_array($splits) || empty($splits)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'splits[] requerido con al menos 1 división',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);

                    /* (1) Ticket consolidado de la cuenta */
                    $this->renderConsolidatedTicket($printer, $data, $isFinal);

                    /* (2) N vouchers individuales — uno por persona */
                    $orderTotal = (float)($data['totales']['total'] ?? 0);
                    foreach ($splits as $idx => $sp) {
                        if (!is_array($sp)) continue;
                        $this->renderSplitVoucher(
                            $printer,
                            $data,
                            $sp,
                            $idx + 1,
                            count($splits),
                            $orderTotal,
                            $isFinal
                        );
                    }

                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => ($isFinal ? 'Tickets de split-pay FINAL' : 'Tickets de split-pay PRELIMINAR')
                            . ' impresos en ' . $printerName,
                        'printer_name' => $printerName,
                        'splits' => count($splits),
                        'template' => $isFinal ? 'split_final_ticket' : 'split_preview_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (Exception $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /* Renderiza el ticket consolidado de la cuenta con la sección de splits.
     * Mismo layout que printConsumo pero con un bloque "DIVIDIDO EN:" antes
     * del TOTAL grande. Si $isFinal, muestra el método de pago de cada split. */
    private function renderConsolidatedTicket($printer, array $data, bool $isFinal): void
    {
        $W = 48;

        $rest = $data['restaurante'] ?? [];
        $ord  = $data['orden'] ?? [];
        $items = $data['items'] ?? [];
        $tot = $data['totales'] ?? [];
        $descuentoOrden = $data['descuentoOrden'] ?? null;
        $splits = $data['splits'] ?? [];

        $printer->initialize();

        /* ===== Cabecera Restaurante ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->setEmphasis(true);
        $printer->text(($rest['nombre'] ?? '') . "\n");
        $printer->setEmphasis(false);

        $printer->setTextSize(1, 1);
        if (!empty($rest['rfc'])) $printer->text($rest['rfc'] . "\n");
        if (!empty($rest['cp']))  $printer->text("CP " . $rest['cp'] . "\n");
        if (!empty($rest['direccion'])) $printer->text($rest['direccion'] . "\n");
        if (!empty($rest['tel'])) $printer->text("TEL: " . $rest['tel'] . "\n");

        /* ===== Aviso de modo (preliminar / final) ===== */
        $printer->setEmphasis(true);
        $printer->text(str_repeat('*', $W) . "\n");
        $printer->text(($isFinal ? "CUENTA DIVIDIDA - PAGADA" : "CUENTA DIVIDIDA - PRELIMINAR") . "\n");
        $printer->text(str_repeat('*', $W) . "\n");
        $printer->setEmphasis(false);

        /* ===== Bloque Orden ===== */
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text("MESA:" . ($ord['mesa'] ?? '') . "\n");
        $printer->text("MESERO:" . ($ord['mesero'] ?? '') . "\n");

        $this->printTwoColumnLine(
            $printer,
            "PERSONAS:" . (string)($ord['personas'] ?? ''),
            "ORDEN:" . (string)($ord['orden'] ?? ''),
            $W
        );

        $printer->text("FOLIO:" . ($ord['folioSerie'] ?? '') . ' N°:' . ($ord['folioNumber'] ?? '') . "\n");

        if (!empty($ord['fechaCreacion'])) {
            $printer->text('Fecha Creacion: ' . $ord['fechaCreacion'] . "\n");
        }
        if (!empty($ord['fechaImpresion'])) {
            $printer->text('Fecha Impresión: ' . $ord['fechaImpresion'] . "\n");
        }
        $printer->text("CAJERO:" . ($ord['cajero'] ?? '') . "\n");

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Encabezado Tabla ===== */
        $printer->setEmphasis(true);
        $header =
            str_pad("CANT.", 5) . " " .
            str_pad("DESCRIPCION", 31) . " " .
            str_pad("IMPORTE", 10, " ", STR_PAD_LEFT);
        $printer->text($header . "\n");
        $printer->setEmphasis(false);

        /* ===== Items ===== */
        if (!is_array($items) || empty($items)) {
            $printer->text("Sin items\n");
        } else {
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $qty  = $it['cantidad'] ?? '';
                $desc = (string)($it['descripcion'] ?? '');
                $imp  = $it['importe'] ?? 0;
                if (!empty($it['isCourtesy'])) {
                    $desc = $desc . ' [CORTESIA]';
                }
                $this->printItemRow($printer, $qty, $desc, $imp, $W);

                $descuento = isset($it['descuento']) ? (float)$it['descuento'] : 0;
                $descuentoLabel = (string)($it['descuentoLabel'] ?? '');
                if ($descuento > 0 && $descuentoLabel !== '') {
                    $discLine = str_pad('', 6) .
                        str_pad($descuentoLabel, 30) . ' ' .
                        str_pad('-' . $this->formatMoney($descuento), 10, ' ', STR_PAD_LEFT);
                    $printer->text($discLine . "\n");
                }
            }
        }

        $printer->text(str_repeat('-', $W) . "\n");

        /* ===== Descuento de orden ===== */
        if (is_array($descuentoOrden) && ($descuentoOrden['monto'] ?? 0) > 0) {
            $dTipo = $descuentoOrden['tipo'] ?? '';
            $dValor = $descuentoOrden['valor'] ?? 0;
            $dMonto = $descuentoOrden['monto'] ?? 0;
            $dLabel = 'DCTO ORDEN';
            if ($dTipo === 'percent') {
                $dLabel = 'DCTO ORDEN (' . $dValor . '%)';
            }
            $this->printTwoColumnLine(
                $printer,
                $dLabel,
                '-' . $this->formatMoney($dMonto),
                $W
            );
            $printer->text(str_repeat('-', $W) . "\n");
        }

        /* ===== TOTAL grande ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->text("TOTAL: " . $this->formatMoney($tot['total'] ?? 0) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text(str_repeat('=', $W) . "\n");

        if (!empty($tot['totalEnLetra'])) {
            $printer->text($tot['totalEnLetra'] . "\n\n");
        }

        $this->printTwoColumnLine(
            $printer,
            "SUBTOTAL:" . $this->formatMoney($tot['subtotal'] ?? 0),
            "IVA:" . $this->formatMoney($tot['iva'] ?? 0),
            $W
        );

        /* ===== Bloque DIVIDIDO EN ===== */
        $printer->text(str_repeat('=', $W) . "\n");
        $printer->setEmphasis(true);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text(($isFinal ? "DETALLE DE PAGOS" : "DIVISION PROPUESTA") . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $W) . "\n");

        $sumSplit = 0.0;
        $sumTip = 0.0;
        foreach ($splits as $idx => $sp) {
            if (!is_array($sp)) continue;
            $name = (string)($sp['payerName'] ?? ('Persona ' . ($idx + 1)));
            $amount = (float)($sp['amount'] ?? 0);
            $tipAmount = (float)($sp['tipAmount'] ?? 0);
            $personalTotal = $amount + $tipAmount;
            $method = (string)($sp['paymentMethod'] ?? '');
            $sumSplit += $amount;
            $sumTip += $tipAmount;

            $left = '#' . ($idx + 1) . ' ' . $name;
            if ($isFinal && $method !== '') {
                $left .= ' (' . $method . ')';
            }
            $this->printTwoColumnLine($printer, $left, $this->formatMoney($personalTotal), $W);
            if ($tipAmount > 0) {
                // Línea secundaria con breakdown: consumo + propina
                $breakdownRight = $this->formatMoney($amount) . ' + tip ' . $this->formatMoney($tipAmount);
                $this->printTwoColumnLine($printer, '   ', $breakdownRight, $W);
            }
        }
        $printer->text(str_repeat('-', $W) . "\n");
        $this->printTwoColumnLine(
            $printer,
            'CONSUMO DIVIDIDO',
            $this->formatMoney($sumSplit),
            $W
        );
        if ($sumTip > 0) {
            $this->printTwoColumnLine(
                $printer,
                'PROPINAS DIVIDIDAS',
                $this->formatMoney($sumTip),
                $W
            );
            $this->printTwoColumnLine(
                $printer,
                'GRAN TOTAL',
                $this->formatMoney($sumSplit + $sumTip),
                $W
            );
        }

        /* ===== Footer ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->feed(1);
        $printer->setEmphasis(true);
        $printer->text("ESTO NO ES UN COMPROBANTE FISCAL\n");
        $printer->setEmphasis(false);
        $printer->feed(1);
        $printer->text("POS GROWTHSUITE\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->feed(3);
        $printer->cut();
    }

    /* Renderiza UN voucher individual de uno de los splits. */
    private function renderSplitVoucher(
        $printer,
        array $data,
        array $split,
        int $idx,
        int $totalSplits,
        float $orderTotal,
        bool $isFinal
    ): void {
        $W = 48;
        $rest = $data['restaurante'] ?? [];
        $ord  = $data['orden'] ?? [];
        $name = (string)($split['payerName'] ?? ('Persona ' . $idx));
        $amount = (float)($split['amount'] ?? 0);
        $tipAmount = (float)($split['tipAmount'] ?? 0);
        $personalTotal = $amount + $tipAmount;
        $method = (string)($split['paymentMethod'] ?? '');

        $printer->initialize();

        /* Header del voucher */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(1, 2);
        $printer->text("VOUCHER DE PAGO INDIVIDUAL\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        $printer->text(str_repeat('=', $W) . "\n");

        /* Estado del voucher */
        $printer->setEmphasis(true);
        $printer->text(($isFinal ? "*** PAGADO ***" : "*** PRELIMINAR — NO PAGADO ***") . "\n");
        $printer->setEmphasis(false);

        $printer->text(str_repeat('=', $W) . "\n");

        /* Restaurante (compacto) */
        $printer->setEmphasis(true);
        $printer->text(($rest['nombre'] ?? '') . "\n");
        $printer->setEmphasis(false);

        /* Datos de la orden (compactos) */
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("MESA: " . ($ord['mesa'] ?? '') . "\n");
        $printer->text("FOLIO: " . ($ord['folioSerie'] ?? '') . ' N°:' . ($ord['folioNumber'] ?? '') . "\n");
        if (!empty($ord['fechaImpresion'])) {
            $printer->text('Fecha: ' . $ord['fechaImpresion'] . "\n");
        }

        $printer->text(str_repeat('=', $W) . "\n");

        /* Datos del split */
        $printer->setEmphasis(true);
        $printer->setTextSize(1, 2);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($name . "\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Persona: " . $idx . " de " . $totalSplits . "\n");

        $printer->text(str_repeat('-', $W) . "\n");

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("$" . number_format($personalTotal, 2) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        if ($isFinal && $method !== '') {
            $printer->text("Método: " . $method . "\n");
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $W) . "\n");

        /* Comparativa: consumo + propina */
        $this->printTwoColumnLine(
            $printer,
            "Tu consumo:",
            $this->formatMoney($amount),
            $W
        );
        if ($tipAmount > 0) {
            $this->printTwoColumnLine(
                $printer,
                "Tu propina:",
                $this->formatMoney($tipAmount),
                $W
            );
        }
        $this->printTwoColumnLine(
            $printer,
            "Tu total a pagar:",
            $this->formatMoney($personalTotal),
            $W
        );
        $printer->text(str_repeat('-', $W) . "\n");
        $this->printTwoColumnLine(
            $printer,
            "Total de la cuenta:",
            $this->formatMoney($orderTotal),
            $W
        );

        $printer->text(str_repeat('=', $W) . "\n");

        /* Footer */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if (!$isFinal) {
            $printer->setEmphasis(true);
            $printer->text("Este voucher es solo informativo.\n");
            $printer->text("Acércalo al cajero para pagar.\n");
            $printer->setEmphasis(false);
        } else {
            $printer->setEmphasis(true);
            $printer->text("¡Gracias por tu visita!\n");
            $printer->setEmphasis(false);
        }
        $printer->feed(1);
        $printer->text("POS GROWTHSUITE\n");

        $printer->feed(3);
        $printer->cut();
    }
}

 // Descomentar la siguiente línea si tu impresora soporta impresión directa de QR
// $printer->qrCode($qrText, Printer::QR_ECLEVEL_L, $sizeQR);
//$printer->feed(1);
