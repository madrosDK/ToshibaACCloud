<?php
require_once __DIR__ . '/mqtt_helper.php';

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
        $this->RegisterVariableFloat('TOSH_SetTemp', 'Soll-Temperatur', '~Temperature.Room', 20);
        $this->RegisterVariableFloat('TOSH_RoomTemp', 'Ist-Temperatur', '~Temperature.Room', 30);
        $this->RegisterVariableInteger('TOSH_Mode', 'Modus', 'TOSH.Mode', 40);
        $this->RegisterVariableInteger('TOSH_FanSpeed', 'Lüfterstufe', 'TOSH.FanSpeed', 50);
        $this->RegisterVariableBoolean('TOSH_Swing', 'Swing', '~Switch', 60);
        $this->RegisterVariableBoolean('TOSH_EcoMode', 'Eco-Modus', '~Switch', 65);
        $this->RegisterVariableBoolean('TOSH_SilentMode', 'Silent-Modus', '~Switch', 66);

        $this->RegisterVariableString('TOSH_WriteInfo', 'Schreiben', '', 67);
        SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'Schreiben erfolgt nicht per REST. Nächster Schritt: native PHP AMQP/Azure IoT Implementierung.');

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
                echo "❌ Schreiben ist aktuell deaktiviert. Toshiba nutzt dafür Azure IoT Hub/AMQP (CMD_FCU_TO_AC), nicht den bisherigen REST-Payload.\n";
                SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'Schreiben deaktiviert: AMQP/Azure IoT Implementierung erforderlich.');
                return false;
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

    public function TestMQTTPreparation()
    {
        if (!$this->EnsureLogin()) {
            echo "❌ Login fehlgeschlagen.\n";
            return false;
        }

        $accessToken = $this->GetBuffer('AccessToken');
        $username = $this->ReadPropertyString('Username');
        $deviceId = ToshibaACMQTTHelper::mobileDeviceId($username);

        $registerPayload = json_encode([
            'DeviceID' => $deviceId,
            'DeviceType' => '1',
            'Username' => $username
        ]);

        $result = $this->QueryAPI('https://mobileapi.toshibahomeaccontrols.com/api/Consumer/RegisterMobileDevice', $registerPayload, $accessToken);
        $this->DebugLog(__FUNCTION__, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (!$result || empty($result['ResObj']['SasToken'])) {
            echo "❌ SAS Token holen fehlgeschlagen.\n";
            return false;
        }

        $sas = $result['ResObj']['SasToken'];
        $current = GetValueString($this->GetIDForIdent('TOSH_ACStateData'));
        $newState = ToshibaACMQTTHelper::buildState($current, 'TOSH_SetTemp', 22);

        $acUniqueId = $this->GetBuffer('LastACUniqueId');
        if ($acUniqueId === '') {
            $devices = json_decode($this->GetBuffer('DiscoveredDevices'), true) ?: [];
            $selected = $this->ReadPropertyString('DeviceID');
            foreach ($devices as $device) {
                if (($device['id'] ?? '') === $selected) {
                    $acUniqueId = $device['uniqueId'] ?? '';
                    break;
                }
            }
        }

        $mqttPayload = ToshibaACMQTTHelper::commandPayload($deviceId, $acUniqueId, $newState);

        echo "✅ SAS Token OK\n";
        echo "DeviceID: " . $deviceId . "\n";
        echo "AC UniqueID: " . ($acUniqueId ?: '(leer)') . "\n";
        echo "Current: " . $current . "\n";
        echo "New:     " . $newState . "\n";
        echo "SAS Token: " . substr($sas, 0, 80) . "...\n";
        echo "\n📦 MQTT Payload:\n" . $mqttPayload . "\n";

        SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'MQTT vorbereitet OK');
        return true;
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
                foreach ($entry['ACList'] as $ac) { $devices[] = ['name' => $ac['Name'] ?? 'Unbekannt', 'id' => $ac['Id'] ?? '', 'uniqueId' => $ac['DeviceUniqueId'] ?? '']; }
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
            $this->SetBuffer('LastACUniqueId', $state['ACDeviceUniqueId'] ?? '');
            $decoded = $this->DecodeACStateData($hex);
            SetValueBoolean($this->GetIDForIdent('TOSH_Power'), $decoded['Power']);
            SetValueInteger($this->GetIDForIdent('TOSH_Mode'), $decoded['Mode']);
            SetValueFloat($this->GetIDForIdent('TOSH_SetTemp'), $decoded['SetTemp']);
            SetValueFloat($this->GetIDForIdent('TOSH_RoomTemp'), $decoded['RoomTemp']);
            SetValueInteger($this->GetIDForIdent('TOSH_FanSpeed'), $decoded['FanSpeed']);
            SetValueBoolean($this->GetIDForIdent('TOSH_Swing'), $decoded['Swing']);
            SetValueBoolean($this->GetIDForIdent('TOSH_EcoMode'), $decoded['EcoMode']);
            SetValueBoolean($this->GetIDForIdent('TOSH_SilentMode'), $decoded['SilentMode']);
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
        $feature = isset($bytes[5]) ? hexdec($bytes[5]) : 0;
        $fanMode = isset($bytes[3]) ? hexdec($bytes[3]) : 0;
        return [
            'Power' => isset($bytes[0]) ? (hexdec($bytes[0]) === 0x30) : false,
            'PowerRaw' => $bytes[0] ?? '',
            'Mode' => isset($bytes[1]) ? hexdec($bytes[1]) : 0,
            'ModeRaw' => $bytes[1] ?? '',
            'SetTemp' => isset($bytes[2]) ? hexdec($bytes[2]) : 0,
            'SetTempRaw' => $bytes[2] ?? '',
            'FanMode' => $fanMode,
            'FanModeRaw' => $bytes[3] ?? '',
            'Feature' => $feature,
            'FeatureRaw' => $bytes[5] ?? '',
            'EcoMode' => ($feature === 3),
            'SilentMode' => ($fanMode === 0x31 && $feature === 0),
            'FanSpeed' => isset($bytes[7]) ? hexdec($bytes[7]) : 0,
            'FanSpeedRaw' => $bytes[7] ?? '',
            'RoomTemp' => isset($bytes[8]) ? hexdec($bytes[8]) : 0,
            'RoomTempRaw' => $bytes[8] ?? '',
            'AirFlow' => isset($bytes[9]) ? hexdec($bytes[9]) : 0,
            'AirFlowRaw' => $bytes[9] ?? '',
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
