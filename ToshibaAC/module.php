<?php

class ToshibaAC extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');

        // Profile anlegen
        if (!IPS_VariableProfileExists('TOSH.Mode')) {
            IPS_CreateVariableProfile('TOSH.Mode', 1);
            IPS_SetVariableProfileIcon('TOSH.Mode', 'Temperature');
            IPS_SetVariableProfileAssociation('TOSH.Mode', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 1, 'Cool', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 2, 'Dry', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 3, 'Heat', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.Mode', 4, 'Fan', '', -1);
        }

        if (!IPS_VariableProfileExists('TOSH.FanSpeed')) {
            IPS_CreateVariableProfile('TOSH.FanSpeed', 1);
            IPS_SetVariableProfileIcon('TOSH.FanSpeed', 'WindSpeed');
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 0, 'Auto', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 1, 'Low', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 2, 'Med', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 3, 'High', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 4, 'Powerful', '', -1);
            IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 5, 'Quiet', '', -1);
        }

        // Variablen
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
    }

    public function RequestAction($Ident, $Value)
    {
        SetValue($this->GetIDForIdent($Ident), $Value);
        $this->SendCommand();
    }

    public function TestConnection()
    {
        if ($this->EnsureLoginAndACId()) {
            $acId = $this->GetBuffer('ACId');
            echo "Verbindung erfolgreich. AC‑ID: $acId";
        } else {
            echo "Verbindung fehlgeschlagen.";
        }
    }

    private function SendCommand()
    {
        if (!$this->EnsureLoginAndACId()) {
            $this->SendDebug(__FUNCTION__, 'Login oder AC‑ID fehlgeschlagen', 0);
            return;
        }

        $accessToken = $this->GetBuffer('AccessToken');
        $acId        = $this->GetBuffer('ACId');

        $payload = [
            'ACId' => $acId,
            'Power' => GetValueBoolean($this->GetIDForIdent('TOSH_Power')) ? 1 : 0,
            'OperationMode' => GetValueInteger($this->GetIDForIdent('TOSH_Mode')),
            'TargetTemperature' => GetValueFloat($this->GetIDForIdent('TOSH_SetTemp')),
            'AirSwingLR' => GetValueBoolean($this->GetIDForIdent('TOSH_Swing')) ? 'auto' : 'stop',
            'FanSpeed' => GetValueInteger($this->GetIDForIdent('TOSH_FanSpeed')),
        ];

        $data = json_encode($payload);

        $result = $this->QueryAPI(
            'https://mobileapi.toshibahomeaccontrols.com/api/AC/SetACState',
            $data,
            $accessToken
        );

        $this->SendDebug(__FUNCTION__, print_r($result, true), 0);
    }

    private function EnsureLoginAndACId()
    {
        $accessToken = $this->GetBuffer('AccessToken');
        $consumerId  = $this->GetBuffer('ConsumerId');
        $acId        = $this->GetBuffer('ACId');

        if (!empty($accessToken) && !empty($consumerId) && !empty($acId)) {
            $this->SendDebug(__FUNCTION__, 'Daten aus Buffer verwendet', 0);
            return true;
        }

        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        $accessToken = $this->Login($username, $password);
        if (!$accessToken) {
            $this->SendDebug(__FUNCTION__, 'Login fehlgeschlagen', 0);
            return false;
        }

        $consumerId = $this->GetBuffer('ConsumerId');
        $acId = $this->GetACId($accessToken, $consumerId);

        if (!$acId) {
            $this->SendDebug(__FUNCTION__, 'AC‑ID konnte nicht ermittelt werden', 0);
            return false;
        }

        $this->SetBuffer('ACId', $acId);

        return true;
    }

    private function Login($username, $password)
    {
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/Consumer/Login';

        $fields = json_encode([
            'Username' => $username,
            'Password' => $password
        ]);

        $result = $this->QueryAPI($url, $fields);

        if (!$result || empty($result['ResObj']['access_token'])) {
            $this->SendDebug(__FUNCTION__, 'Login fehlgeschlagen', 0);
            return false;
        }

        $accessToken = $result['ResObj']['access_token'];
        $consumerId  = $result['ResObj']['consumerId'];

        $this->SetBuffer('AccessToken', $accessToken);
        $this->SetBuffer('ConsumerId', $consumerId);

        $this->SendDebug(__FUNCTION__, 'Login erfolgreich', 0);

        return $accessToken;
    }

    private function GetACId($accessToken, $consumerId)
    {
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId);

        $result = $this->QueryAPI($url, null, $accessToken);

        if (!$result || empty($result['ResObj'][0]['ACList'][0]['Id'])) {
            $this->SendDebug(__FUNCTION__, 'Keine AC‑ID gefunden', 0);
            return false;
        }

        $acId = $result['ResObj'][0]['ACList'][0]['Id'];
        $this->SendDebug(__FUNCTION__, "Gefundene AC‑ID: $acId", 0);
        return $acId;
    }

    private function QueryAPI($url, $postData = null, $token = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        if (!empty($postData)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $headers = ['Content-Type: application/json'];
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        if (!empty($result)) {
            return json_decode($result, true);
        } else {
            $this->SendDebug(__FUNCTION__, "Fehler bei Query: $url", 0);
            return false;
        }
    }
}
