<?php

class ToshibaAC extends IPSModule
{
  public function Create()
  {
      parent::Create();
      $this->RegisterPropertyString('Username', '');
      $this->RegisterPropertyString('Password', '');
      $this->RegisterPropertyString('DeviceID', '');

      // Profile anlegen, falls noch nicht vorhanden
      if (!IPS_VariableProfileExists('TOSH.Mode')) {
          IPS_CreateVariableProfile('TOSH.Mode', 1); // 1 = Integer
          IPS_SetVariableProfileIcon('TOSH.Mode', 'Temperature');
          IPS_SetVariableProfileAssociation('TOSH.Mode', 0, 'Auto', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.Mode', 1, 'Cool', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.Mode', 2, 'Dry', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.Mode', 3, 'Heat', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.Mode', 4, 'Fan', '', -1);
      }

      if (!IPS_VariableProfileExists('TOSH.FanSpeed')) {
          IPS_CreateVariableProfile('TOSH.FanSpeed', 1); // 1 = Integer
          IPS_SetVariableProfileIcon('TOSH.FanSpeed', 'WindSpeed');
          IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 0, 'Auto', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 1, 'Low', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 2, 'Mid', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 3, 'High', '', -1);
          IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 4, 'Powerful', '', -1);
      }

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

    public function TestConnection()
    {
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        if ($username == '' || $password == '') {
            echo "Benutzername oder Passwort fehlt.";
            return;
        }

        $token = $this->Login($username, $password);
        if (!$token) {
            echo "Login fehlgeschlagen.";
            return;
        }

        $consumerId = $this->GetBuffer('ConsumerId');
        $acId = $this->GetACId($token, $consumerId);

        if ($acId) {
            echo "Verbindung erfolgreich. AC‑ID: $acId";
        } else {
            echo "Login ok, aber keine AC‑ID gefunden.";
        }
    }


    private function Login($username, $password)
    {
        $baseUrl = "https://mobileapi.toshibahomeaccontrols.com";
        $loginUrl = $baseUrl . "/api/Consumer/Login";

        $fields = json_encode([
            'Username' => $username,
            'Password' => $password
        ]);

        $result = $this->QueryAPI($loginUrl, $fields);

        if (!$result || empty($result['ResObj']['access_token'])) {
            $this->SendDebug(__FUNCTION__, 'Login fehlgeschlagen', 0);
            return false;
        }

        $accessToken = $result['ResObj']['access_token'];
        $consumerId = $result['ResObj']['consumerId'];

        // speichern für spätere API‑Aufrufe
        $this->SetBuffer('AccessToken', $accessToken);
        $this->SetBuffer('ConsumerId', $consumerId);

        return $accessToken;
    }

    private function GetACId($accessToken, $consumerId)
{
    $baseUrl = "https://mobileapi.toshibahomeaccontrols.com";
    $mappingUrl = $baseUrl . "/api/AC/GetConsumerACMapping?consumerId=" . urlencode($consumerId);

    $result = $this->QueryAPI($mappingUrl, null, $accessToken);

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
