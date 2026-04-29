<?php

class ToshibaAC extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterTimer('UpdateTimer', 0, 'TOSH_GetStatus($_IPS["TARGET"]);');

        $this->RegisterVariableBoolean('TOSH_Power', 'Power', '~Switch', 10);
        $this->EnableAction('TOSH_Power');
        $this->RegisterVariableFloat('TOSH_SetTemp', 'Soll-Temperatur', '~Temperature.Room', 20);
        $this->EnableAction('TOSH_SetTemp');
        $this->RegisterVariableFloat('TOSH_RoomTemp', 'Ist-Temperatur', '~Temperature.Room', 30);
        $this->RegisterVariableInteger('TOSH_Mode', 'Modus', 'TOSH.Mode', 40);
        $this->EnableAction('TOSH_Mode');
        $this->RegisterVariableInteger('TOSH_FanSpeed', 'Lüfterstufe', 'TOSH.FanSpeed', 50);
        $this->EnableAction('TOSH_FanSpeed');
        $this->RegisterVariableBoolean('TOSH_Swing', 'Swing', '~Switch', 60);
        $this->EnableAction('TOSH_Swing');

        $this->RegisterVariableString('TOSH_Firmware', 'Firmware', '', 70);
        $this->RegisterVariableString('TOSH_LastUpdate', 'Letztes Update', '', 80);
        $this->RegisterVariableInteger('TOSH_Model', 'Modell', '', 90);
        $this->RegisterVariableString('TOSH_MeritFeature', 'MeritFeature', '', 100);
        $this->RegisterVariableString('TOSH_ACStateData', 'ACStateData', '', 110);
        $this->RegisterVariableString('TOSH_ACStateBytes', 'ACStateData Bytes', '', 120);
        $this->RegisterVariableString('TOSH_DecodedState', 'Dekodierter Status', '', 130);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'DiscoverDevices':
                return $this->DiscoverDevices();
            default:
                SetValue($this->GetIDForIdent($Ident), $Value);
                $this->SendCommand();
                return true;
        }
    }

    public function TestConnection()
    {
        if ($this->EnsureLogin()) {
            $acId = $this->ReadPropertyString('DeviceID');
            echo 'Verbindung erfolgreich. AC-ID: ' . ($acId ?: '(kein Gerät gewählt)');
        } else {
            echo 'Verbindung fehlgeschlagen.';
        }
    }

    public function DiscoverDevices()
    {
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');
        if ($username === '' || $password === '') { echo "❌ Benutzername oder Passwort fehlt.\n"; return false; }
        $accessToken = $this->Login($username, $password);
        if (!$accessToken) { echo "❌ Login fehlgeschlagen.\n"; return false; }
        $consumerId = $this->GetBuffer('ConsumerId');
        if (!$consumerId) { echo "❌ ConsumerId nicht gefunden.\n"; return false; }
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId);
        $result = $this->QueryAPI($url, null, $accessToken);
        $this->DebugLog(__FUNCTION__, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (!$result || empty($result['ResObj'])) { echo "❌ Keine Geräte gefunden.\n"; return false; }
        $devices = [];
        foreach ($result['ResObj'] as $entry) {
            if (!empty($entry['ACList'])) {
                foreach ($entry['ACList'] as $ac) { $devices[] = ['name' => $ac['Name'] ?? 'Unbekannt', 'id' => $ac['Id'] ?? '']; }
            }
        }
        if (empty($devices)) { echo "❌ Keine Geräte gefunden (leere ACList).\n"; return false; }
        $this->SetBuffer('DiscoveredDevices', json_encode($devices));
        $this->ReloadForm();
        echo "✅ Gefundene Geräte:\n";
        foreach ($devices as $device) { echo "📋 Name: {$device['name']} | ID: {$device['id']}\n"; }
        echo "\nFalls die Auswahl nicht sofort sichtbar ist, Konfigurationsfenster einmal schließen und neu öffnen.\n";
        return true;
    }

    public function GetStatus()
    {
        if (!$this->EnsureLogin()) { $this->DebugLog(__FUNCTION__, 'Login fehlgeschlagen'); echo "❌ Login fehlgeschlagen.\n"; return; }
        $accessToken = $this->GetBuffer('AccessToken');
        $acId = $this->ReadPropertyString('DeviceID');
        if ($acId === '') { echo "❌ Keine DeviceID gewählt.\n"; return; }
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?ACId=' . urlencode($acId);
        $result = $this->QueryAPI($url, null, $accessToken);
        $this->DebugLog(__FUNCTION__, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (!$result || empty($result['ResObj'])) { echo "❌ Keine Statusdaten erhalten.\n"; return; }
        $state = $result['ResObj'];
        if (!empty($state['ACStateData'])) {
            $hex = $state['ACStateData'];
            $decoded = $this->DecodeACStateData($hex);
            SetValueBoolean($this->GetIDForIdent('TOSH_Power'), $decoded['Power']);
            SetValueInteger($this->GetIDForIdent('TOSH_Mode'), $decoded['Mode']);
            SetValueFloat($this->GetIDForIdent('TOSH_SetTemp'), $decoded['SetTemp']);
            SetValueFloat($this->GetIDForIdent('TOSH_RoomTemp'), $decoded['RoomTemp']);
            SetValueInteger($this->GetIDForIdent('TOSH_FanSpeed'), $decoded['FanSpeed']);
            SetValueBoolean($this->GetIDForIdent('TOSH_Swing'), $decoded['Swing']);
            SetValueString($this->GetIDForIdent('TOSH_ACStateData'), $hex);
            SetValueString($this->GetIDForIdent('TOSH_ACStateBytes'), $this->FormatACStateBytes($hex));
            SetValueString($this->GetIDForIdent('TOSH_DecodedState'), json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        SetValueString($this->GetIDForIdent('TOSH_Firmware'), $state['FirmwareVersion'] ?? '');
        SetValueString($this->GetIDForIdent('TOSH_LastUpdate'), $state['UpdatedDate'] ?? '');
        SetValueInteger($this->GetIDForIdent('TOSH_Model'), (int)($state['Model'] ?? 0));
        SetValueString($this->GetIDForIdent('TOSH_MeritFeature'), $state['MeritFeature'] ?? '');
        echo "✅ Status aktualisiert.\n";
    }

    public function GetSettings()
    {
        if (!$this->EnsureLogin()) { $this->DebugLog(__FUNCTION__, 'Login fehlgeschlagen'); return; }
        $accessToken = $this->GetBuffer('AccessToken');
        $consumerId = $this->GetBuffer('ConsumerId');
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerProgramSettings?consumerId=' . urlencode($consumerId);
        $result = $this->QueryAPI($url, null, $accessToken);
        $this->DebugLog(__FUNCTION__, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function DebugDump()
    {
        if (!$this->EnsureLogin()) { echo "❌ Login fehlgeschlagen.\n"; return; }
        $accessToken = $this->GetBuffer('AccessToken');
        $consumerId = $this->GetBuffer('ConsumerId');
        $acId = $this->ReadPropertyString('DeviceID');
        echo 'Gewählte DeviceID/ACId: ' . ($acId ?: '(leer)') . "\n";
        echo 'ConsumerId: ' . ($consumerId ?: '(leer)') . "\n";
        $endpoints = [
            'CurrentACState_ACId' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?ACId=' . urlencode($acId),
            'CurrentACState_acId' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?acId=' . urlencode($acId),
            'CurrentACState_Id' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?Id=' . urlencode($acId),
            'ConsumerACMapping' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId),
            'ConsumerProgramSettings' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerProgramSettings?consumerId=' . urlencode($consumerId),
        ];
        foreach ($endpoints as $name => $url) {
            $result = $this->QueryAPI($url, null, $accessToken);
            echo "\n==============================\n" . $name . "\nURL: " . $url . "\n==============================\n";
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            $this->DebugLog($name, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $devices = json_decode($this->GetBuffer('DiscoveredDevices'), true) ?: [];
        $options = [['caption' => 'Bitte Gerät auswählen …', 'value' => '']];
        foreach ($devices as $device) { $options[] = ['caption' => "{$device['name']} ({$device['id']})", 'value' => $device['id']]; }
        foreach ($form['elements'] as &$element) { if (($element['name'] ?? '') === 'DeviceID') { $element['options'] = $options; } }
        return json_encode($form);
    }

    private function SendCommand()
    {
        if (!$this->EnsureLogin()) { $this->DebugLog(__FUNCTION__, 'Login fehlgeschlagen'); return; }
        $accessToken = $this->GetBuffer('AccessToken');
        $acId = $this->ReadPropertyString('DeviceID');
        if ($acId === '') { $this->DebugLog(__FUNCTION__, 'Keine DeviceID gewählt.'); echo "❌ Keine DeviceID gewählt.\n"; return; }
        $payload = [
            'ACId' => $acId,
            'Power' => GetValueBoolean($this->GetIDForIdent('TOSH_Power')) ? 1 : 0,
            'OperationMode' => GetValueInteger($this->GetIDForIdent('TOSH_Mode')),
            'TargetTemperature' => GetValueFloat($this->GetIDForIdent('TOSH_SetTemp')),
            'AirSwingLR' => GetValueBoolean($this->GetIDForIdent('TOSH_Swing')) ? 'auto' : 'stop',
            'FanSpeed' => GetValueInteger($this->GetIDForIdent('TOSH_FanSpeed')),
        ];
        $result = $this->QueryAPI('https://mobileapi.toshibahomeaccontrols.com/api/AC/SetACState', json_encode($payload), $accessToken);
        $this->DebugLog(__FUNCTION__, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function EnsureLogin()
    {
        $accessToken = $this->GetBuffer('AccessToken');
        $consumerId = $this->GetBuffer('ConsumerId');
        if (!empty($accessToken) && !empty($consumerId)) { return true; }
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');
        if ($username === '' || $password === '') { return false; }
        return (bool)$this->Login($username, $password);
    }

    private function Login($username, $password)
    {
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/Consumer/Login';
        $payload = json_encode(['Username' => $username, 'Password' => $password]);
        $result = $this->QueryAPI($url, $payload);
        if (!$result || empty($result['ResObj']['access_token']) || empty($result['ResObj']['consumerId'])) { $this->DebugLog(__FUNCTION__, 'Login fehlgeschlagen oder unvollständig'); return false; }
        $this->SetBuffer('AccessToken', $result['ResObj']['access_token']);
        $this->SetBuffer('ConsumerId', $result['ResObj']['consumerId']);
        $this->DebugLog(__FUNCTION__, 'Login erfolgreich');
        return $result['ResObj']['access_token'];
    }

    private function QueryAPI($url, $postData = null, $token = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($postData !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); }
        $headers = ['Content-Type: application/json'];
        if (!empty($token)) { $headers[] = 'Authorization: Bearer ' . $token; }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $result === '') { $this->DebugLog(__FUNCTION__, 'Fehler bei Query: ' . $url . ' / ' . $error); return false; }
        return json_decode($result, true);
    }

    private function DecodeACStateData(string $hex)
    {
        $bytes = str_split($hex, 2);
        return [
            'Power' => isset($bytes[0]) ? (hexdec($bytes[0]) === 0x30) : false,
            'PowerRaw' => $bytes[0] ?? '',
            'Mode' => isset($bytes[1]) ? hexdec($bytes[1]) : 0,
            'ModeRaw' => $bytes[1] ?? '',
            'SetTemp' => isset($bytes[2]) ? hexdec($bytes[2]) : 0,
            'SetTempRaw' => $bytes[2] ?? '',
            'FanSpeed' => isset($bytes[7]) ? hexdec($bytes[7]) : 0,
            'FanSpeedRaw' => $bytes[7] ?? '',
            'RoomTemp' => isset($bytes[8]) ? hexdec($bytes[8]) : 0,
            'RoomTempRaw' => $bytes[8] ?? '',
            'Swing' => isset($bytes[10]) ? (hexdec($bytes[10]) > 0) : false,
            'SwingRaw' => $bytes[10] ?? '',
            'ByteCount' => count($bytes),
            'Bytes' => $bytes
        ];
    }

    private function FormatACStateBytes(string $hex)
    {
        $bytes = str_split($hex, 2);
        $out = [];
        foreach ($bytes as $i => $byte) { $out[] = sprintf('%02d:%s(%d)', $i + 1, $byte, hexdec($byte)); }
        return implode(' ', $out);
    }

    private function DebugLog(string $message, string $data = '')
    {
        if ($this->ReadPropertyBoolean('Debug')) { $this->SendDebug($message, $data, 0); }
    }
}
