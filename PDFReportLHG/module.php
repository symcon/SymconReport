<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';

class PDFReportLHG extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        //Properties
        $this->RegisterPropertyString('LogoData', '');
        $this->RegisterPropertyInteger('AggregationLevel', 1);
        $this->RegisterPropertyString('DecimalSeparator', ',');
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

        $this->RegisterScript('Generate', 'Generate', "<? RAC_GenerateReport(IPS_GetParent(\$_IPS['SELF']));", 3);
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

        // crate MultiChart and give the id to renderChart
        $dataset = [];
        $variables = json_decode($this->ReadPropertyString('EnergyVariables'), true);
        $this->SendDebug('Variables', print_r($variables, true), 0);
        if (@IPS_GetMediaContent($this->GetIDForIdent('MediaChart')) === false) {
            foreach ($variables as $key => $variable) {

                $dataset[] = [
                    'variableID'  => $variable['CounterVariable'],
                    'fillColor'   => 'clear',
                    'strokeColor' => '#' . dechex(rand(0, 255)) . dechex(rand(0, 255)) . dechex(rand(0, 255)),
                    'timeOffset'  => 0,
                    'visible'     => true,
                    'side'        => 'left',
                ];

            }
        }else {
            $currentMediaChart = json_decode(base64_decode(IPS_GetMediaContent($this->GetIDForIdent('MediaChart'))), true);
            $currentDataset = $currentMediaChart['datasets'];
            foreach ($variables as $key => $variable) {
                if (!in_array($variable, array_column($currentDataset, 'variableID'))) {
                    $dataset[] = [
                        'variableID'  => $variable['CounterVariable'],
                        'fillColor'   => 'clear',
                        'strokeColor' => '#' . dechex(rand(0, 255)) . dechex(rand(0, 255)) . dechex(rand(0, 255)),
                        'timeOffset'  => 0,
                        'visible'     => true,
                        'side'        => 'left',
                    ];
                }
            }
        }
        $id = $this->GetIDForIdent('MediaChart');
        IPS_SetMediaContent($id, base64_encode(json_encode(['datasets' => $dataset])));

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

        //Text
        $pdf->writeHTML($this->GenerateHTMLText(), true, false, true, false, '');

        //Chart
        //Charts
        $svg = $this->GenerateChart();
        $pdf->ImageSVG('@' . $svg, $x = 10, $pdf->GetY(), $w = 180, $h = '', $link = '', $align = '', $palign = '', $border = 0, $fitonpage = true);

        //Save the pdf
        return $pdf->Output($filename, 'S');
    }

    private function GenerateHTMLHeader($logo)
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
            $name : $consumption kWh<br />
            EOT;
        }
        $totalConsumption = $data['Total'];
        $totalConsumption = str_replace('.', $this->ReadPropertyString('DecimalSeparator'), strval($totalConsumption));
        $textCounter = $this->Translate('Counter');
        $textConsumption = $this->Translate('Consumption');
        $textPercentage = $this->Translate('Percentage');
        $textTotalConsumption = $this->Translate('Total Consumption');

        return <<<EOT
        <br>
        <p>$dataText</p>
        <h3>$textTotalConsumption: $totalConsumption kwh</h3>
        EOT;
    }

    private function GenerateChart()
    {

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $this->SendDebug('end', date('d.m.y', strtotime('00:00:00', $this->GetValue('End'))), 0);
        $this->SendDebug('Start', date('d.m.y', strtotime('00:00:00', $this->GetValue('Start'))), 0);

        $chart = '<meta xmlns:sym="symcon">' . AC_RenderChart(
            $archivID,
            $this->GetIDForIdent('MediaChart'),
            strtotime('00:00:00', $this->GetValue('Start')),
            $this->ReadPropertyInteger('AggregationLevel'),
            0,
            false,
            true,
            800,
            500
        ) . '</meta>';
        $example = simplexml_load_string($chart);
        $this->SendDebug('chart', $chart, 0);

        //add text-anchor
        foreach ($example->svg->g as $object) {
            if ($object['class'] == 'y axis') {
                $object->g->addAttribute('text-anchor', 'end');
            }
        }

        //fill the bars
        $object = $example->svg;
        if ($object['class'] == 'bar multi') {
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
            $name = $variable['Name'] == '' ? IPS_GetName($variable['CounterVariable']) : $variable['Name'];
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
                'Name'        => $name,
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