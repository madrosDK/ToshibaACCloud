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

      // Standard-Variablen
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

      // hier könnte man z.B. prüfen, ob alles korrekt initialisiert wurde oder zyklische Ereignisse setzen
      $this->SendDebug(__FUNCTION__, 'ApplyChanges() aufgerufen', 0);
  }


  public function RequestAction($Ident, $Value)
  {
      switch ($Ident) {
          case 'DiscoverDevices':
              $this->DiscoverDevices();
              return;

          default:
              // Standard‑Variablen bedienen
              SetValue($this->GetIDForIdent($Ident), $Value);
              $this->SendCommand();
              return;
      }
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
    public function GetStatus()
    {
        if (!$this->EnsureLoginAndACId()) {
            $this->SendDebug(__FUNCTION__, 'Login oder AC‑ID fehlgeschlagen', 0);
            return;
        }

        $accessToken = $this->GetBuffer('AccessToken');
        $acId        = $this->GetBuffer('ACId');

        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?ACId=' . urlencode($acId);

        $result = $this->QueryAPI($url, null, $accessToken);

        if (!$result) {
            $this->SendDebug(__FUNCTION__, 'Keine Daten erhalten', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, print_r($result, true), 0);

        if (!empty($result['ResObj'])) {
            $state = $result['ResObj'];

            // bekannte Keys → bekannte Variablen

            // Power → aus dstStatus (ON/OFF)
            if (isset($state['dstStatus'])) {
                SetValueBoolean(
                    $this->GetIDForIdent('TOSH_Power'),
                    ($state['dstStatus'] === 'ON')
                );
            }

            // Mode → aus OpeMode (könnte NULL sein)
            if (!empty($state['OpeMode'])) {
                SetValueInteger(
                    $this->GetIDForIdent('TOSH_Mode'),
                    (int) $state['OpeMode']
                );
            }

            // SetTemp / RoomTemp / FanSpeed / Swing → unbekannt (Platzhalter)
            if (!empty($state['ACStateDataForProgram'])) {
                $this->SendDebug(
                    __FUNCTION__,
                    'ACStateDataForProgram (hex, noch nicht dekodiert): ' . $state['ACStateDataForProgram'],
                    0
                );

                // ➝ hier müsstest du das Hex dekodieren, z.B.:
                // $decoded = $this->DecodeACStateData($state['ACStateDataForProgram']);

                // bis dahin setzen wir Platzhalter:
                SetValueFloat($this->GetIDForIdent('TOSH_SetTemp'), 0);
                SetValueFloat($this->GetIDForIdent('TOSH_RoomTemp'), 0);
                SetValueInteger($this->GetIDForIdent('TOSH_FanSpeed'), 0);
                SetValueBoolean($this->GetIDForIdent('TOSH_Swing'), false);
            }

            // für Debug & Vollständigkeit
            if (!empty($state['ACName'])) {
                $this->SendDebug(__FUNCTION__, 'ACName: ' . $state['ACName'], 0);
            }

            if (!empty($state['ACModel'])) {
                $this->SendDebug(__FUNCTION__, 'ACModel: ' . $state['ACModel'], 0);
            }

            if (!empty($state['SchedulerStatus'])) {
                $this->SendDebug(__FUNCTION__, 'SchedulerStatus: ' . $state['SchedulerStatus'], 0);
            }

            if (!empty($state['programSetting'])) {
                $json = json_encode($state['programSetting'], JSON_PRETTY_PRINT);
                $this->SendDebug(__FUNCTION__, 'ProgramSetting: ' . $json, 0);
            }

            if (!empty($state['dst'])) {
                $this->SendDebug(__FUNCTION__, 'DST: ' . print_r($state['dst'], true), 0);
            }

        } else {
            $this->SendDebug(__FUNCTION__, 'ResObj leer oder nicht vorhanden', 0);
        }
    }


public function GetSettings()
{
    if (!$this->EnsureLoginAndACId()) {
        $this->SendDebug(__FUNCTION__, 'Login oder AC‑ID fehlgeschlagen', 0);
        return;
    }

    $accessToken = $this->GetBuffer('AccessToken');
    $consumerId  = $this->GetBuffer('ConsumerId');

    $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerProgramSettings?consumerId=' . urlencode($consumerId);

    $result = $this->QueryAPI($url, null, $accessToken);

    if (!$result) {
        $this->SendDebug(__FUNCTION__, 'Keine Daten erhalten', 0);
        return;
    }

    $this->SendDebug(__FUNCTION__, print_r($result, true), 0);

    // Einstellungen werden hier nur geloggt — du kannst sie nach Wunsch weiter verarbeiten
}

public function DiscoverDevices()
{
    $username = $this->ReadPropertyString('Username');
    $password = $this->ReadPropertyString('Password');

    if ($username == '' || $password == '') {
        echo "Benutzername oder Passwort fehlt.";
        return;
    }

    $accessToken = $this->Login($username, $password);
    if (!$accessToken) {
        echo "Login fehlgeschlagen.";
        return;
    }

    $consumerId = $this->GetBuffer('ConsumerId');
    if (!$consumerId) {
        echo "ConsumerId nicht gefunden.";
        return;
    }

    $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId);

    $result = $this->QueryAPI($url, null, $accessToken);

    if (!$result || empty($result['ResObj'])) {
        echo "Keine Geräte gefunden.";
        return;
    }

    $devices = [];
    foreach ($result['ResObj'] as $entry) {
        if (!empty($entry['ACList'])) {
            foreach ($entry['ACList'] as $ac) {
                $devices[] = [
                    'name' => $ac['Name'] ?? 'Unbekannt',
                    'id'   => $ac['Id'] ?? 'unbekannt'
                ];
            }
        }
    }

    if (empty($devices)) {
        echo "Keine Geräte gefunden.";
        return;
    }

    // Anzeige im Output
    echo "Gefundene Geräte:\n";
    foreach ($devices as $device) {
        echo "📋 Name: {$device['name']} | ID: {$device['id']}\n";
    }

    // Buffer speichern, damit GetConfigurationForm() sie anzeigen kann
    $this->SetBuffer('DiscoveredDevices', json_encode($devices));
}


public function GetConfigurationForm()
{
    $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

    $devices = json_decode($this->GetBuffer('DiscoveredDevices'), true) ?: [];

    $options = [
        [
            'caption' => 'Bitte Gerät auswählen …',
            'value'   => ''
        ]
    ];

    foreach ($devices as $device) {
        $options[] = [
            'caption' => "{$device['name']} ({$device['id']})",
            'value'   => $device['id']
        ];
    }

    foreach ($form['elements'] as &$element) {
        if ($element['name'] == 'DeviceID') {
            $element['options'] = $options;
        }
    }

    return json_encode($form);
}


}
