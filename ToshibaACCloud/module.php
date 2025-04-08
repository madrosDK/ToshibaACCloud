<?php

class ToshibaACCloudControl extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');

        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('SetTemp', 'Soll-Temperatur', '~Temperature.Room', 20);
        $this->EnableAction('SetTemp');

        $this->RegisterVariableFloat('RoomTemp', 'Ist-Temperatur', '~Temperature.Room', 30);

        $this->RegisterVariableInteger('Mode', 'Modus', 'TAC.Mode', 40);
        $this->EnableAction('Mode');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (!IPS_VariableProfileExists('TAC.Mode')) {
            IPS_CreateVariableProfile('TAC.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('TAC.Mode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('TAC.Mode', 1, 'Cool', '', -1);
            IPS_SetVariableProfileAssociation('TAC.Mode', 2, 'Dry', '', -1);
            IPS_SetVariableProfileAssociation('TAC.Mode', 3, 'Heat', '', -1);
            IPS_SetVariableProfileAssociation('TAC.Mode', 4, 'Fan', '', -1);
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
            'Power' => GetValueBoolean($this->GetIDForIdent('Power')) ? 1 : 0,
            'OperationMode' => GetValueInteger($this->GetIDForIdent('Mode')),
            'TargetTemperature' => GetValueFloat($this->GetIDForIdent('SetTemp'))
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
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json" . ($token ? "\r\nAuthorization: Bearer $token" : ''),
                'content' => $postData
            ]
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        return $result ? json_decode($result, true) : false;
    }
}
