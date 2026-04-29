<?php

class ToshibaACMQTTClient
{
    private $socket;

    public function connect(string $host, string $clientId, string $username, string $password, int $port = 8883): bool
    {
        $this->socket = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            throw new Exception('MQTT connect failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($this->socket, 10);

        $payload =
            chr(0) . chr(4) . 'MQTT' .
            chr(4) .
            chr(0xC2) .
            chr(0) . chr(60) .
            $this->encodeString($clientId) .
            $this->encodeString($username) .
            $this->encodeString($password);

        $packet = chr(0x10) . $this->encodeLength(strlen($payload)) . $payload;

        fwrite($this->socket, $packet);

        $response = fread($this->socket, 4);

        if (strlen($response) < 4 || ord($response[0]) !== 0x20 || ord($response[3]) !== 0) {
            throw new Exception('MQTT CONNACK failed: ' . bin2hex($response));
        }

        return true;
    }

    public function publish(string $topic, string $message): bool
    {
        $payload = $this->encodeString($topic) . $message;
        $packet = chr(0x30) . $this->encodeLength(strlen($payload)) . $payload;

        fwrite($this->socket, $packet);

        return true;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fwrite($this->socket, chr(0xE0) . chr(0));
            fclose($this->socket);
        }
    }

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
