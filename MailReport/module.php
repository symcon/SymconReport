<?php

declare(strict_types=1);

const ARCHIVE_CONTROL_MODULE_ID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
const SMTP_MODULE_ID = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

class MailReport extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('SMTP', 0);
        $this->RegisterPropertyInteger('Variable', 0);
        $this->RegisterPropertyInteger('Interval', 1);
        $this->RegisterPropertyBoolean('DecimalSeparator', true);

        $this->RegisterPropertyInteger('ArchiveControlID', IPS_GetInstanceListByModuleID(ARCHIVE_CONTROL_MODULE_ID)[0]);
        $this->RegisterVariableBoolean('Active', $this->Translate('Mail Report active'), '~Switch');
        $this->EnableAction('Active');

        //Update at next full hourF
        $this->RegisterTimer('SendTimer', 0, "MR_SendInfo(\$_IPS['TARGET']);");
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Update the timer if the module is active
        if (GetValue($this->GetIDForIdent('Active'))) {
            $this->UpdateTimer();
        }

        //Adding References
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
        if (IPS_VariableExists($this->ReadPropertyInteger('Variable'))) {
            $this->RegisterReference($this->ReadPropertyInteger('Variable'));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                $this->SetActive($Value);
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    public function SendInfo()
    {
        if (!IPS_VariableExists($this->ReadPropertyInteger('Variable'))) {
            echo $this->Translate('Aggregated variable does not exist.');
            return;
        }

        if (!IPS_InstanceExists($this->ReadPropertyInteger('SMTP')) || (IPS_GetInstance($this->ReadPropertyInteger('SMTP'))['ModuleInfo']['ModuleID'] != SMTP_MODULE_ID)) {
            echo $this->Translate('SMTP Module does not exist');
            return;
        }

        $filename = __DIR__ . '/' . $this->ReadPropertyInteger('Variable') . '.' . $this->GetAggregationString() . '.csv';
        if (file_exists($filename)) {
            file_put_contents($filename, '');
        }

        $file = fopen($filename, 'w');
        if (AC_GetAggregationType($this->ReadPropertyInteger('ArchiveControlID'), $this->ReadPropertyInteger('Variable')) == 0) { //default aggregation
            fwrite($file, "TimeStamp,Avg,MinTime,Min,MaxTime,Max\n");
        } else { //counter aggregation
            fwrite($file, "TimeStamp,Consumption,MinTime,Min,MaxTime,Max\n");
        }

        $digits = 7;
        $profile = $this->GetProfileName(IPS_GetVariable($this->ReadPropertyInteger('Variable')));
        if (IPS_VariableProfileExists($profile)) {
            $digits = IPS_GetVariableProfile($profile)['Digits'];
        }

        $aggregatedValues = AC_GetAggregatedValues(
            $this->ReadPropertyInteger('ArchiveControlID'),
            $this->ReadPropertyInteger('Variable'),
            $this->GetAggregation(),
            $this->GetAggregationStart(),
            $this->GetAggregationEnd(),
            0
        );
        $decimalSeparator = $this->ReadPropertyBoolean('DecimalSeparator') ? ',' : '.';
        $csvSeparator = $this->ReadPropertyBoolean('DecimalSeparator') ? ';' : ',';
        for ($i = count($aggregatedValues) - 1; $i >= 0; $i--) {
            $value = $aggregatedValues[$i];
            $dataString = date('j.n.Y H:i:s', $value['TimeStamp']) . $csvSeparator .
                          number_format($value['Avg'], $digits, $decimalSeparator, '') . $csvSeparator .
                          date('j.n.Y H:i:s', $value['MinTime']) . $csvSeparator .
                          number_format($value['Min'], $digits, $decimalSeparator, '') . $csvSeparator .
                          date('j.n.Y H:i:s', $value['MaxTime']) . $csvSeparator .
                          number_format($value['Max'], $digits, $decimalSeparator, '') . "\n";
            fwrite($file, $dataString);
        }

        $title = $this->Translate('IP-Symcon: Aggregated Data of Variable') .
                    ' "' . IPS_GetObject($this->ReadPropertyInteger('Variable'))['ObjectName'] . '" ' .
                    '(' . $this->ReadPropertyInteger('Variable') . ') ' .
                    $this->Translate('for') . ' ' .
                    $this->GetTimeIntervalString();
        SMTP_SendMailAttachment($this->ReadPropertyInteger('SMTP'), $title, $this->Translate('You can find the data for the time interval attached to this mail.'), $filename);

        if (GetValue($this->GetIDForIdent('Active'))) {
            $this->UpdateTimer();
        }

        fclose($file);
        unlink($filename);
    }

    public function SetActive(bool $Active)
    {
        SetValue($this->GetIDForIdent('Active'), $Active);
        if ($Active) {
            $this->UpdateTimer();
        } else {
            $this->SetTimerInterval('SendTimer', 0);
        }
    }

    private function GetElementIndexByName(string $name)
    {
        $elements = json_decode(file_get_contents(__DIR__ . '/form.json'))->elements;
        for ($i = 0; $i < count($elements); $i++) {
            if ($elements[$i]->name == $name) {
                return $i;
            }
        }
        throw new Exception('Getting index of non existing element');
    }

    private function GetAggregation()
    {
        switch ($this->ReadPropertyInteger('Interval')) {
            case 0:
                return 5;

            case 1:
                return 0;

            case 2:
                return 1;

            case 3:
                return 1;

            case 5:
                return 6;
        }
    }

    private function GetAggregationString()
    {
        switch ($this->GetAggregation()) {
            case 0:
                return 'hour';

            case 1:
                return 'day';

            case 5:
                return '5minutes';

            case 6:
                return '1minute';
        }
    }

    private function GetAggregationStart()
    {
        switch ($this->ReadPropertyInteger('Interval')) {
            case 0:
                return mktime(intval(date('H')) - 1, 0, 0);

            case 1:
                return mktime(0, 0, 0, intval(date('n')), intval(date('j')) - 1);

            case 2:
                return mktime(0, 0, 0, intval(date('n')), intval(date('j')) - intval(date('N')) - 6);

            case 3:
                return mktime(0, 0, 0, intval(date('n')) - 1, 1);

            case 5:
                return mktime(intval(date('H')), intval(date('i')) - (intval(date('i')) % 5) - 5, 0);
        }
    }

    private function GetAggregationEnd()
    {
        switch ($this->ReadPropertyInteger('Interval')) {
            case 0:
                return mktime(intval(date('H')), 0, -1);

            case 1:
                return mktime(0, 0, -1);

            case 2:
                return mktime(0, 0, -1, intval(date('n')), intval(date('j')) - intval(date('N')) + 1);

            case 3:
                return mktime(0, 0, -1, intval(date('n')), 1);

            case 5:
                return mktime(intval(date('H')), intval(date('i')) - (intval(date('i')) % 5), -1);
        }
    }

    private function GetTimeIntervalString()
    {
        switch ($this->ReadPropertyInteger('Interval')) {
            case 1: // Daily
                return date('j.n.Y', $this->GetAggregationStart());

            case 2: // Weekly
                return $this->Translate('Calendar Week') . ' ' . date('W o', $this->GetAggregationStart());

            case 3: // Monthly
                return $this->Translate(date('F', $this->GetAggregationStart())) . ' ' . date('Y', $this->GetAggregationStart());

            default:
                return $this->GetAggregationStart();
        }
    }

    private function UpdateTimer()
    {
        $difference = 0;

        switch ($this->ReadPropertyInteger('Interval')) {
            case 0: // Hourly
                $difference = mktime(intval(date('H')) + 1, 0, 0) - time();
                break;

            case 1: // Daily
                $difference = mktime(0, 0, 0, intval(date('n')), intval(date('j')) + 1) - time();
                break;

            case 2: // Weekly
                $difference = mktime(0, 0, 0, intval(date('n')), intval(date('j')) + 7 - (intval(date('N')) - 1)) - time();
                break;

            case 3: // Monthly
                $difference = mktime(0, 0, 0, intval(date('n')) + 1, 1) - time();
                break;

            case 5: // Every five minutes
                $difference = mktime(intval(date('H')), intval(date('i')) + 5 - (intval(date('i')) % 5), 0) - time();
                break;
        }

        $this->SetTimerInterval('SendTimer', $difference * 1000 + 30 * 1000); // Add thirty seconds to avoid racing conditions
    }

    private function CreateLabel(int $objectID)
    {
        return IPS_GetName($objectID) . ' (' . $objectID . ')';
    }

    private function GetProfileName($variable)
    {
        if ($variable['VariableCustomProfile'] != '') {
            return $variable['VariableCustomProfile'];
        } else {
            return $variable['VariableProfile'];
        }
    }
}
