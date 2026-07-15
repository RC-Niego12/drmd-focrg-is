<?php

namespace App\Http\Controllers;

use App\Models\StandbyFund;
use App\Services\AuditLogger;
use App\Services\StandbyFundSheetSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StandbyFundController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('StandbyFunds/Index', [
            'standbyFund' => StandbyFund::current()->fresh('updater:id,name'),
            'defaultSheetUrl' => config('services.google_sheets.standby_fund_url'),
            'defaultCell' => config('services.google_sheets.standby_fund_cell', 'L2'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:255'],
            'google_sheet_url' => ['nullable', 'url'],
            'cell_reference' => ['nullable', 'string', 'max:10'],
        ]);

        $standbyFund = StandbyFund::current();
        $old = $standbyFund->toArray();

        $standbyFund->update([
            'amount' => $validated['amount'],
            'source' => $validated['source'] ?: 'Manual update',
            'google_sheet_url' => $validated['google_sheet_url'] ?? $standbyFund->google_sheet_url,
            'cell_reference' => strtoupper($validated['cell_reference'] ?: $standbyFund->cell_reference ?: 'L2'),
            'updated_by' => $request->user()->id,
        ]);

        $audit->log('standby_fund.updated', $standbyFund, $old, $standbyFund->fresh()->toArray());

        return back()->with('success', 'Standby fund updated successfully.');
    }

    public function sync(Request $request, StandbyFundSheetSyncService $syncService, AuditLogger $audit): RedirectResponse
    {
        $standbyFund = StandbyFund::current();
        $url = $request->input('google_sheet_url') ?: config('services.google_sheets.standby_fund_url') ?: $standbyFund->google_sheet_url;
        $cell = strtoupper($request->input('cell_reference') ?: config('services.google_sheets.standby_fund_cell', 'L2'));

        if (! $url) {
            return back()->with('error', 'Standby fund Google Sheet URL is not configured.');
        }

        try {
            $amount = $syncService->fetchAmount($url, $cell);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Standby fund sync failed.');
        }

        $old = $standbyFund->toArray();
        $standbyFund->update([
            'amount' => $amount,
            'source' => "Synced from WIT {$cell}",
            'google_sheet_url' => $url,
            'cell_reference' => $cell,
            'synced_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $audit->log('standby_fund.synced', $standbyFund, $old, $standbyFund->fresh()->toArray());

        return back()->with('success', 'Standby fund synced successfully.');
    }
}
