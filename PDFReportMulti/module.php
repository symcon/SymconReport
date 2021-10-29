<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';

class PDFReportMulti extends IPSModule
{
    public function Create()
    {

        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('LogoData', '');
        $this->RegisterPropertyString('CompanyName', '');
        $this->RegisterPropertyString('ReportTitle', '');
        $this->RegisterPropertyString('ReportFooter', '');
        $this->RegisterPropertyString('DataVariables', '[]');
        $this->RegisterPropertyInteger('DataAggregation', 1);
        $this->RegisterPropertyInteger('DataCount', 7);
        $this->RegisterPropertyBoolean('DataSkipFirst', true);

        $this->RegisterMediaDocument('ReportPDF', $this->Translate('Report (PDF)'), 'pdf');
    }

    private function RegisterMediaDocument($Ident, $Name, $Extension, $Position = 0)
    {
        $this->RegisterMedia(5, $Ident, $Name, $Extension, $Position);
    }

    private function RegisterMedia($Type, $Ident, $Name, $Extension, $Position)
    {
        $mid = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($mid === false) {
            $mid = IPS_CreateMedia(5 /* Document */);
            IPS_SetParent($mid, $this->InstanceID);
            IPS_SetIdent($mid, $Ident);
            IPS_SetName($mid, $Name);
            IPS_SetPosition($mid, $Position);
            IPS_SetMediaFile($mid, 'media/' . $mid . '.' . $Extension, false);
        }
    }

    private function FetchData()
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

        $startTime = 0;
        $endTime = 0;
        $dataCount = $this->ReadPropertyInteger('DataCount');

        /*
         *
                    //This is also possible. The currently used mechanism is simpler

                    switch($this->ReadPropertyInteger("DataAggregation")) {
                        case 0: //Hour
                            $startTime = strtotime("-" . $this->ReadPropertyInteger("DataCount") . " hours");
                            break;
                        case 1: //Day
                            $startTime = strtotime("-" . $this->ReadPropertyInteger("DataCount") . " days");
                            break;
                        case 2: //Week
                            $startTime = strtotime("-" . $this->ReadPropertyInteger("DataCount") . " weeks");
                            break;
                        case 3: //Month
                            $startTime = strtotime("-" . $this->ReadPropertyInteger("DataCount") . " months");
                            break;
                        case 4: //Year
                            $startTime = strtotime("-" . $this->ReadPropertyInteger("DataCount") . " years");
                            break;
                        default:
                            throw new Exception("Invalid aggregation type");
                    }
                    $endTime = time();
                    $dataCount = 0;

         */

        if ($this->ReadPropertyBoolean('DataSkipFirst')) {
            $dataCount++;
        }

        $dataValues = [];
        $json = json_decode($this->ReadPropertyString('DataVariables'), true);
        foreach ($json as $data) {
            $values = AC_GetAggregatedValues(
                $archiveID,
                $data["VariableID"],
                $this->ReadPropertyInteger('DataAggregation'),
                $startTime,
                $endTime,
                $dataCount
            );
            
            if ($this->ReadPropertyBoolean('DataSkipFirst')) {
                array_shift($values);
            }

            $dataValues[$data["VariableID"]] = $values;
        }
        
        return $dataValues;
    }

    private function GetDateTimeFormatForAggreagtion()
    {
        $escape = function ($str)
        {
            $strResult = '';
            $strArr = str_split($str);
            foreach ($strArr as $strElem) {
                $strResult .= '\\' . $strElem;
            }
            return $strResult;
        };

        switch ($this->ReadPropertyInteger('DataAggregation')) {
            case 0: //Hour
                return 'd.m.Y H:i';
            case 1: //Day
                return 'd.m.Y';
            case 2: //Week
                return $escape($this->Translate('CW')) . 'W Y';
            case 3: //Month
                return 'M Y';
            case 4: //Year
                return 'Y';
            default:
                throw new Exception('Invalid aggregation type');
        }
    }

    private function GenerateHTMLHeader()
    {
        $imageData = $this->ReadPropertyString('LogoData');
        $company = $this->ReadPropertyString('CompanyName');

        $date = date('d.m.Y');

        return <<<EOT
			
<table cellpadding="5" cellspacing="0" border="0" width="95%">
<tr>
	<td width="50%"><img src="@$imageData"></td>
	<td align="right">
		<br/><br/><br/>
		$company<br/>
		$date
	</td>
</tr>
</table>
EOT;
    }

    private function GenerateHTMLRows()
    {
        // Transform values into a time based array
        $timeValues = [];
        foreach ($this->FetchData() as $id => $values) {
            foreach($values as $value) {
                $timeValues[$value['TimeStamp']][$id] = $value["Avg"];
            }
        }

        $rows = '';
        $json = json_decode($this->ReadPropertyString('DataVariables'), true);
        foreach ($timeValues as $ts => $values) {
            $rows .= '<tr>';
            $rows .= '<td width="25%">' . date($this->GetDateTimeFormatForAggreagtion(), $ts) . '</td>';
            foreach ($json as $data) {
                $rows .= '<td style="text-align: center;">' . GetValueFormattedEx($data["VariableID"], $values[$data["VariableID"]]) . '</td>';
            }
            $rows .= '</tr>';
        }

        return $rows;
    }

    private function GenerateHTML()
    {
        $header = $this->GenerateHTMLHeader();
        $rows = $this->GenerateHTMLRows();
        $footer = $this->ReadPropertyString('ReportFooter');
        $title = $this->ReadPropertyString('ReportTitle');

        //Headings
        $headDate = $this->Translate('Date');
        $headCols = '';
        $json = json_decode($this->ReadPropertyString('DataVariables'), true);
        foreach ($json as $data) {
            $name = $data["Name"] ? $data["Name"] : IPS_GetName($data["VariableID"]); 
            $headCols .= '<td style="text-align: center;"><b>' . $name . '</b></td>';
        }        

        return <<<EOT
$header
<br/>
<h2>$title</h2>
<br/>
<br/>
<br/>
<table cellpadding="5" cellspacing="0" border="0" width="95%">
	<tr style="background-color: #cccccc; padding:5px;">
	   <td style="padding:5px;" width="25%"><b>$headDate</b></td>
	   $headCols
	</tr>
	$rows
	<tr>
		<td colspan="5"><hr/></td>
	</tr>
</table>
<br/>
$footer
EOT;
    }

    private function GeneratePDF($author, $title, $subject, $html, $filename)
    {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($author);
        $pdf->SetTitle($title);
        $pdf->SetSubject($subject);

        $pdf->setPrintHeader(false);

        //$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP - 15, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);

        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        $pdf->SetFont('dejavusans', '', 10);

        $pdf->AddPage();

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output($filename, 'S');
    }

    public function GenerateReport()
    {
        $json = json_decode($this->ReadPropertyString('DataVariables'), true);
        foreach ($json as $data) {
            if ($data["VariableID"] == 0) {
                echo $this->Translate('Selected data source is not a valid variable!');
                return false;
            }
        }

        $pdfContent = $this->GeneratePDF(
            'IP-Symcon ' . IPS_GetKernelVersion(),
            $this->ReadPropertyString('ReportTitle'),
            $this->ReadPropertyString('ReportTitle'),
            $this->GenerateHTML(),
            'report.pdf'
        );

        $mediaID = $this->GetIDForIdent('ReportPDF');
        IPS_SetMediaContent($mediaID, base64_encode($pdfContent));

        return true;
    }
}