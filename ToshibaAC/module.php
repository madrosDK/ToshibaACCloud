<?php

class ToshibaAC extends IPSModule
{
  public function Create()
  {
      parent::Create();

      $this->RegisterPropertyString('Username', '');
      $this->RegisterPropertyString('Password', '');
      $this->RegisterPropertyString('DeviceID', '');

      // NEW
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
  }

  public function ApplyChanges()
  {
      parent::ApplyChanges();

      $interval = $this->ReadPropertyInteger('UpdateInterval');

      if ($interval > 0) {
          $this->SetTimerInterval('UpdateTimer', $interval * 1000);
      } else {
          $this->SetTimerInterval('UpdateTimer', 0);
      }
  }

  private function DebugLog($msg, $data = '')
  {
      if ($this->ReadPropertyBoolean('Debug')) {
          $this->SendDebug($msg, $data, 0);
      }
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
      if ($this->EnsureLoginAndACId()) {
          $acId = $this->ReadPropertyString('DeviceID');
          echo "Verbindung erfolgreich. AC-ID: $acId";
      } else {
          echo "Verbindung fehlgeschlagen.";
      }
  }

  private function SendCommand()
  {
      if (!$this->EnsureLoginAndACId()) {
          $this->DebugLog(__FUNCTION__, 'Login fehlgeschlagen');
          return;
      }

      $accessToken = $this->GetBuffer('AccessToken');
      $acId        = $this->ReadPropertyString('DeviceID');

      if (empty($acId)) {
          $this->DebugLog(__FUNCTION__, 'Keine DeviceID gewählt.');
          echo "❌ Keine DeviceID gewählt.\n";
          return;
      }

      $payload = [
          'ACId' => $acId,
          'Power' => GetValueBoolean($this->GetIDForIdent('TOSH_Power')) ? 1 : 0,
          'OperationMode' => GetValueInteger($this->GetIDForIdent('TOSH_Mode')),
          'TargetTemperature' => GetValueFloat($this->GetIDForIdent('TOSH_SetTemp')),
          'AirSwingLR' => GetValueBoolean($this->GetIDForIdent('TOSH_Swing')) ? 'auto' : 'stop',
          'FanSpeed' => GetValueInteger($this->GetIDForIdent('TOSH_FanSpeed')),
      ];

      $result = $this->QueryAPI(
          'https://mobileapi.toshibahomeaccontrols.com/api/AC/SetACState',
          json_encode($payload),
          $accessToken
      );

      $this->DebugLog(__FUNCTION__, json_encode($result));
  }

  // rest bleibt gleich
}
