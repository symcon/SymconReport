<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';

class PDFReportSingle extends IPSModule
{
    public function Create()
    {

        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('LogoData', '');
        $this->RegisterPropertyString('CompanyName', '');
        $this->RegisterPropertyString('ReportTitle', '');
        $this->RegisterPropertyString('ReportFooter', '');
        $this->RegisterPropertyInteger('DataVariable', 0);
        $this->RegisterPropertyInteger('DataAggregation', 1);
        $this->RegisterPropertyInteger('DataCount', 7);
        $this->RegisterPropertyBoolean('DataSkipFirst', true);
        $this->RegisterPropertyFloat('DataLimitMin', 0);
        $this->RegisterPropertyFloat('DataLimitMax', 0);

        $this->RegisterMediaDocument('ReportPDF', $this->Translate('Report (PDF)'), 'pdf');
    }

    public function GenerateReport()
    {
        if ($this->ReadPropertyInteger('DataVariable') == 0) {
            echo $this->Translate('Selected data source is not a valid variable!');
            return false;
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

        $dataValues = AC_GetAggregatedValues(
            $archiveID,
            $this->ReadPropertyInteger('DataVariable'),
            $this->ReadPropertyInteger('DataAggregation'),
            $startTime,
            $endTime,
            $dataCount
        );

        if ($this->ReadPropertyBoolean('DataSkipFirst')) {
            array_shift($dataValues);
        }

        return $dataValues;
    }

    private function CheckDataLimit($min, $max)
    {
        $limitMin = $this->ReadPropertyFloat('DataLimitMin');
        $limitMax = $this->ReadPropertyFloat('DataLimitMax');

        if ($limitMin == $limitMax) {
            return 'OK';
        }

        if ($min < $limitMin && $max > $limitMax) {
            return $this->Translate('<font color="red">' . $this->Translate('Error Min/Max') . '</font>');
        }

        if ($min < $limitMin) {
            return $this->Translate('<font color="red">' . $this->Translate('Error Min') . '</font>');
        }

        if ($max > $limitMax) {
            return $this->Translate('<font color="red">' . $this->Translate('Error Max') . '</font>');
        }

        return $this->Translate('OK');
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
        $variableID = $this->ReadPropertyInteger('DataVariable');

        $rows = '';
        foreach ($this->FetchData() as $data) {
            $date = date($this->GetDateTimeFormatForAggreagtion(), $data['TimeStamp']);
            $min = GetValueFormattedEx($variableID, $data['Min']);
            $max = GetValueFormattedEx($variableID, $data['Max']);
            $avg = GetValueFormattedEx($variableID, $data['Avg']);

            $status = $this->CheckDataLimit($data['Min'], $data['Max']);

            $rows .= <<<EOT
<tr>
	<td width="25%">$date</td>
	<td style="text-align: center;">$min</td>                  
	<td style="text-align: center;">$max</td>   
	<td style="text-align: center;">$avg</td>
	<td style="text-align: center;">$status</td>
</tr>
EOT;
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
        $headMin = $this->Translate('Min');
        $headMax = $this->Translate('Max');
        if (AC_GetAggregationType(
            IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0],
            $this->ReadPropertyInteger('DataVariable')
        ) == 0) { //default aggregation
            $headAvg = $this->Translate('Avg');
        } else { //counter aggregation
            $headAvg = $this->Translate('Consumption');
        }
        $headLimit = $this->Translate('Limit');

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
	   <td style="text-align: center;"><b>$headMin</b></td>
	   <td style="text-align: center;"><b>$headMax</b></td>
	   <td style="text-align: center;"><b>$headAvg</b></td>
	   <td style="text-align: center;"><b>$headLimit</b></td>
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
}