<?php

class ToshibaACMQTTHelper
{
    public const CLIENT_SUFFIX = '3e6e4eb5f0e5aa46';

    public static function mobileDeviceId(string $username): string
    {
        return $username . '_' . self::CLIENT_SUFFIX;
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
                $bytes[1] = sprintf('%02x', (int)$value);
                break;
            case 'TOSH_SetTemp':
                $bytes[2] = sprintf('%02x', max(5, min(30, (int)$value)));
                break;
            case 'TOSH_FanSpeed':
                $bytes[3] = sprintf('%02x', (int)$value);
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
