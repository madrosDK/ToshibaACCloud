<?php
require_once __DIR__ . '/mqtt_helper.php';
require_once __DIR__ . '/mqtt_client.php';

class ToshibaAC extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterProfiles();
        $this->RegisterTimer('UpdateTimer', 0, 'TOSH_GetStatus($_IPS["TARGET"]);');
        $this->RegisterVariableBoolean('TOSH_Power', 'Power', '~Switch', 10);
        $this->RegisterVariableFloat('TOSH_SetTemp', 'Soll-Temperatur', '~Temperature.Room', 20);
        $this->RegisterVariableFloat('TOSH_RoomTemp', 'Ist-Temperatur', '~Temperature.Room', 30);
        $this->RegisterVariableInteger('TOSH_Mode', 'Modus', 'TOSH.Mode', 40);
        $this->RegisterVariableInteger('TOSH_FanSpeed', 'Lüfterstufe', 'TOSH.FanSpeed', 50);
        $this->RegisterVariableBoolean('TOSH_Swing', 'Swing', '~Switch', 60);
        $this->RegisterVariableBoolean('TOSH_EcoMode', 'Eco-Modus', '~Switch', 65);
        $this->RegisterVariableBoolean('TOSH_SilentMode', 'Silent-Modus', '~Switch', 66);
        $this->RegisterVariableString('TOSH_WriteInfo', 'Schreiben', '', 67);
        $this->RegisterVariableString('TOSH_Firmware', 'Firmware', '', 70);
        $this->RegisterVariableString('TOSH_LastUpdate', 'Letztes Update', '', 80);
        $this->RegisterVariableInteger('TOSH_Model', 'Modell', '', 90);
        $this->RegisterVariableString('TOSH_MeritFeature', 'MeritFeature', '', 100);
        $this->RegisterVariableString('TOSH_ACStateData', 'ACStateData', '', 110);
        $this->RegisterVariableString('TOSH_ACStateBytes', 'ACStateData Bytes', '', 120);
        $this->RegisterVariableString('TOSH_DecodedState', 'Dekodierter Status', '', 130);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterProfiles();
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('TOSH.Mode')) { IPS_CreateVariableProfile('TOSH.Mode', 1); }
        IPS_SetVariableProfileAssociation('TOSH.Mode', 0, 'Auto', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.Mode', 1, 'Cool', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.Mode', 2, 'Heat', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.Mode', 3, 'Dry', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.Mode', 4, 'Fan', '', -1);
        if (!IPS_VariableProfileExists('TOSH.FanSpeed')) { IPS_CreateVariableProfile('TOSH.FanSpeed', 1); }
        IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 0, 'Auto', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 1, 'Quiet', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 2, 'Low', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 3, 'Medium', '', -1);
        IPS_SetVariableProfileAssociation('TOSH.FanSpeed', 4, 'High', '', -1);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'DiscoverDevices') { return $this->DiscoverDevices(); }
        return $this->SendMQTTCommand($Ident, $Value);
    }

    public function TestConnection(){ echo $this->EnsureLogin() ? 'Verbindung erfolgreich.' : 'Verbindung fehlgeschlagen.'; }

    public function TestMQTTPreparation()
    {
        if (!$this->EnsureLogin()) { echo "❌ Login fehlgeschlagen.\n"; return false; }
        $deviceId = ToshibaACMQTTHelper::mobileDeviceId($this->ReadPropertyString('Username'));
        $sas = $this->RegisterMobileDevice($deviceId);
        if (!$sas) { echo "❌ SAS Token holen fehlgeschlagen.\n"; return false; }
        $current = GetValueString($this->GetIDForIdent('TOSH_ACStateData'));
        $newState = ToshibaACMQTTHelper::buildState($current, 'TOSH_SetTemp', 22);
        $payload = ToshibaACMQTTHelper::commandPayload($deviceId, $this->ResolveACUniqueId(), $newState);
        echo "✅ SAS Token OK\nDeviceID: $deviceId\nAC UniqueID: ".$this->ResolveACUniqueId()."\nCurrent: $current\nNew:     $newState\nSAS Token: ".substr($sas,0,80)."...\n\n📦 MQTT Payload:\n$payload\n";
        SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'MQTT vorbereitet OK'); return true;
    }

    public function DiscoverDevices()
    {
        if (!$this->EnsureLogin()) { echo "❌ Login fehlgeschlagen.\n"; return false; }
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerACMapping?consumerId=' . urlencode($this->GetBuffer('ConsumerId'));
        $result = $this->QueryAPI($url, null, $this->GetBuffer('AccessToken'));
        if (!$result || empty($result['ResObj'])) { echo "❌ Keine Geräte gefunden.\n"; return false; }
        $devices = [];
        foreach ($result['ResObj'] as $entry) { foreach (($entry['ACList'] ?? []) as $ac) { $devices[] = ['name'=>$ac['Name']??'Unbekannt','id'=>$ac['Id']??'','uniqueId'=>$ac['DeviceUniqueId']??'']; } }
        if (empty($devices)) { echo "❌ Keine Geräte gefunden (leere ACList).\n"; return false; }
        $this->SetBuffer('DiscoveredDevices', json_encode($devices)); $this->ReloadForm();
        echo "✅ Gefundene Geräte:\n"; foreach ($devices as $device) { echo "📋 Name: {$device['name']} | ID: {$device['id']}\n"; }
        return true;
    }

    public function GetStatus()
    {
        if (!$this->EnsureLogin()) { echo "❌ Login fehlgeschlagen.\n"; return; }
        $acId = $this->ReadPropertyString('DeviceID');
        if ($acId === '') { echo "❌ Keine DeviceID gewählt.\n"; return; }
        $url = 'https://mobileapi.toshibahomeaccontrols.com/api/AC/GetCurrentACState?ACId=' . urlencode($acId);
        $result = $this->QueryAPI($url, null, $this->GetBuffer('AccessToken'));
        $this->DebugLog(__FUNCTION__, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (!$result || empty($result['ResObj'])) { echo "❌ Keine Statusdaten erhalten.\n"; return; }
        $state = $result['ResObj'];
        if (!empty($state['ACStateData'])) {
            $this->SetBuffer('LastACUniqueId', $state['ACDeviceUniqueId'] ?? '');
            $this->ApplyDecodedState($state['ACStateData'], true);
        }
        SetValueString($this->GetIDForIdent('TOSH_Firmware'), $state['FirmwareVersion'] ?? '');
        SetValueString($this->GetIDForIdent('TOSH_LastUpdate'), $state['UpdatedDate'] ?? '');
        SetValueInteger($this->GetIDForIdent('TOSH_Model'), (int)($state['Model'] ?? 0));
        SetValueString($this->GetIDForIdent('TOSH_MeritFeature'), $state['MeritFeature'] ?? '');
        echo "✅ Status aktualisiert.\n";
    }

    private function ApplyDecodedState(string $hex, bool $respectPending)
    {
        $decoded = $this->DecodeACStateData($hex);
        $pendingActive = $respectPending && ((int)$this->GetBuffer('PendingStateUntil') > time());
        $pendingIdent = $this->GetBuffer('PendingIdent');
        if (!($pendingActive && $pendingIdent === 'TOSH_Power')) { SetValueBoolean($this->GetIDForIdent('TOSH_Power'), $decoded['Power']); }
        if (!($pendingActive && $pendingIdent === 'TOSH_Mode')) { SetValueInteger($this->GetIDForIdent('TOSH_Mode'), $decoded['Mode']); }
        if (!($pendingActive && $pendingIdent === 'TOSH_SetTemp')) { SetValueFloat($this->GetIDForIdent('TOSH_SetTemp'), $decoded['SetTemp']); }
        SetValueFloat($this->GetIDForIdent('TOSH_RoomTemp'), $decoded['RoomTemp']);
        if (!($pendingActive && $pendingIdent === 'TOSH_FanSpeed')) { SetValueInteger($this->GetIDForIdent('TOSH_FanSpeed'), $decoded['FanSpeed']); }
        SetValueBoolean($this->GetIDForIdent('TOSH_Swing'), $decoded['Swing']);
        if (!($pendingActive && $pendingIdent === 'TOSH_EcoMode')) { SetValueBoolean($this->GetIDForIdent('TOSH_EcoMode'), $decoded['EcoMode']); }
        if (!($pendingActive && $pendingIdent === 'TOSH_SilentMode')) { SetValueBoolean($this->GetIDForIdent('TOSH_SilentMode'), $decoded['SilentMode']); }
        SetValueString($this->GetIDForIdent('TOSH_ACStateData'), $hex);
        SetValueString($this->GetIDForIdent('TOSH_ACStateBytes'), $this->FormatACStateBytes($hex));
        SetValueString($this->GetIDForIdent('TOSH_DecodedState'), json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($pendingActive) { SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'Warte auf Cloud-Sync: ' . $pendingIdent); }
        else { $this->SetBuffer('PendingIdent', ''); $this->SetBuffer('PendingValue', ''); }
    }

    public function GetSettings(){ if (!$this->EnsureLogin()) { return; } $url='https://mobileapi.toshibahomeaccontrols.com/api/AC/GetConsumerProgramSettings?consumerId='.urlencode($this->GetBuffer('ConsumerId')); $this->DebugLog(__FUNCTION__, json_encode($this->QueryAPI($url,null,$this->GetBuffer('AccessToken')), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
    public function DebugDump(){ $this->GetStatus(); echo "\nACStateData: " . GetValueString($this->GetIDForIdent('TOSH_ACStateData')) . "\n"; }

    public function GetConfigurationForm(){ $form=json_decode(file_get_contents(__DIR__.'/form.json'),true); $devices=json_decode($this->GetBuffer('DiscoveredDevices'),true)?:[]; $options=[['caption'=>'Bitte Gerät auswählen …','value'=>'']]; foreach($devices as $device){$options[]=['caption'=>"{$device['name']} ({$device['id']})",'value'=>$device['id']];} foreach($form['elements'] as &$element){if(($element['name']??'')==='DeviceID'){$element['options']=$options;}} return json_encode($form); }

    private function SendMQTTCommand($ident, $value)
    {
        if (!$this->EnsureLogin()) { echo "❌ Login fehlgeschlagen.\n"; return false; }
        $current = GetValueString($this->GetIDForIdent('TOSH_ACStateData'));
        $newState = ToshibaACMQTTHelper::buildState($current, $ident, $value);
        if ($newState === '') { echo "❌ State konnte nicht gebaut werden.\n"; return false; }
        $deviceId = ToshibaACMQTTHelper::mobileDeviceId($this->ReadPropertyString('Username'));
        $sas = $this->RegisterMobileDevice($deviceId);
        if (!$sas) { echo "❌ SAS Token holen fehlgeschlagen.\n"; return false; }
        $host='toshibasmaciothubprod.azure-devices.net';
        $mqttUser=$host.'/'.$deviceId.'/?api-version=2021-04-12';
        $topic='devices/'.$deviceId.'/messages/events/type=mob';
        $payload=ToshibaACMQTTHelper::commandPayload($deviceId,$this->ResolveACUniqueId(),$newState);
        try {
            $mqtt=new ToshibaACMQTTClient(); $mqtt->connect($host,$deviceId,$mqttUser,$sas); $mqtt->publish($topic,$payload); $mqtt->disconnect();
            $this->ApplyDecodedState($newState, false);
            $this->SetBuffer('PendingStateUntil', (string)(time() + 15));
            $this->SetBuffer('PendingIdent', $ident);
            $this->SetBuffer('PendingValue', json_encode($value));
            SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'MQTT gesendet, lokale Anzeige sofort aktualisiert: ' . $ident);
            return true;
        } catch (Exception $e) {
            echo '❌ MQTT Fehler: ' . $e->getMessage() . "\n";
            SetValueString($this->GetIDForIdent('TOSH_WriteInfo'), 'MQTT Fehler: ' . $e->getMessage()); return false;
        }
    }

    private function RegisterMobileDevice($deviceId){ $payload=json_encode(['DeviceID'=>$deviceId,'DeviceType'=>'1','Username'=>$this->ReadPropertyString('Username')]); $result=$this->QueryAPI('https://mobileapi.toshibahomeaccontrols.com/api/Consumer/RegisterMobileDevice',$payload,$this->GetBuffer('AccessToken')); return $result['ResObj']['SasToken']??false; }
    private function ResolveACUniqueId(){ $u=$this->GetBuffer('LastACUniqueId'); if($u!==''){return $u;} $selected=$this->ReadPropertyString('DeviceID'); $devices=json_decode($this->GetBuffer('DiscoveredDevices'),true)?:[]; foreach($devices as $device){if(($device['id']??'')===$selected){return $device['uniqueId']??'';}} return ''; }
    private function EnsureLogin(){ if($this->GetBuffer('AccessToken')!=='' && $this->GetBuffer('ConsumerId')!==''){return true;} $u=$this->ReadPropertyString('Username'); $p=$this->ReadPropertyString('Password'); if($u===''||$p===''){return false;} return (bool)$this->Login($u,$p); }
    private function Login($username,$password){ $result=$this->QueryAPI('https://mobileapi.toshibahomeaccontrols.com/api/Consumer/Login',json_encode(['Username'=>$username,'Password'=>$password])); if(!$result||empty($result['ResObj']['access_token'])||empty($result['ResObj']['consumerId'])){return false;} $this->SetBuffer('AccessToken',$result['ResObj']['access_token']); $this->SetBuffer('ConsumerId',$result['ResObj']['consumerId']); return $result['ResObj']['access_token']; }
    private function QueryAPI($url,$postData=null,$token=null){ $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$url); if($postData!==null){curl_setopt($ch,CURLOPT_POST,true); curl_setopt($ch,CURLOPT_POSTFIELDS,$postData);} $headers=['Content-Type: application/json']; if(!empty($token)){$headers[]='Authorization: Bearer '.$token;} curl_setopt($ch,CURLOPT_HTTPHEADER,$headers); curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); $result=curl_exec($ch); $error=curl_error($ch); curl_close($ch); if($result===false||$result===''){ $this->DebugLog(__FUNCTION__,'Fehler bei Query: '.$url.' / '.$error); return false;} return json_decode($result,true); }

    private function DecodeACStateData(string $hex)
    {
        $bytes=str_split($hex,2); $feature=isset($bytes[5])?hexdec($bytes[5]):0; $fanRaw=isset($bytes[3])?hexdec($bytes[3]):0; $modeRaw=isset($bytes[1])?hexdec($bytes[1]):0;
        return [
            'Power'=>isset($bytes[0])?(hexdec($bytes[0])===0x30):false,'PowerRaw'=>$bytes[0]??'',
            'Mode'=>ToshibaACMQTTHelper::mapModeFromRaw($modeRaw),'ModeRaw'=>$bytes[1]??'',
            'SetTemp'=>isset($bytes[2])?hexdec($bytes[2]):0,'SetTempRaw'=>$bytes[2]??'',
            'FanMode'=>ToshibaACMQTTHelper::mapFanFromRaw($fanRaw),'FanModeRaw'=>$bytes[3]??'',
            'Feature'=>$feature,'FeatureRaw'=>$bytes[5]??'','EcoMode'=>($feature===3),'SilentMode'=>($fanRaw===0x31 && $feature===0),
            'FanSpeed'=>ToshibaACMQTTHelper::mapFanFromRaw($fanRaw),'FanSpeedRaw'=>$bytes[3]??'',
            'RoomTemp'=>isset($bytes[8])?hexdec($bytes[8]):0,'RoomTempRaw'=>$bytes[8]??'',
            'AirFlow'=>isset($bytes[9])?hexdec($bytes[9]):0,'AirFlowRaw'=>$bytes[9]??'',
            'Swing'=>isset($bytes[10])?(hexdec($bytes[10])>0):false,'SwingRaw'=>$bytes[10]??'',
            'ByteCount'=>count($bytes),'Bytes'=>$bytes
        ];
    }
    private function FormatACStateBytes(string $hex){ $bytes=str_split($hex,2); $out=[]; foreach($bytes as $i=>$byte){$out[]=sprintf('%02d:%s(%d)',$i+1,$byte,hexdec($byte));} return implode(' ',$out); }
    private function DebugLog(string $message,string $data=''){ if($this->ReadPropertyBoolean('Debug')){$this->SendDebug($message,$data,0);} }
}
