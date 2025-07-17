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


        // Verwende hier deine API-Klasse oder direkten cURL-Call
        $loginUrl = 'https://mobileapi.toshibahomeaccontrols.com/v1/user/auth/login';
        $accessToken = $this->Login($username, $password);

        if (!$accessToken) {
            echo "Login fehlgeschlagen.";
            return;
        }

        $loginData = json_encode([
            'username' => $username,
            'password' => $password
        ]);

        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200) {
            echo "Login fehlgeschlagen (HTTP-Code $httpCode)";
            return;
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            echo "Login fehlgeschlagen: kein Zugriffstoken erhalten.";
            return;
        }

        $accessToken = $data['access_token'];

        // Geräte abrufen
        $deviceUrl = 'https://mobileapi.toshibahomeaccontrols.com/v1/user/device';

        $ch = curl_init($deviceUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);

        $deviceResponse = curl_exec($ch);
        curl_close($ch);

        $deviceData = json_decode($deviceResponse, true);

        if (isset($deviceData[0]['deviceGuid'])) {
            echo "Verbindung erfolgreich. DeviceID: " . $deviceData[0]['deviceGuid'];
        } elseif (isset($deviceData['message'])) {
            echo "Fehler: " . $deviceData['message'];
        } else {
            echo "Keine Geräte gefunden oder unbekannter Fehler.";
        }
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
