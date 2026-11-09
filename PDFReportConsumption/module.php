<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';

class PDFReportConsumption extends IPSModule
{
    private const COLORS = ['#9ACD32', '#4682B4', '#FF8C00', '#8B008B', '#20B2AA', '#DC143C', '#808000', '#1E90FF'];

    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        //Properties
        $this->RegisterPropertyString('LogoData', '');
        $this->RegisterPropertyString('EnergyVariables', '[]');
        $this->RegisterPropertyInteger('SMTP', 0);
        $this->RegisterPropertyInteger('DifferentReciever', 0);

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

        $this->RegisterScript('Generate', $this->Translate('Generate'), "<? RAC_GenerateReport(IPS_GetParent(\$_IPS['SELF']));", 3);
        $this->RegisterMedia(5, 'ReportPDF', $this->Translate('Report (PDF)'), 'pdf', 4);
        $this->RegisterMedia(4, 'MediaChart', $this->Translate('Chart'), 'chart', 5);

    }

    public function GenerateReport(): bool
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
        $this->SetValue($Ident, intval($Value));
    }

    public function ApplyChanges()
    {

        // The chart is not used for the PDF, but allows to view the counters in the visualization
        // Keep the datasets of existing counters (e.g. changed colors) and add new counters with the colors of the PDF
        $chartID = $this->GetIDForIdent('MediaChart');
        $currentDatasets = [];
        $content = @IPS_GetMediaContent($chartID);
        if ($content !== false && $content !== '') {
            $currentChart = json_decode(base64_decode($content), true);
            if (isset($currentChart['datasets'])) {
                $currentDatasets = array_column($currentChart['datasets'], null, 'variableID');
            }
        }
        $datasets = [];
        foreach (json_decode($this->ReadPropertyString('EnergyVariables'), true) as $index => $variable) {
            $variableID = $variable['CounterVariable'];
            if (isset($currentDatasets[$variableID])) {
                $datasets[] = $currentDatasets[$variableID];
            } else {
                $color = self::COLORS[$index % count(self::COLORS)];
                $datasets[] = [
                    'variableID'  => $variableID,
                    'fillColor'   => $color,
                    'strokeColor' => $color,
                    'timeOffset'  => 0,
                    'visible'     => true,
                    'side'        => 'left',
                ];
            }
        }
        IPS_SetMediaContent($chartID, base64_encode(json_encode(['datasets' => $datasets])));

        $refs = $this->GetReferenceList();
        foreach ($refs as $ref) {
            $this->UnregisterReference($ref);
        }
        // Register reference of the counter and the reciever and the instance
    }

    public function SendReport(string $topic, string $body): bool
    {
        $smtp = $this->ReadPropertyInteger('SMTP');
        if (!IPS_InstanceExists($smtp)) {
            $this->SendDebug('SMTP', "Don't exist", 0);
            return false;
        }
        if ($this->GenerateReport()) {
            $reciever = $this->ReadPropertyInteger('DifferentReciever');
            if (IPS_VariableExists($reciever)) {
                $reciever = GetValue($reciever);
                if (filter_var($reciever, FILTER_VALIDATE_EMAIL) === false) {
                    $reciever = false;
                }
            }
            else {
                $reciever = false;
            }
            if ($reciever) {
                return SMTP_SendMailMediaEx($smtp, $reciever, $topic, $body, $this->GetIDForIdent('ReportPDF'));
            }
            else {
                return SMTP_SendMailMedia($smtp, $topic, $body, $this->GetIDForIdent('ReportPDF'));
            }
        }
        $this->SendDebug('SendReport', 'Something is wrong', 0);
    }

    private function RegisterMediaDocument($Ident, $Name, $Extension, $Position = 0): void
    {
        $this->RegisterMedia(5, $Ident, $Name, $Extension, $Position);
    }

    private function RegisterMedia($Type, $Ident, $Name, $Extension, $Position): void
    {
        $mediaId = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($mediaId === false) {

            $mediaId = IPS_CreateMedia($Type);
            switch ($Type) {
                case 5: // Document
                    IPS_SetMediaFile($mediaId, 'media/' . $mediaId . '.' . $Extension, false);
                    break;
                case 4://Chart
                    IPS_SetMediaFile($mediaId, 'media/' . $mediaId . '.' . $Extension, false);
                    break;
                default:
                    # code...
                    break;
            }
            IPS_SetParent($mediaId, $this->InstanceID);
            IPS_SetIdent($mediaId, $Ident);
            IPS_SetName($mediaId, $Name);
            IPS_SetPosition($mediaId, $Position);
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
        $logo = $this->ReadPropertyString('LogoData');
        if (strpos(base64_decode($logo), '<svg') !== false) {
            $logo = base64_decode($logo);
            $pdf->ImageSVG('@' . $logo, $x = 150, $y = 0, $w = 50, $h = 50, $border = 1);
            $logo = '';
        } elseif ($logo != '') {
            $logo = '<img src="@' . $logo . '">';
        }

        $pdf->writeHTML($this->GenerateHTMLHeader($logo), true, false, true, false, '');

        //Text and chart are based on the same data to match each other
        $data = $this->FetchData(json_decode($this->ReadPropertyString('EnergyVariables'), true));

        //Text
        $pdf->writeHTML($this->GenerateHTMLText($data), true, false, true, false, '');

        //Chart
        $svg = $this->GenerateChart($data);
        $pdf->ImageSVG('@' . $svg, $x = 10, $pdf->GetY(), $w = 180, $h = '', $link = '', $align = '', $palign = '', $border = 0, $fitonpage = true);

        //Save the pdf
        return $pdf->Output($filename, 'S');
    }

    private function GenerateHTMLHeader($logo)
    {
        $title = mb_strtoupper($this->Translate('Consumption'));
        //Get Times
        [$startTime, $endTime] = $this->GetTimeRange();
        // The end is exclusive, so display the last day of the range
        $timestamps = $this->Translate('From') . ' ' . date($this->Translate('m/d/Y'), $startTime) . ' ' . $this->Translate('to') . ' ' . date($this->Translate('m/d/Y'), $endTime - 1);

        return <<<EOT
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
                <td width="75%">
                    <br/><br/><br/>
                    <h1 style="font-weight: normal; font-size: 25px">$title</h1>
                    <p>$timestamps</p>
                </td>
                <td width="25%" align="right"><br>$logo</td>
            </tr>
            </table>
            <br/>
            <hr>
            EOT;
    }

    private function GenerateHTMLText(array $data): string
    {
        $dataText = '';
        foreach ($data['Variables'] as $key => $variable) {
            $name = htmlspecialchars($variable['Name']);
            $consumption = GetValueFormattedEx($data['FormatVariableID'], $variable['Consumption']);
            $this->SendDebug('Consumption', $consumption, 0);
            $this->SendDebug('percentage', $variable['Percentage'], 0);

            $dataText .= <<<EOT
            $name : $consumption<br />
            EOT;
        }
        $totalConsumption = GetValueFormattedEx($data['FormatVariableID'], $data['Total']);
        $textTotalConsumption = $this->Translate('Total Consumption');

        return <<<EOT
        <br>
        <p>$dataText</p>
        <h3>$textTotalConsumption: $totalConsumption</h3>
        EOT;
    }

    private function GenerateChart(array $data): string
    {
        $width = 800;
        $height = 500;
        $colors = self::COLORS;
        $font = 'font-family="dejavusans"';
        $variables = $data['Variables'];
        $labels = $data['Labels'];

        //Layout: the legend is placed below the chart with 3 entries per row
        $legendRows = intval(ceil(count($variables) / 3));
        $plotRight = $width - 20;
        $plotTop = 30;
        $plotBottom = $height - 50 - ($legendRows * 20);

        //Stack the values of all variables (positive values upwards, negative values downwards)
        $maxValue = 0;
        $minValue = 0;
        foreach (array_keys($labels) as $key) {
            $positive = 0;
            $negative = 0;
            foreach ($variables as $variable) {
                if ($variable['Values'][$key] >= 0) {
                    $positive += $variable['Values'][$key];
                } else {
                    $negative += $variable['Values'][$key];
                }
            }
            $maxValue = max($maxValue, $positive);
            $minValue = min($minValue, $negative);
        }
        if ($maxValue == $minValue) {
            $maxValue = 1;
        }

        //Calculate the y axis
        $step = $this->GetAxisStep($maxValue - $minValue);
        $decimals = $step < 1 ? intval(ceil(-log10($step))) : 0;
        $axisMax = ceil($maxValue / $step) * $step;
        $axisMin = floor($minValue / $step) * $step;
        $formatVariableID = $data['FormatVariableID'];
        //The length of the formatted values depends on the variable, so the plot starts after the longest label
        $plotLeft = max(80, 16 + (8 * max(mb_strlen(GetValueFormattedEx($formatVariableID, $axisMin)), mb_strlen(GetValueFormattedEx($formatVariableID, $axisMax)))));
        $getY = function ($value) use ($axisMin, $axisMax, $plotTop, $plotBottom)
        {
            return $plotBottom - (($value - $axisMin) / ($axisMax - $axisMin)) * ($plotBottom - $plotTop);
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';

        //Grid and y axis labels
        for ($value = $axisMin; $value <= $axisMax + ($step / 2); $value += $step) {
            $y = round($getY($value), 2);
            $svg .= '<line x1="' . $plotLeft . '" y1="' . $y . '" x2="' . $plotRight . '" y2="' . $y . '" stroke="#CCCCCC" stroke-width="1"/>';
            $svg .= '<text x="' . ($plotLeft - 8) . '" y="' . ($y + 4) . '" ' . $font . ' font-size="12" text-anchor="end">' . htmlspecialchars(GetValueFormattedEx($formatVariableID, round($value, $decimals)), ENT_XML1) . '</text>';
        }

        //Bars
        $slotWidth = ($plotRight - $plotLeft) / max(count($labels), 1);
        $barWidth = round($slotWidth * 0.7, 2);
        $labelStep = intval(ceil(count($labels) / 16));
        $index = 0;
        foreach ($labels as $key => $label) {
            $x = round($plotLeft + ($index * $slotWidth) + ($slotWidth * 0.15), 2);
            $positive = 0;
            $negative = 0;
            foreach ($variables as $variableIndex => $variable) {
                $value = $variable['Values'][$key];
                if ($value == 0) {
                    continue;
                }
                if ($value > 0) {
                    $top = $getY($positive + $value);
                    $bottom = $getY($positive);
                    $positive += $value;
                } else {
                    $top = $getY($negative);
                    $bottom = $getY($negative + $value);
                    $negative += $value;
                }
                $svg .= '<rect x="' . $x . '" y="' . round($top, 2) . '" width="' . $barWidth . '" height="' . round($bottom - $top, 2) . '" fill="' . $colors[$variableIndex % count($colors)] . '"/>';
            }

            //Reduce the labels if there are too many bars
            if (($index % $labelStep) == 0) {
                $svg .= '<text x="' . round($x + ($barWidth / 2), 2) . '" y="' . ($plotBottom + 18) . '" ' . $font . ' font-size="11" text-anchor="middle">' . $label . '</text>';
            }
            $index++;
        }

        //Zero line
        $y = round($getY(0), 2);
        $svg .= '<line x1="' . $plotLeft . '" y1="' . $y . '" x2="' . $plotRight . '" y2="' . $y . '" stroke="#000000" stroke-width="1"/>';

        //Legend
        foreach ($variables as $variableIndex => $variable) {
            $x = $plotLeft + (($variableIndex % 3) * 230);
            $y = $plotBottom + 45 + (intval(floor($variableIndex / 3)) * 20);
            $svg .= '<rect x="' . $x . '" y="' . ($y - 10) . '" width="12" height="12" fill="' . $colors[$variableIndex % count($colors)] . '"/>';
            $svg .= '<text x="' . ($x + 18) . '" y="' . $y . '" ' . $font . ' font-size="12">' . htmlspecialchars($variable['Name'], ENT_XML1) . '</text>';
        }

        return $svg . '</svg>';
    }

    /**
     * Returns a step size of 1, 2 or 5 multiplied by a power of ten, which results in about 5 grid lines
     */
    private function GetAxisStep(float $range): float
    {
        $roughStep = $range / 5;
        $magnitude = pow(10, floor(log10($roughStep)));
        foreach ([1, 2, 5] as $factor) {
            if (($factor * $magnitude) >= $roughStep) {
                return $factor * $magnitude;
            }
        }
        return 10 * $magnitude;
    }

    /**
     * Returns the selected time range [start, end) from 00:00:00 of the start day until 00:00:00 of the day after the end day
     */
    private function GetTimeRange(): array
    {
        $startTime = $this->GetValue('Start');
        $endTime = $this->GetValue('End');
        if ($startTime > $endTime) {
            [$startTime, $endTime] = [$endTime, $startTime];
        }
        return [strtotime('00:00:00', $startTime), strtotime('+1 day', strtotime('00:00:00', $endTime))];
    }

    /**
     * Returns how the values are grouped into the bars of the chart depending on the length of the time range
     * Level: aggregation level for AC_GetAggregatedValues, Step: length of these periods,
     * Key: date format to group the periods into bars, Label: date format of the bar labels
     */
    private function GetBarInterval(int $startTime, int $endTime): array
    {
        $days = round(($endTime - $startTime) / (60 * 60 * 24));
        if ($days <= 1) {
            return ['Level' => 0, 'Step' => '+1 hour', 'Key' => 'Y-m-d H', 'Label' => 'H:i'];
        } elseif ($days <= 62) {
            return ['Level' => 1, 'Step' => '+1 day', 'Key' => 'Y-m-d', 'Label' => $this->Translate('m/d')];
        } elseif ($days <= 731) {
            return ['Level' => 1, 'Step' => '+1 day', 'Key' => 'Y-m', 'Label' => $this->Translate('m/Y')];
        }
        return ['Level' => 1, 'Step' => '+1 day', 'Key' => 'Y', 'Label' => 'Y'];
    }

    private function FetchData(array $variables): array
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        [$startTime, $endTime] = $this->GetTimeRange();
        $this->SendDebug('Time range', date('d.m.Y H:i', $startTime) . ' - ' . date('d.m.Y H:i', $endTime), 0);

        //Create the bars of the chart
        //Bars are grouped from whole hours/days, so they don't exceed the time range (e.g. the first month starts on the start day)
        $interval = $this->GetBarInterval($startTime, $endTime);
        $labels = [];
        for ($time = $startTime; $time < $endTime; $time = strtotime($interval['Step'], $time)) {
            $key = date($interval['Key'], $time);
            if (!isset($labels[$key])) {
                $labels[$key] = date($interval['Label'], $time);
            }
        }

        $data = [];
        $totalConsumption = 0;

        //Get the consumption
        foreach ($variables as $variable) {
            $name = $variable['Name'] == '' ? IPS_GetName($variable['CounterVariable']) : $variable['Name'];
            $variable = $variable['CounterVariable'];
            //AC_GetAggregatedValues returns all periods starting within [start, end], so the period starting at the end has to be excluded
            $aggregatedValues = AC_GetAggregatedValues($archiveID, $variable, $interval['Level'], $startTime, $endTime - 1, 0);
            $this->SendDebug('Values of' . $variable, print_r($aggregatedValues, true), 0);

            $barValues = array_fill_keys(array_keys($labels), 0);
            foreach ($aggregatedValues as $aggregatedValue) {
                $key = date($interval['Key'], $aggregatedValue['TimeStamp']);
                if (isset($barValues[$key])) {
                    $barValues[$key] += $aggregatedValue['Avg'];
                }
            }

            $consumption = round(array_sum($barValues), 2);
            $totalConsumption += $consumption;
            $data[] = [
                'Name'        => $name,
                'Consumption' => $consumption,
                'Percentage'  => 0,
                'Values'      => $barValues
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
        $values['Labels'] = $labels;
        //All values of the report are formatted based on the first counter
        $values['FormatVariableID'] = $variables[0]['CounterVariable'];

        return $values;
    }
}
