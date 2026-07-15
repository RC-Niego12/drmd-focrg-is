<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\DispatchPlan;
use App\Models\Receiver;
use App\Models\Vehicle;
use App\Models\OperationalLibraryValue;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DispatchPlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Requests/Dispatches', [
            'dispatches' => DispatchPlan::with(['request', 'request.items'])->latest()->paginate(15),
            'requests' => AssistanceRequest::whereIn('status', ['approved', 'partially_approved'])->latest()->get(),
            'vehicles' => Vehicle::where('status', 'available')->get(),
            'receivers' => Receiver::orderBy('agency')->get(),
            'libraryOptions' => OperationalLibraryValue::groupedOptions(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()?->can('manage dispatches'), 403);

        $data = $request->validate([
            'request_id' => ['required', 'exists:requests,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'receiver_id' => ['nullable', 'exists:receivers,id'],
            'destination' => ['required', 'string', 'max:255'],
            'receiving_agency_lgu' => ['required', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'dispatcher' => ['nullable', 'string', 'max:255'],
            'dispatch_date' => ['required', 'date'],
            'estimated_arrival' => ['nullable', 'date'],
            'actual_arrival' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $dispatch = DispatchPlan::create([
            ...$data,
            'dispatch_number' => 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
            'status' => 'scheduled',
        ]);

        AssistanceRequest::whereKey($data['request_id'])->update(['status' => 'released']);
        $audit->log('dispatch.created', $dispatch, [], $dispatch->toArray());

        return back()->with('success', 'Dispatch plan created.');
    }
}
