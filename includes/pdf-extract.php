<?php
require_once __DIR__ . '/Smalot/alt_autoload.php';
use Smalot\PdfParser\Parser;

if (!function_exists('extract_text_from_pdf')) {
    function extract_text_from_pdf($filename) {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($filename);
            return $pdf->getText();
        } catch (Exception $e) {
            return false;
        }
    }
}
