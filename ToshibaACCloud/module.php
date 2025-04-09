<?php

class TOSH_ACControl extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');

        // Variablen mit Prefix TOSH
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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (!IPS_VariableProfileExists('TOSH.Mode')) {
            IPS_CreateVariableProfile('TOSH.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 1, 'Cool', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 2, 'Dry', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 3, 'Heat', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 4, 'Fan', '', -1);
        }

        if (!IPS_VariableProfileExists('TOSH.FanSpeed')) {
            IPS_CreateVariableProfile('TOSH.FanSpeed', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 1, 'Low', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 2, 'Med', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 3, 'High', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 4, 'Powerful', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 5, 'Quiet', '', -1);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        SetValue($this->GetIDForIdent($Ident), $Value);
        $this->SendCommand();
    }

    private function SendCommand()
    {
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');
        $deviceId = $this->ReadPropertyString('DeviceID');

        $token = $this->Login($username, $password);
        if (!$token) {
            $this->SendDebug(__FUNCTION__, 'Login fehlgeschlagen', 0);
            return;
        }

        $payload = [
            'ACId' => $deviceId,
            'Power' => GetValueBoolean($this->GetIDForIdent('TOSH_Power')) ? 1 : 0,
            'OperationMode' => GetValueInteger($this->GetIDForIdent('TOSH_Mode')),
            'TargetTemperature' => GetValueFloat($this->GetIDForIdent('TOSH_SetTemp')),
            'AirSwingLR' => GetValueBoolean($this->GetIDForIdent('TOSH_Swing')) ? 'auto' : 'stop',
            'FanSpeed' => GetValueInteger($this->GetIDForIdent('TOSH_FanSpeed')),
        ];

        $data = json_encode($payload);
        $result = $this->QueryAPI('/api/AC/SetACState', $data, $token);
        $this->SendDebug(__FUNCTION__, print_r($result, true), 0);
    }

    private function Login($username, $password)
    {
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/Consumer/Login';
        $payload = json_encode([
            'Username' => $username,
            'Password' => $password
        ]);
        $response = $this->QueryAPI($url, $payload);
        return $response['ResObj']['access_token'] ?? false;
    }

    private function QueryAPI($url, $postData = null, $token = null)
    {
        if (strpos($url, 'http') !== 0) {
            $url = 'https://mobileapi.toshibahomeaccontrols.com' . $url;
        }

        $headers = ["Content-Type: application/json"];
        if ($token) {
            $headers[] = "Authorization: Bearer $token";
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $postData
            ]
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        return $result ? json_decode($result, true) : false;
    }
}
