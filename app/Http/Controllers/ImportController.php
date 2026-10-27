<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Import en deux temps. Entre l'analyse et la confirmation, le fichier reste dans
 * storage/app/private/imports sous un nom aléatoire. Il est supprimé à la confirmation,
 * à l'annulation, ou après 24 h.
 */
class ImportController extends Controller
{
    public function __construct(private readonly CsvImporter $importer) {}

    public function analyze(Request $request, Campaign $campaign): View|RedirectResponse
    {
        $request->validate(
            [
                'file' => ['required', 'file', 'extensions:csv,txt', 'max:'.config('slowmail.import.max_kilobytes')],
                'email_column' => ['nullable', 'string', 'max:100'],
            ],
            [
                'file.required' => 'Choisissez un fichier CSV.',
                'file.file' => 'Le fichier n\'a pas pu être envoyé.',
                'file.extensions' => 'Le fichier doit être un .csv.',
                'file.max' => 'Le fichier est trop gros.',
            ],
        );

        $this->pruneStale();

        $emailColumn = trim((string) $request->input('email_column')) ?: 'EMAIL';
        $token = strtolower((string) Str::ulid());
        $stored = $request->file('file')->storeAs(config('slowmail.import.directory'), $token.'.csv', 'local');

        try {
            $analysis = $this->importer->analyze($campaign, $this->disk()->path($stored), $emailColumn);
        } catch (ImportException $e) {
            $this->disk()->delete($stored);

            return back()->withInput($request->except('file'))->withErrors(['file' => $e->getMessage()]);
        }

        // Rien à confirmer : on ne garde pas le fichier.
        if ($analysis->importable === [] && $analysis->contacted === []) {
            $this->disk()->delete($stored);
            $token = null;
        }

        return view('imports.report', [
            'campaign' => $campaign,
            'analysis' => $analysis,
            'token' => $token,
            'emailColumn' => $emailColumn,
        ]);
    }

    public function confirm(Request $request, Campaign $campaign, string $token): RedirectResponse
    {
        $path = $this->pathFor($token);

        abort_unless($this->disk()->exists($path), 404, 'Ce fichier n\'est plus disponible : recommencez l\'import.');

        $emailColumn = trim((string) $request->input('email_column')) ?: 'EMAIL';
        $includeContacted = $request->boolean('include_contacted');

        try {
            $analysis = $this->importer->analyze($campaign, $this->disk()->path($path), $emailColumn);
        } catch (ImportException $e) {
            $this->disk()->delete($path);

            return redirect()->route('campaigns.show', $campaign)->withErrors(['file' => $e->getMessage()]);
        }

        $imported = $this->importer->commit($campaign, $analysis, $includeContacted);
        $this->disk()->delete($path);

        return redirect()->route('campaigns.show', $campaign)->with('import_result', [
            'imported' => $imported,
            'rejected' => array_slice($analysis->rejected, 0, 200),
            'rejected_total' => count($analysis->rejected),
            'contacted' => count($analysis->contacted),
            'contacted_included' => $includeContacted,
            'warnings' => $analysis->warnings,
        ]);
    }

    public function cancel(Campaign $campaign, string $token): RedirectResponse
    {
        $this->disk()->delete($this->pathFor($token));

        return redirect()->route('campaigns.show', $campaign)->with('status', 'Import annulé, le fichier a été supprimé.');
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    private function pathFor(string $token): string
    {
        // Le jeton est un ULID en minuscules : rien d'autre ne peut former un chemin.
        abort_unless(preg_match('/^[0-9a-z]{26}$/', $token) === 1, 404);

        return config('slowmail.import.directory').'/'.$token.'.csv';
    }

    private function pruneStale(): void
    {
        $limit = now()->subHours(config('slowmail.import.stale_after_hours'))->getTimestamp();

        foreach ($this->disk()->files(config('slowmail.import.directory')) as $file) {
            if ($this->disk()->lastModified($file) < $limit) {
                $this->disk()->delete($file);
            }
        }
    }
}
