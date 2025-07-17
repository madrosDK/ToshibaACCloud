<?php

class ToshibaAC extends IPSModule
{
  public function Create()
  {
      parent::Create();

      $this->RegisterPropertyString('Username', '');
      $this->RegisterPropertyString('Password', '');
      $this->RegisterPropertyString('DeviceID', '');

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
      switch ($Ident) {
          case 'DiscoverDevices':
              return $this->DiscoverDevices();  // <-- der Rückgabewert von DiscoverDevices()

          default:
              SetValue($this->GetIDForIdent($Ident), $Value);
              $this->SendCommand();
              return true;
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
        $payload = json_encode([
            'Username' => $username,
            'Password' => $password
        ]);

        $result = $this->QueryAPI($url, $payload);

        if (!$result || empty($result['ResObj']['access_token']) || empty($result['ResObj']['consumerId'])) {
            $this->SendDebug(__FUNCTION__, 'Login fehlgeschlagen oder unvollständig', 0);
            return false;
        }

        $accessToken = $result['ResObj']['access_token'];
        $consumerId  = $result['ResObj']['consumerId'];

        $this->SetBuffer('AccessToken', $accessToken);
        $this->SetBuffer('ConsumerId', $consumerId);

        $this->SendDebug(__FUNCTION__, "Login erfolgreich. AccessToken & ConsumerId gesetzt.", 0);

        return $accessToken;
    }

    private function GetACId($accessToken, $consumerId)
    {
        // Prüfe: hat der Benutzer bereits eine DeviceID in der Instanz gespeichert?
        $deviceIdProp = $this->ReadPropertyString('DeviceID');
        if (!empty($deviceIdProp)) {
            $this->SendDebug(__FUNCTION__, "Verwende gespeicherte DeviceID aus Property: $deviceIdProp", 0);
            return $deviceIdProp;
        }

        // Falls nicht: hole Liste aller Geräte & nimm den ersten
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId);

        $result = $this->QueryAPI($url, null, $accessToken);

        if (!$result || empty($result['ResObj'])) {
            $this->SendDebug(__FUNCTION__, 'Keine Geräte gefunden', 0);
            return false;
        }

        foreach ($result['ResObj'] as $entry) {
            if (!empty($entry['ACList'])) {
                foreach ($entry['ACList'] as $ac) {
                    if (!empty($ac['Id'])) {
                        $acId = $ac['Id'];
                        $this->SendDebug(__FUNCTION__, "Erste gefundene AC‑ID: $acId", 0);

                        // Optional: auch in Property schreiben
                        // IPS_SetProperty($this->InstanceID, 'DeviceID', $acId);
                        // IPS_ApplyChanges($this->InstanceID);

                        return $acId;
                    }
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'Keine AC‑ID gefunden', 0);
        return false;
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

    if ($username === '' || $password === '') {
        return "❌ Benutzername oder Passwort fehlt.";
    }

    $accessToken = $this->Login($username, $password);
    if (!$accessToken) {
        return "❌ Login fehlgeschlagen.";
    }

    $consumerId = $this->GetBuffer('ConsumerId');
    if (!$consumerId) {
        return "❌ ConsumerId nicht gefunden.";
    }

    $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId);

    $result = $this->QueryAPI($url, null, $accessToken);

    if (!$result) {
        return "❌ Keine Antwort von API.";
    }

    if (empty($result['ResObj'])) {
        return "❌ ResObj leer. Antwort: " . json_encode($result);
    }

    $output = "✅ Gefundene Geräte:<br>";

    foreach ($result['ResObj'] as $entry) {
        if (!empty($entry['ACList'])) {
            foreach ($entry['ACList'] as $ac) {
                $name = $ac['Name'] ?? 'Unbekannt';
                $id   = $ac['Id'] ?? 'unbekannt';
                $output .= "📋 Name: {$name} | ID: {$id}<br>";
            }
        }
    }

    return $output;
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
              if ($element['name'] === 'DeviceID') {
                  $element['options'] = $options;
              }
          }

          return json_encode($form);
      }

}
