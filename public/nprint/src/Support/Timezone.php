<?php

namespace App\Support;

/**
 * Detecta la zona horaria configurada en Windows (la misma que usa el reloj
 * del sistema) y la aplica como zona horaria por defecto de PHP.
 *
 * Esto es importante porque este servidor se instala en la PC de cada
 * restaurante de forma independiente. No podemos fijar una sola zona horaria
 * en el código: cada PC puede estar en un huso distinto (Ciudad de México,
 * Tijuana, Cancún, etc.), así que la tomamos del propio Windows en vez de
 * hardcodearla, sin necesidad de configurar nada por instalación.
 *
 * Si no se puede detectar (por ejemplo, corriendo en otro SO en desarrollo,
 * o si `tzutil` no está disponible), se usa $fallback.
 */
class Timezone
{
    /**
     * Mapeo de IDs de zona horaria de Windows -> IANA (subconjunto de la
     * tabla oficial de Unicode CLDR "windowsZones", con foco en México,
     * Latinoamérica y EE. UU.). Ampliar si algún restaurante cae fuera de
     * este listado.
     *
     * @see https://raw.githubusercontent.com/unicode-org/cldr/main/common/supplemental/windowsZones.xml
     */
    private const WINDOWS_TO_IANA = [
        'Pacific Standard Time (Mexico)' => 'America/Tijuana',
        'Mountain Standard Time (Mexico)' => 'America/Chihuahua',
        'Central Standard Time (Mexico)' => 'America/Mexico_City',
        'Eastern Standard Time (Mexico)' => 'America/Cancun',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Mountain Standard Time' => 'America/Denver',
        'US Mountain Standard Time' => 'America/Phoenix',
        'Central Standard Time' => 'America/Chicago',
        'Eastern Standard Time' => 'America/New_York',
        'SA Pacific Standard Time' => 'America/Bogota',
        'Pacific SA Standard Time' => 'America/Santiago',
        'Central America Standard Time' => 'America/Guatemala',
        'Venezuela Standard Time' => 'America/Caracas',
        'Argentina Standard Time' => 'America/Argentina/Buenos_Aires',
        'SA Eastern Standard Time' => 'America/Cayenne',
        'Paraguay Standard Time' => 'America/Asuncion',
    ];

    public static function applyFromSystem(string $fallback = 'America/Mexico_City'): string
    {
        $tz = self::detect() ?? $fallback;
        date_default_timezone_set($tz);
        return $tz;
    }

    private static function detect(): ?string
    {
        if (stripos(PHP_OS, 'WIN') !== 0) {
            return null;
        }
        if (!function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec('tzutil /g');
        if (!$output) {
            return null;
        }

        $windowsId = trim($output);
        return self::WINDOWS_TO_IANA[$windowsId] ?? null;
    }
}
