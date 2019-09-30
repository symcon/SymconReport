<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class SymconReportValidationTest extends TestCaseSymconValidation
{
    public function testValidateSymconReport(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateMailReportModule(): void
    {
        $this->validateModule(__DIR__ . '/../MailReport');
    }
    public function testValidatePDFReportModule(): void
    {
        $this->validateModule(__DIR__ . '/../PDFReport');
    }
}