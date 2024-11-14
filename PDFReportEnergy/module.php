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
        $this->RegisterPropertyString('HeaderText', '');
        $this->RegisterPropertyInteger('CounterID', 1);
        $this->RegisterPropertyBoolean('CounterDynamic', true);
        $this->RegisterPropertyInteger('TemperatureID', 1);
        $this->RegisterPropertyBoolean('TemperatureDynamic', true);
        $this->RegisterPropertyInteger('PredictionID', 1);
        $this->RegisterPropertyInteger('CO2Type', -1);
        $this->RegisterPropertyString('DecimalSeparator', ',');
        $this->RegisterPropertyString('StartTime', $this->timeStampToDateObject(strtotime('first day of last month 00:00:00')));
        $this->RegisterPropertyString('EndTime', $this->timeStampToDateObject(strtotime('first day of this month 00:00:00')));
        $this->RegisterPropertyInteger('CustomTextID', 1);
        $this->RegisterPropertyInteger('CustomTextIDTop', 1);

        $this->RegisterMediaDocument('ReportPDF', $this->Translate('Report (PDF)'), 'pdf');
    }

    public function GenerateEnergyReport()
    {
        if (!IPS_VariableExists($this->ReadPropertyInteger('CounterID'))) {
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
        $pdf->setPrintFooter(false);

        $pdf->SetFont('dejavusans');

        $pdf->AddPage();

        //PDF Content
        //Header
        $logo = $this->ReadPropertyString('LogoData');
        //echo($logo);
        if (strpos(base64_decode($logo), '<svg') !== false) {
            $logo = base64_decode($logo);
            $pdf->ImageSVG('@' . $logo, $x = 150, $y = 0, $w = 50, $h = 50, $border = 1);
            $logo = '';
        } elseif ($logo != '') {
            $logo = '<img src="@' . $logo . '">';
        }

        $pdf->writeHTML($this->GenerateHTMLHeader($logo), true, false, true, false, '');

        //Charts
        if (IPS_VariableExists($this->ReadPropertyInteger('TemperatureID'))) {
            $svg = $this->GenerateCharts($this->ReadPropertyInteger('TemperatureID'), $this->ReadPropertyBoolean('TemperatureDynamic'));
            $pdf->ImageSVG('@' . $svg, $x = 105, $y = '', $w = 90, $h = '', $link = '', $align = 5, $palign = 5, $border = 0, $fitonpage = true);
        }
        $svg = $this->GenerateCharts($this->ReadPropertyInteger('CounterID'), $this->ReadPropertyBoolean('CounterDynamic'));
        $pdf->ImageSVG('@' . $svg, $x = 10, $pdf->GetY(), $w = 90, $h = '', $link = '', $align = '', $palign = '', $border = 0, $fitonpage = true);

        //reset Y
        $pdf->setY($pdf->getY() + 62);

        //text
        $pdf->writeHTML($this->GenerateHTMLText(), true, false, true, false, '');

        //Save the pdf
        return $pdf->Output($filename, 'S');
    }

    private function GenerateHTMLHeader(string $logo)
    {
        $startTime = $this->getStartTime();
        $date = strtoupper($this->Translate(date('F', $startTime)) . ' ' . date('Y', $startTime));
        $energy =  $this->ReadPropertyString('HeaderText');
        $title = strtoupper($this->Translate('Consumption'));

        return <<<EOT
        <table cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td>
                <br/><br/><br/>
                $date<br/>
                $energy<br/><hr style="height: 5px"/>
                <h1 style="font-weight: normal; font-size: 25px">$title </h1>
            </td>
            <td width="50%" align="right"><br>$logo</td>
        </tr>
        </table>
        EOT;
    }

    private function GenerateCharts(int $id, bool $dynamic)
    {
        $chart = '<meta xmlns:sym="symcon">' . AC_RenderChart(IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0], $id, $this->getStartTime(), 3, 0, false, $dynamic, 800, 500) . '</meta>';
        $example = simplexml_load_string($chart);

        //add text-anchor
        foreach ($example->svg->g as $object) {
            if ($object['class'] == 'y axis') {
                $object->g->addAttribute('text-anchor', 'end');
            }
        }

        //fill the bars
        $object = $example->svg;
        if ($object['class'] == 'bar') {
            $object = $example->svg->g[3]->g[1];
            $object->addAttribute('fill', 'yellowgreen');
        } else {
            //svg -> graphs -> outline
            $object = $example->svg->g[3]->g[1]->g;
            $object->addAttribute('stroke', 'black');
            $object->addAttribute('fill', 'none');

            // svg -> graphs -> background
            $object = $example->svg->g[3]->g[0]->g;
            $object->addAttribute('opacity', '0');
        }

        //transform the grid
        $object = $example->svg->g[0]->line;
        for ($i = 0; $i < count($object); $i++) {
            $node = $object[$i];

            //set the opacity of the vertical lines on 0 (trancparence)
            //"remove" the vertical lines
            if (!empty($node) && $node['class'] == 'gridLine y') {
                $node->addAttribute('opacity', '0');
            }
            if (!empty($node) && $node['class'] == 'gridLine y firstLine') {
                $node->addAttribute('opacity', '0');
            }

            //set the horizontal lines grey
            if (!empty($node) && $node['class'] == 'gridLine x' || $node['class'] == 'gridLine x firstLine') {
                $node->addAttribute('opacity', '.2');
            }
        }

        return $example->asXML();
    }

    private function GenerateHTMLText()
    {
        $data = $this->FetchData();
        if ($data == []) {
            return;
        }

        $title = strtoupper($this->Translate('Behave'));
        $consumption = sprintf($this->Translate('In %s you used up %s.'), $data['month'], $data['consumption']) . ' ';
        if ($data['consumptionLastYear'] !== false) {
            $consumption .= $data['consumptionLastYear'];
        }

        if ($data['prediction'] != '') {
            $predictionText = $this->Translate('Expected usage based on your behavior') . ': ' . $data['prediction'] . ' <br> ';
        } else {
            $predictionText = '';
        }

        $valueText = $this->Translate('Actual usage') . ': ' . $data['consumption'] . ' <br><br> ';

        if ($data['percent']) {
            $consumptionText = $this->Translate("You could $data[percentText] your consumption by %s in the period");
            $consumptionText2 = sprintf($consumptionText, $data['percent']);
        } else {
            $consumptionText = '';
            $consumptionText2 = '';
        }

        $text =
        <<<EOT
        <p>$consumption </p>
        <br>
        EOT;
        $customTextTopID = $this->ReadPropertyInteger('CustomTextIDTop');
        if ($customTextTopID > 1) {
            $text .= GetValue($customTextTopID);
        }
        $text .=
        <<<EOT
        <h2 style="font-weight: normal; font-size: 25px">$title</h2>
        <hr style="height: 5px">
        <br> </br>
        <h3>$data[month]</h3>
            <p>
            $data[avgTemp] <br>
            $predictionText
            $valueText
            $consumptionText2
            </p>
        EOT;

        $comparison = $this->Translate('For Comparison');
        $co2text = $this->Translate('A tree bind each month ca. 1 kg CO².<br>In order to achieve the 2030 climate targets, the total energy consumption for heating and hot water must be reduced by <br>5.5% per year.<br>Your personal footprint can be found <a href = "uba.co2-rechner.de" >here </a> calculate.');

        if ($data['co2'] > 0) {
            $text .=
            <<<EOT
            <h3>CO² Emmisionen</h3> 
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td width="20%"><p>    $data[co2] kg</p></td>
                    <td width="80%"><h4>$comparison</h4><p>$co2text</p></td>
                </tr>
            </table>
            EOT;
        }
        $customTextID = $this->ReadPropertyInteger('CustomTextID');
        if ($customTextID > 1) {
            $text .= GetValue($customTextID);
        }
        return $text;
    }

    private function FetchData()
    {
        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        //Get Times
        $startTime = $this->getStartTime();
        $endTime = $this->getEndTime();
        $month = $this->Translate(date('F', $startTime));

        //Consumption last month
        $counterID = $this->ReadPropertyInteger('CounterID');
        $consumption = AC_GetAggregatedValues($archivID, $counterID, 3, $startTime, $endTime - 1, 1);
        if (count($consumption) != 0) {
            $consumption = $consumption[0]['Avg'];
            $this->SetStatus(102);
        } else {
            $this->setStatus(200); //No Data for last month;
            return [];
        }
        //Consumption last Year
        $consumptionLastYear = AC_GetAggregatedValues($archivID, $counterID, 3, strtotime('-12 month', $startTime), strtotime('-12 month', $endTime) - 1, 1);
        if (count($consumptionLastYear) != 0) {
            $consumptionLastYear = $consumptionLastYear[0]['Avg'];
        } else {
            $consumptionLastYear = false;
        }

        //Average Temperature
        $temperatureID = $this->ReadPropertyInteger('TemperatureID');
        if (IPS_VariableExists($temperatureID)) {
            $avgTemp = AC_GetAggregatedValues($archivID, $temperatureID, 3, $startTime, $endTime, 0);
            if (count($avgTemp) != 0) {
                $avgTemp = $avgTemp[0]['Avg'];
                $avgTemp = $this->Translate('The average temperature was') . ': ' . GetValueFormattedEx($temperatureID, $avgTemp);
            } else {
                $this->setStatus(200);
                return [];
            }
        } else {
            $avgTemp = '';
        }

        //Prediction and Prozent
        $predictionID = $this->ReadPropertyInteger('PredictionID');
        if (IPS_VariableExists($predictionID)) {
            $prediction = GetValue($predictionID);

            $percent = 100 - round(($consumption / $prediction) * 100, 2);

            if ($percent >= 0) {
                $percentText = $this->Translate('reduce');
            } else {
                $percentText = $this->Translate('raise');
            }
            $percent = abs($percent);

            $percent = $percent . '%';
            $prediction = GetValueFormattedEx($predictionID, $prediction);
        } else {
            $percent = '';
            $prediction = '';
            $percentText = '';
        }

        $co2 = ($consumption * $this->ReadPropertyInteger('CO2Type')) / 1000;

        if ($consumptionLastYear !== false) {
            $consumptionLastYear = sprintf($this->Translate('Im Vorjahr hatten Sie einen Verbrauch von %s.'), GetValueFormattedEx($counterID, $consumptionLastYear));
        } else {
            $consumptionLastYear = '';
        }
        if ($consumption !== false) {
            $consumption = GetValueFormattedEx($counterID, $consumption);
        }

        //Format all values if with comma
        if ($this->ReadPropertyString('DecimalSeparator') == ',') {
            $consumption = str_replace('.', ',', $consumption);
            $consumptionLastYear = str_replace('.', ',', $consumptionLastYear);
            $avgTemp = str_replace('.', ',', $avgTemp);
            $prediction = str_replace('.', ',', $prediction);
            $percent = str_replace('.', ',', $percent);
            $co2 = str_replace('.', ',', '' . $co2);
        }

        $data = [
            'month'               => $month,
            'consumption'         => $consumption,
            'consumptionLastYear' => $consumptionLastYear,
            'avgTemp'             => $avgTemp,
            'prediction'          => $prediction,
            'percent'             => $percent,
            'percentText'         => $percentText,
            'co2'                 => $co2
        ];

        return $data;
    }

    private function timeStampToDateObject(int $timestamp)
    {
        return json_encode([
            'day'   => date('d', $timestamp),
            'month' => date('n', $timestamp),
            'year'  => date('Y', $timestamp),
        ]);
    }

    private function jsonStringToTimeStamp(string $string)
    {
        $json = json_decode($string, true);
        return mktime(0, 0, 0, intval($json['month']), intval($json['day']), intval($json['year']));
    }

    private function getStartTime()
    {
        return $this->jsonStringToTimeStamp($this->ReadPropertySTring('StartTime'));
    }

    private function getEndTime()
    {
        return $this->jsonStringToTimeStamp($this->ReadPropertySTring('EndTime'));
    }
}
