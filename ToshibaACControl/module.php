<?php

class ToshibaACControl extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');

        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('SetTemperature', 'Soll-Temperatur', '~Temperature.Room', 20);
        $this->EnableAction('SetTemperature');

        $this->RegisterVariableFloat('RoomTemperature', 'Ist-Temperatur', '~Temperature.Room', 30);

        $this->RegisterVariableInteger('Mode', 'Modus', 'TAC.Mode', 40);
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('FanSpeed', 'Lüfterstufe', 'TAC.FanSpeed', 50);
        $this->EnableAction('FanSpeed');

        $this->RegisterVariableBoolean('Swing', 'Swing', '~Switch', 60);
        $this->EnableAction('Swing');
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

        if (!IPS_VariableProfileExists('TAC.FanSpeed')) {
            IPS_CreateVariableProfile('TAC.FanSpeed', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('TAC.FanSpeed', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('TAC.FanSpeed', 1, 'Low', '', -1);
            IPS_SetVariableProfileAssociation('TAC.FanSpeed', 2, 'Med', '', -1);
            IPS_SetVariableProfileAssociation('TAC.FanSpeed', 3, 'High', '', -1);
            IPS_SetVariableProfileAssociation('TAC.FanSpeed', 4, 'Powerful', '', -1);
            IPS_SetVariableProfileAssociation('TAC.FanSpeed', 5, 'Quiet', '', -1);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
            case 'SetTemperature':
            case 'Mode':
            case 'FanSpeed':
            case 'Swing':
                SetValue($this->GetIDForIdent($Ident), $Value);
                $this->SendCommand();
                break;
        }
    }

    private function SendCommand()
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        $token = $this->Authenticate($username, $password);

        if (!$token) {
            $this->SendDebug(__FUNCTION__, 'Authentifizierung fehlgeschlagen', 0);
            return;
        }

        $payload = [
            'power'        => GetValueBoolean($this->GetIDForIdent('Power')) ? 'on' : 'off',
            'mode'         => GetValueInteger($this->GetIDForIdent('Mode')),
            'temperature'  => GetValueFloat($this->GetIDForIdent('SetTemperature')),
            'fanspeed'     => GetValueInteger($this->GetIDForIdent('FanSpeed')),
            'airSwingLR'   => GetValueBoolean($this->GetIDForIdent('Swing')) ? 'auto' : 'stop',
        ];

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ],
                'content' => json_encode($payload)
            ]
        ];

        $context = stream_context_create($options);
        $url = "https://mobileapi.toshibahomeaccontrols.com/devices/$deviceID/control";

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->SendDebug(__FUNCTION__, 'Senden fehlgeschlagen', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Befehl erfolgreich gesendet', 0);
        }
    }

    private function Authenticate($username, $password)
    {
        $payload = json_encode([
            'username' => $username,
            'password' => $password
        ]);

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json',
                'content' => $payload
            ]
        ];

        $context = stream_context_create($options);
        $url = 'https://mobileapi.toshibahomeaccontrols.com/users/auth/login';
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return $data['token'] ?? false;
    }
}
