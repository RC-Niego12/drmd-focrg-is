<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SSOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SSOAuthService $sso,
    ) {}

    public function syncMyPortal(Request $request): RedirectResponse
    {
        $user = $request->user();
        $old = $user->only($this->profileFields());

        try {
            $myPortalProfile = $this->sso->fetchMyPortalProfile();
            $mapped = $this->sso->mapProfileToUserFields($myPortalProfile);
            $payload = collect($mapped)
                ->only($this->profileFields())
                ->filter(fn ($value): bool => filled($value))
                ->all();

            if ($payload === []) {
                return back()->with('error', 'MyPortal returned no employee profile fields.');
            }

            $existingSsoPayload = is_array($user->sso_profile_payload) ? $user->sso_profile_payload : [];

            $user->forceFill([
                ...$payload,
                'sso_profile_payload' => [
                    ...$existingSsoPayload,
                    'myportal' => $myPortalProfile,
                    'myportal_checked_at' => now()->toISOString(),
                ],
            ])->save();

            $this->audit->log('user.myportal_profile_synced', $user, $old, $user->fresh()->only($this->profileFields()), $user->id);

            return back()->with('success', 'Employee profile refreshed from MyPortal.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function photo(Request $request): Response
    {
        $avatar = $this->sso->normalizeAvatarUrl($request->user()?->avatar);

        abort_if(blank($avatar), 404);

        if (str_starts_with((string) $avatar, 'data:image/')) {
            [$meta, $encoded] = explode(',', (string) $avatar, 2) + [null, null];
            $mime = str($meta)->between('data:', ';base64')->value() ?: 'image/png';

            return response(base64_decode((string) $encoded, true) ?: '', 200)
                ->header('Content-Type', $mime)
                ->header('Cache-Control', 'private, max-age=600');
        }

        abort_unless($this->sso->isTrustedIdentityUrl((string) $avatar), 403);

        $remote = Http::accept('*/*')
            ->timeout(10)
            ->when(! config('services.cc_idp.verify_ssl'), fn ($pending) => $pending->withoutVerifying())
            ->get((string) $avatar);

        abort_unless($remote->ok(), 404);

        $contentType = $remote->header('Content-Type') ?: 'image/jpeg';
        abort_unless(str_starts_with(strtolower($contentType), 'image/'), 415);

        return response($remote->body(), 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'private, max-age=600');
    }

    private function profileFields(): array
    {
        return [
            'name',
            'username',
            'email',
            'id_number',
            'office',
            'position',
            'designation',
            'area_of_assignment',
            'employment_status',
            'contact_number',
            'mobile_no',
            'avatar',
        ];
    }
}
