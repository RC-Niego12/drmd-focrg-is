<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class MfaController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
    ) {}

    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->mfa_enabled) {
            return redirect()->route('mfa.verify');
        }

        $secret = $request->session()->get('mfa_setup_secret');
        if (! $secret) {
            $secret = $this->totp->generateSecret();
            $request->session()->put('mfa_setup_secret', $secret);
        }

        $issuer = (string) config('app.name', 'DRIMS');
        $account = $user->email ?: ($user->username ?: 'user-'.$user->id);
        $otpauthUri = $this->totp->otpAuthUri($secret, $account, $issuer);
        $qrSvg = QrCode::format('svg')->size(220)->margin(1)->generate($otpauthUri);

        return Inertia::render('Auth/MfaSetup', [
            'secret' => $secret,
            'qrSvg' => (string) $qrSvg,
            'account' => $account,
            'issuer' => $issuer,
        ]);
    }

    public function storeSetup(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->mfa_enabled) {
            return redirect()->route('mfa.verify');
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $secret = $request->session()->get('mfa_setup_secret');
        if (! $secret) {
            throw ValidationException::withMessages([
                'code' => 'MFA setup expired. Reload this page and try again.',
            ]);
        }

        if (! $this->totp->verify($secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or expired. Try again.',
            ]);
        }

        $user->forceFill([
            'mfa_secret' => encrypt($secret),
            'mfa_enabled' => true,
            'mfa_verified' => true,
        ])->save();

        $request->session()->forget(['mfa_setup_secret', 'mfa_required']);

        return $this->redirectAfterMfa($request)
            ->with('success', 'Authenticator app linked successfully.');
    }

    public function verify(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->mfa_enabled) {
            return redirect()->route('mfa.setup');
        }

        return Inertia::render('Auth/MfaVerify', [
            'email' => $user->email,
        ]);
    }

    public function storeVerify(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->mfa_enabled || blank($user->mfa_secret)) {
            return redirect()->route('mfa.setup');
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $secret = decrypt($user->mfa_secret);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'code' => 'MFA is misconfigured for this account. Contact a Super Admin.',
            ]);
        }

        if (! $this->totp->verify($secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or expired. Try again.',
            ]);
        }

        $user->forceFill(['mfa_verified' => true])->save();
        $request->session()->forget('mfa_required');

        return $this->redirectAfterMfa($request)
            ->with('success', 'Multi-factor authentication verified.');
    }

    private function redirectAfterMfa(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->access_status !== 'approved') {
            return redirect()->route('access.request');
        }

        return redirect()->intended(route('dashboard'));
    }
}
