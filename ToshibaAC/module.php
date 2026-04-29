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

  public function DebugDump()
  {
      if (!$this->EnsureLoginAndACId()) {
          echo "❌ Login fehlgeschlagen.\n";
          return;
      }

      $accessToken = $this->GetBuffer('AccessToken');
      $consumerId  = $this->GetBuffer('ConsumerId');
      $acId        = $this->ReadPropertyString('DeviceID');

      $endpoints = [
          'CurrentACState' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?ACId=' . urlencode($acId),
          'ConsumerACMapping' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($consumerId),
          'ConsumerProgramSettings' => 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerProgramSettings?consumerId=' . urlencode($consumerId),
      ];

      foreach ($endpoints as $name => $url) {
          $result = $this->QueryAPI($url, null, $accessToken);

          echo "\n==============================\n";
          echo $name . "\n";
          echo "==============================\n";
          echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
          echo "\n";

          $this->SendDebug($name, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0);
      }
  }

  public function ApplyChanges()
  {
      parent::ApplyChanges();
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

  // ... rest unverändert ...
}
