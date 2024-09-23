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
        $this->RegisterVariableInteger('Start', 'Start', '~UnixTimestampDate', 1);
        $this->SetValue('Start', time());
        $this->RegisterVariableInteger('End', 'End', '~UnixTimestampDate', 2);
        $this->SetValue('End', time() - (60 * 60 * 24));
        $this->RegisterMediaDocument('ReportPDF', $this->Translate('Report (PDF)'), 'pdf', 4);
        $this->RegisterScript('Generate', 'Generate', "<? RAC_GenerateMultiEnergyReport(IPS_GetParent(\$_IPS['SELF']));", 3);

    }

    public function GenerateMultiEnergyReport(): bool
    {
        if (count(json_decode($this->ReadPropertyString('EnergyVariables'), true)) === 0) {
            echo $this->Translate('Selected variable is not a valid variable!');
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
        // //Header
        // $logo = $this->ReadPropertyString('LogoData');
        // //echo($logo);
        // if (strpos(base64_decode($logo), '<svg') !== false) {
        //     $logo = base64_decode($logo);
        //     $pdf->ImageSVG('@' . $logo, $x = 150, $y = 0, $w = 50, $h = 50, $border = 1);
        //     $logo = '';
        // } elseif ($logo != '') {
        //     $logo = '<img src="@' . $logo . '">';
        // }

        $pdf->writeHTML($this->GenerateHTMLHeader(), true, false, true, false, '');

        // //Charts
        // if (IPS_VariableExists($this->ReadPropertyInteger('TemperatureID'))) {
        //     $svg = $this->GenerateCharts($this->ReadPropertyInteger('TemperatureID'));
        //     $pdf->ImageSVG('@' . $svg, $x = 105, $y = '', $w = 90, $h = '', $link = '', $align = 5, $palign = 5, $border = 0, $fitonpage = true);
        // }
        // $svg = $this->GenerateCharts($this->ReadPropertyInteger('CounterID'));
        // $pdf->ImageSVG('@' . $svg, $x = 10, $pdf->GetY(), $w = 90, $h = '', $link = '', $align = '', $palign = '', $border = 0, $fitonpage = true);

        //reset Y
        //$pdf->setY($pdf->getY() + 5);

        //text
        $pdf->writeHTML($this->GenerateHTMLText(), true, false, true, false, '');

        //Save the pdf
        return $pdf->Output($filename, 'S');
    }

    private function GenerateHTMLHeader()
    {
        $title = strtoupper($this->Translate('Consumption'));
        $timestamps = 'From ' . date('d.m.Y', $this->GetValue('Start')) . ' to ' . date('d.m.Y', $this->GetValue('End'));

        return <<<EOT
        <table cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td>
                <br/><br/><br/>
                <h1 style="font-weight: normal; font-size: 25px">$title</h1>
                <p> $timestamps </p>
            </td>
        </tr>
        </table>
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
            $dataText .= <<<EOT
                <tr>
                    <td><p>$name</p></td>
                    <td><p>$consumption kWh</p></td>
                    <td><p>$percentage %</p></td>
                </tr>
            EOT;
        }
        $totalConsumption = $data['Total'];

        return <<<EOT
        <h3>Total consumption: $totalConsumption kwh</h3>
        <br> </br>
            <table cellpadding="2" cellspacing="0" border="0" width="100%">
                <tr style="background-color: #cccccc; padding:5px;">
                    <th><p>Counter</p></th>
                    <th><p>Consumption</p></th>
                    <th><p>Percentage</p></th>
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
        $data = [];
        $totalConsumption = 0;

        //Get the consumption
        foreach ($variables as $variable) {
            $variable = $variable['CounterVariable'];
            $aggregatedValues = AC_GetAggregatedValues($archiveID, $variable, $this->ReadPropertyInteger('AggregationLevel'), $startTime, $endTime, 0);
            //ToDO look if the Values are 1000 and the Endtime match
            $consumption = array_sum(array_column($aggregatedValues, 'Avg'));
            $totalConsumption += $consumption;
            $data[] = [
                'Name'        => IPS_GetName($variable),
                'Consumption' => $consumption,
                'Percentage'  => 0
            ];
        }

        //Calculate the percentage
        foreach ($data as $key => $value) {
            $data[$key]['Percentage'] = round(($value['Consumption'] / $totalConsumption) * 100, 2);
        }
        $values['Variables'] = $data;
        $values['Total'] = $totalConsumption;

        return $values;
    }
}