<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';

class PDFReportMultiEnergy extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        //Properties
        $this->RegisterPropertyInteger('AggregationLevel', 1);
        $this->RegisterPropertyString('DecimalSeparator', ',');
        $this->RegisterPropertyString('EnergyVariables', '[]');

        //Variables
        $this->RegisterVariableInteger('Start', $this->Translate('Start'), '~UnixTimestampDate', 1);
        $this->EnableAction('Start');
        if ($this->GetValue('Start') == 0) {
            $this->SetValue('Start', time() - (60 * 60 * 24));
        }
        $this->RegisterVariableInteger('End', $this->Translate('End'), '~UnixTimestampDate', 2);
        $this->EnableAction('End');
        if ($this->GetValue('End') == 0) {
            $this->SetValue('End', time());
        }
        $this->RegisterMediaDocument('ReportPDF', $this->Translate('Report (PDF)'), 'pdf', 4);
        $this->RegisterScript('Generate', 'Generate', "<? RAC_GenerateMultiEnergyReport(IPS_GetParent(\$_IPS['SELF']));", 3);

    }

    public function GenerateMultiEnergyReport(): bool
    {
        if (count(json_decode($this->ReadPropertyString('EnergyVariables'), true)) === 0) {
            echo $this->Translate('No Variables are selected.');
            return false;
        }

        $pdfContent = $this->GeneratePDF('IP-Symcon ' . IPS_GetKernelVersion(), 'report.pdf');

        if ($this->GetStatus() >= IS_EBASE) {
            return false;
        }

        $mediaID = $this->GetIDForIdent('ReportPDF');
        IPS_SetMediaContent($mediaID, base64_encode($pdfContent));

        return true;
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Start':
            case 'End':
                $this->SetValue('Start', intval($Value));
                break;
        }
    }

    private function RegisterMediaDocument($Ident, $Name, $Extension, $Position = 0): void
    {
        $this->RegisterMedia(5, $Ident, $Name, $Extension, $Position);
    }

    private function RegisterMedia($Type, $Ident, $Name, $Extension, $Position): void
    {
        $mediaId = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($mediaId === false) {
            $mediaId = IPS_CreateMedia(5 /* Document */);
            IPS_SetParent($mediaId, $this->InstanceID);
            IPS_SetIdent($mediaId, $Ident);
            IPS_SetName($mediaId, $Name);
            IPS_SetPosition($mediaId, $Position);
            IPS_SetMediaFile($mediaId, 'media/' . $mediaId . '.' . $Extension, false);
        }
    }

    private function GeneratePDF($author, $filename): string
    {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($author);
        $pdf->SetTitle('');
        $pdf->SetSubject('');

        $pdf->setPrintHeader(false);

        $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP - 5, PDF_MARGIN_RIGHT);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);

        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $pdf->setPrintFooter(false);

        $pdf->SetFont('dejavusans');

        $pdf->AddPage();

        //PDF Content
        //Header
        $pdf->writeHTML($this->GenerateHTMLHeader(), true, false, true, false, '');

        //Text
        $pdf->writeHTML($this->GenerateHTMLText(), true, false, true, false, '');

        //Save the pdf
        return $pdf->Output($filename, 'S');
    }

    private function GenerateHTMLHeader()
    {
        $title = strtoupper($this->Translate('Consumption'));
        //Get Times
        $startTime = $this->GetValue('Start');
        $endTime = $this->GetValue('End');
        if ($startTime > $endTime) {
            $startTime = $this->GetValue('End');
            $endTime = $this->GetValue('Start');
        }
        $timestamps = $this->Translate('From') . ' ' . date('d.m.Y', $startTime) . ' ' . $this->Translate('to') . ' ' . date('d.m.Y', $endTime);

        return <<<EOT
        <br/><br/><br/>
        <h1 style="font-weight: normal; font-size: 25px">$title</h1>
        <p>$timestamps</p>
        EOT;
    }

    private function GenerateHTMLText(): string
    {
        $dataText = '';
        $data = $this->FetchData(json_decode($this->ReadPropertyString('EnergyVariables'), true));
        foreach ($data['Variables'] as $key => $variable) {
            $name = $variable['Name'];
            $consumption = $variable['Consumption'];
            $percentage = $variable['Percentage'];

            //replace the decimal separator
            $consumption = str_replace('.', $this->ReadPropertyString('DecimalSeparator'), strval($consumption));
            $percentage = str_replace('.', $this->ReadPropertyString('DecimalSeparator'), strval($percentage));
            $this->SendDebug('Consumption', $consumption, 0);
            $this->SendDebug('percentage', $percentage, 0);

            $dataText .= <<<EOT
                <tr>
                    <td><p>$name</p></td>
                    <td><p>$consumption kWh</p></td>
                    <td><p>$percentage %</p></td>
                </tr>
            EOT;
        }
        $totalConsumption = $data['Total'];
        $totalConsumption = str_replace('.', $this->ReadPropertyString('DecimalSeparator'), strval($totalConsumption));
        $textCounter = $this->Translate('Counter');
        $textConsumption = $this->Translate('Consumption');
        $textPercentage = $this->Translate('Percentage');
        $textTotalConsumption = $this->Translate('Total Consumption');

        return <<<EOT
        <h3>$textTotalConsumption: $totalConsumption kwh</h3>
        <br> </br>
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr style="background-color: #cccccc; padding:5px;">
                    <th><p>$textCounter</p></th>
                    <th><p>$textConsumption</p></th>
                    <th><p>$textPercentage</p></th>
                </tr>
                $dataText
            </table>
        EOT;
    }

    private function FetchData(array $variables): array
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        //Get Times
        $startTime = $this->GetValue('Start');
        $endTime = $this->GetValue('End');
        if ($startTime > $endTime) {
            $startTime = $this->GetValue('End');
            $endTime = $this->GetValue('Start');
        }
        $data = [];
        $totalConsumption = 0;

        //Get the consumption
        foreach ($variables as $variable) {
            $variable = $variable['CounterVariable'];
            $aggregatedValues = AC_GetAggregatedValues($archiveID, $variable, $this->ReadPropertyInteger('AggregationLevel'), $startTime, $endTime, 0);
            $this->SendDebug('Values of' . $variable, print_r($aggregatedValues, true), 0);
            //ToDO look if the Values are 1000 and the Endtime match

            if (count($aggregatedValues) === 1000 && $startTime != $aggregatedValues[array_key_last($aggregatedValues)]['TimeStamp']) {
                $this->SendDebug('Variable ' . $variable, 'Too many datasets, get higher aggregationLevel, to fetch all datasets for the period', 0);
            }
            $consumption = round(array_sum(array_column($aggregatedValues, 'Avg')), 2);
            $totalConsumption += $consumption;
            $data[] = [
                'Name'        => IPS_GetName($variable),
                'Consumption' => $consumption,
                'Percentage'  => 0
            ];
        }

        $values['Total'] = $totalConsumption;
        if ($totalConsumption == 0) {
            $totalConsumption = 1; // To prevent the division by zero
        }
        //Calculate the percentage
        foreach ($data as $key => $value) {
            $data[$key]['Percentage'] = round(($value['Consumption'] / $totalConsumption) * 100, 2);
        }
        $values['Variables'] = $data;

        return $values;
    }
}