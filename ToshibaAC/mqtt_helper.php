<?php

class ToshibaACMQTTHelper
{
    public const CLIENT_SUFFIX = '3e6e4eb5f0e5aa46';

    public static function mobileDeviceId(string $username): string
    {
        return $username . '_' . self::CLIENT_SUFFIX;
    }

    public static function mapModeToRaw($value): int
    {
        $v = (int)$value;
        $map = [0 => 0x41, 1 => 0x42, 2 => 0x43, 3 => 0x44, 4 => 0x45];
        return $map[$v] ?? $v;
    }

    public static function mapFanToRaw($value): int
    {
        $v = (int)$value;
        $map = [0 => 0x41, 1 => 0x31, 2 => 0x32, 3 => 0x34, 4 => 0x36];
        return $map[$v] ?? $v;
    }

    public static function buildState(string $currentHex, string $ident, $value): string
    {
        $bytes = str_split($currentHex, 2);
        if (count($bytes) < 19) {
            return '';
        }

        switch ($ident) {
            case 'TOSH_Power':
                $bytes[0] = $value ? '30' : '31';
                break;

            case 'TOSH_Mode':
                $mode = self::mapModeToRaw($value);
                $bytes[1] = sprintf('%02x', $mode);

                // App-Verhalten nachgebildet: Cool 23 Auto -> Dry 22 Auto setzt zusätzlich Temp/Fan/Airflow.
                if ($mode === 0x44) {
                    $bytes[2] = '16'; // 22 °C
                    $bytes[3] = '41'; // Fan Auto
                    $bytes[9] = '0d'; // Airflow/Lamelle Default bei Dry
                }
                break;

            case 'TOSH_SetTemp':
                $bytes[2] = sprintf('%02x', max(5, min(30, (int)$value)));
                break;

            case 'TOSH_FanSpeed':
                $bytes[3] = sprintf('%02x', self::mapFanToRaw($value));
                break;

            case 'TOSH_EcoMode':
                $bytes[5] = $value ? '03' : '00';
                break;

            case 'TOSH_SilentMode':
                $bytes[3] = $value ? '31' : '41';
                break;

            default:
                return '';
        }

        return implode('', $bytes);
    }

    public static function commandPayload(string $sourceId, string $acUniqueId, string $stateHex): string
    {
        return json_encode([
            'sourceId' => $sourceId,
            'messageId' => '0000000',
            'targetId' => [$acUniqueId],
            'cmd' => 'CMD_FCU_TO_AC',
            'payload' => ['data' => $stateHex],
            'timeStamp' => '0000000'
        ], JSON_UNESCAPED_SLASHES);
    }
}
