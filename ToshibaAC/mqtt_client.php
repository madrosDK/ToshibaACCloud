<?php

class ToshibaACMQTTClient
{
    private function encodeString(string $value): string
    {
        return pack('n', strlen($value)) . $value;
    }

    private function encodeLength(int $length): string
    {
        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 0x80;
            }
            $encoded .= chr($digit);
        } while ($length > 0);
        return $encoded;
    }
}
