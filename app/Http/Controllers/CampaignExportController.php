<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Export\CsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignExportController extends Controller
{
    public function __invoke(Campaign $campaign, CsvExporter $exporter): StreamedResponse
    {
        return response()->streamDownload(function () use ($exporter, $campaign) {
            $out = fopen('php://output', 'w');
            $exporter->write($out, $campaign);
            fclose($out);
        }, $exporter->filename($campaign), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Des adresses et des notes : rien ne doit rester dans un cache.
            'Cache-Control' => 'no-store',
        ]);
    }
}
