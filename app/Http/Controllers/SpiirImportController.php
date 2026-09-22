<?php

namespace App\Http\Controllers;

use App\Finance\SpiirExportImporter;
use App\Jobs\ImportSpiirExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpiirImportController extends Controller
{
    public function store(Request $request, SpiirExportImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'categories' => ['required', 'file', 'max:10240'],
            'user' => ['required', 'file', 'max:1024'],
            'accounts' => ['required', 'file', 'max:10240'],
            'postings' => ['required', 'file', 'max:51200'],
        ]);

        if (! $importer->canImport()) {
            throw ValidationException::withMessages([
                'postings' => 'A Spiir import can only run while there are no postings.',
            ]);
        }

        $importId = (string) Str::uuid();
        $directory = "spiir-imports/{$importId}";

        Storage::disk('local')->putFileAs($directory, $data['categories'], 'Categories.json');
        Storage::disk('local')->putFileAs($directory, $data['user'], 'User.csv');
        Storage::disk('local')->putFileAs($directory, $data['accounts'], 'Account.csv');
        Storage::disk('local')->putFileAs($directory, $data['postings'], 'Posting.csv');

        Cache::put(
            ImportSpiirExport::cacheKey($importId),
            ['status' => 'pending', 'user_id' => $request->user()->id],
            now()->addDay(),
        );

        ImportSpiirExport::dispatch($importId, $request->user()->id);

        $request->session()->put('spiir_import_id', $importId);

        return back();
    }

    public function show(string $import): JsonResponse
    {
        $status = Cache::get(ImportSpiirExport::cacheKey($import));

        abort_if($status === null, 404);

        return response()->json([
            'status' => $status['status'] ?? 'pending',
            'summary' => $status['summary'] ?? null,
            'error' => $status['error'] ?? null,
        ]);
    }

    /**
     * The last import status remembered in the session, for pre-filling the
     * import panels on the onboarding step and the settings page.
     *
     * @return array{status: string, summary?: array<string, mixed>, error?: string}|null
     */
    public static function sessionImportStatus(Request $request): ?array
    {
        $importId = $request->session()->get('spiir_import_id');

        if ($importId === null) {
            return null;
        }

        return Cache::get(ImportSpiirExport::cacheKey($importId));
    }
}
