<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';

class PDFReportEnergy extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterPropertyString('LogoData', '');
        $this->RegisterPropertyString('EnergyType', 'Gas');
        $this->RegisterPropertyInteger('CounterID', 0);
        $this->RegisterPropertyInteger('TemperatureID', 0);
        $this->RegisterPropertyInteger('PredictionID', 0);
        $this->RegisterPropertyInteger('CO2Type', -1);

        $this->RegisterMediaDocument('ReportPDF', $this->Translate('Report (PDF)'), 'pdf');
    }

    private function RegisterMediaDocument($Ident, $Name, $Extension, $Position = 0)
    {
        $this->RegisterMedia(5, $Ident, $Name, $Extension, $Position);
    }

    private function RegisterMedia($Type, $Ident, $Name, $Extension, $Position)
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

    public function GenerateEnergyReport()
    {
        if ($this->ReadPropertyInteger('CounterID') == 0 || $this->ReadPropertyInteger('PredictionID') == 0) {
            echo $this->Translate('Selected variable is not a valid variable!');
            return false;
        }

        $pdfContent = $this->GeneratePDF('IP-Symcon ' . IPS_GetKernelVersion(), 'report.pdf');

        $mediaID = $this->GetIDForIdent('ReportPDF');
        IPS_SetMediaContent($mediaID, base64_encode($pdfContent));

        return true;
    }

    private function GeneratePDF($author, $filename)
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

        $pdf->SetFont('dejavusans');

        $pdf->AddPage();

        //PDF Content
        //Header
        $pdf->writeHTML($this->GenerateHTMLHeader(), true, false, true, false, '');

        //Charts
        if ($this->ReadPropertyInteger('TemperatureID') != 0) {
            $svg = $this->GenerateCharts($this->ReadPropertyInteger('TemperatureID'));
            $pdf->ImageSVG('@' . $svg, $x = 105, $y = '', $w = 90, $h = '', $link = '', $align = 5, $palign = 5, $border = 0, $fitonpage = true);
        }
        $svg = $this->GenerateCharts($this->ReadPropertyInteger('CounterID'));
        $pdf->ImageSVG('@' . $svg, $x = 10, $pdf->GetY(), $w = 90, $h = '', $link = '', $align = '', $palign = '', $border = 0, $fitonpage = true);

        //reset Y
        $pdf->setY($pdf->getY() + 70);

        //text
        $pdf->writeHTML($this->GenerateHTMLText(), true, false, true, false, '');

        //Save the pdf
        return $pdf->Output($filename, 'S');
    }

    private function GenerateHTMLHeader()
    {
        $date = $this->Translate(date('F', strtotime('-1 month'))) . ' ' . date('Y');
        $energy = $this->Translate('Your') . ' ' . $this->ReadPropertyString('EnergyType') . $this->Translate(' behaviour');
        $title = strtoupper($this->Translate('The Behaviour'));
        $logo = $this->ReadPropertyString('LogoData');

        return <<<EOT
        <table cellpadding="5" cellspacing="0" border="0" width="95%">
        <tr>
            <td>
                <br/><br/><br/>
                $date<br/>
                $energy<br/><hr/>
                <h1>$title </h1>
            </td>
            <td width="50%" align="right"><img src="@$logo"></td>
        </tr>
        </table>
        EOT;
    }

    private function GenerateCharts(int $id)
    {
        $chart = '<meta xmlns:sym="symcon">' . AC_RenderChart(IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0], $id, strtotime('first day of last month 00:00:00'), 3, 0, false, false, 800, 500) . '</meta>';
        $example = simplexml_load_string($chart);

        //add text-anchor
        foreach ($example->svg->g as $object) {
            if ($object['class'] == 'y axis') {
                $object->g->addAttribute('text-anchor', 'end');
            }
        }

        //set the opacity of the vertical lines on 0 (trancparence)
        //"remove" the vertical lines
        $object = $example->svg->g[0]->line;
        for ($i = 0; $i < count($object); $i++) {
            $node = $object[$i];
            if (!empty($node) && $node['class'] == 'gridLine y') {
                $node->addAttribute('opacity', '0');
            }
        }

        return $example->asXML();
    }

    private function GenerateHTMLText()
    {
        $data = $this->FetchData();

        $text =
        <<<EOT
        <p>Im $data[0] haben Sie $data[1] kWh verbraucht. Im Vorjahr hatten Sie einen Verbrauch von $data[2] kWh.</p>
        <h2>Verhalten</h2>
        <hr/>
        <h3>$data[0]:</h3>
            <p>
            $data[3]
            Erwarteter Verbrauch aufgrund ihres Verhaltens: $data[4] kWh <br>
            Tatsächlicher Verbrauch: $data[1] kWh <br/>
            <br>
            Sie konnten Ihren Verbrauch um $data[5] % $data[6] im Zeitraum.<br/>
            </p>
        EOT;

        if ($data[7] != -1) {
            $text .= "<h3>CO2 Emmisionen</h3> <p>$data[7] kg</p>";
        }
        return $text;
    }

    private function FetchData()
    {
        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $startTime = strtotime('first day of last month 00:00:00');
        $endTime = strtotime('first day of this month 00:00:00');

        $counterID = $this->ReadPropertyInteger('CounterID');

        $month = $this->Translate(date('F', $startTime));

        $consum = AC_GetAggregatedValues($archivID, $counterID, 3, $startTime, $endTime, 0)[0]['Avg'];
        $consumLastYear = AC_GetAggregatedValues($archivID, $counterID, 3, strtotime('-1 year', $startTime), strtotime('-1 year', $endTime), 0)[0]['Avg'];

        if (($temperatureID = $this->ReadPropertyInteger('TemperatureID')) != 0) {
            $avgTemp = AC_GetAggregatedValues($archivID, $temperatureID, 3, $startTime, $endTime, 0)[0]['Avg'];
            $avgTemp = round($avgTemp, 1);
            $avgTemp = 'Die Durchschnittstemperatur betrug: ' . $avgTemp . ' °C <br>';
        } else {
            $avgTemp = '';
        }

        $predictionID = $this->ReadPropertyInteger('PredictionID');
        $prediction = GetValue($predictionID);

        $percent = round(((1 - ($consum / $prediction)) * 100), 2);

        if ($percent <= 100) {
            $percentText = 'senken';
        } else {
            $percentText = 'steigern';
        }

        if ($this->ReadPropertyInteger('CO2Type') != -1) {
            $co2 = $consum * $this->ReadPropertyInteger('CO2Type');
        } else {
            $co2 = -1;
        }

        $data = [$month, $consum, $consumLastYear, $avgTemp, $prediction, $percent, $percentText, $co2];

        return $data;
    }
}