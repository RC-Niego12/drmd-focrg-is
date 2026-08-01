<?php

use App\Http\Controllers\AccessManagementController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AiRogerController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\SSOController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DispatchPlanController;
use App\Http\Controllers\DistributionPlanController;
use App\Http\Controllers\DrmdAaRequestController;
use App\Http\Controllers\DrmdLguRoutingController;
use App\Http\Controllers\DromicReportController;
use App\Http\Controllers\EpirmaSigningController;
use App\Http\Controllers\EStockCardController;
use App\Http\Controllers\FniIssuanceController;
use App\Http\Controllers\FniLibraryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LguDirectoryController;
use App\Http\Controllers\LguDromicRequestController;
use App\Http\Controllers\LguProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OcdAlertController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PopulationController;
use App\Http\Controllers\PsgcAddressController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\RealtimeAuthController;
use App\Http\Controllers\StandbyFundController;
use App\Http\Controllers\SystemMessageController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\WarehouseController;
use App\Http\Middleware\EnsureMfaSatisfied;
use App\Http\Middleware\EnsureUserAccessApproved;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
    Route::get('login-lgu', [AuthenticatedSessionController::class, 'createLgu'])->name('login.lgu');
    Route::post('login-lgu', [AuthenticatedSessionController::class, 'storeLgu'])->name('login.lgu.store');
    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
    Route::get('sso/login', [SSOController::class, 'login'])->name('sso.login');
    Route::get('login-sso/callback', [SSOController::class, 'callback'])->name('sso.callback');
    Route::get('sso/complete', [SSOController::class, 'complete'])->name('sso.complete');
});

// Protected by a temporary signed URL so e-PIRMA can fetch the PDF for signing.
Route::get('/integrations/epirma/signed-documents/{document}', [EpirmaSigningController::class, 'serveSignedDocument'])
    ->name('epirma.signed-document');

// Protected by a single-use, hashed callback token rather than a browser
// session so the external e-PIRMA service can complete the signing handoff.
Route::match(['get', 'post'], '/integrations/epirma/requests/{assistanceRequest}/callback', [EpirmaSigningController::class, 'callback'])
    ->name('epirma.callback');

Route::middleware('auth')->group(function (): void {
    Route::get('mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('mfa/setup', [MfaController::class, 'storeSetup'])->name('mfa.setup.store');
    Route::get('mfa/verify', [MfaController::class, 'verify'])->name('mfa.verify');
    Route::post('mfa/verify', [MfaController::class, 'storeVerify'])->name('mfa.verify.store');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('session/keepalive', function (Request $request) {
        $request->session()->put('last_keepalive_at', now()->toIso8601String());

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
        ]);
    })->middleware('throttle:30,1')->name('session.keepalive');

    Route::patch('/settings/theme', function (Request $request) {
        $validated = $request->validate([
            'theme_mode' => ['required', 'in:light,dark'],
        ]);

        $request->user()->update($validated);

        return back();
    })->name('settings.theme');

    Route::patch('/settings/password', [PasswordController::class, 'update'])->name('settings.password');

    Route::middleware(EnsureMfaSatisfied::class)->group(function (): void {
        Route::get('/realtime/auth', RealtimeAuthController::class)
            ->middleware('throttle:60,1')
            ->name('realtime.auth');
        Route::get('/access/request', [AccessRequestController::class, 'show'])->name('access.request');
        Route::post('/access/request', [AccessRequestController::class, 'store'])->name('access.request.store');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::patch('/notifications/{notification}/acknowledge-regional-alert', [NotificationController::class, 'acknowledgeRegionalAlert'])->name('notifications.regional-alert.acknowledge');
        Route::get('/messages', [SystemMessageController::class, 'index'])->name('messages.index');
        Route::post('/messages', [SystemMessageController::class, 'store'])->name('messages.store');
        Route::patch('/messages/{message}/read', [SystemMessageController::class, 'read'])->name('messages.read');
        Route::post('/ai-roger/chat', [AiRogerController::class, 'chat'])->name('ai-roger.chat');
        Route::post('/profile/myportal/sync', [UserProfileController::class, 'syncMyPortal'])->name('profile.myportal.sync');
        Route::get('/profile/aor/occupied-scopes', [UserProfileController::class, 'occupiedAorScopes'])->name('profile.aor.occupied-scopes');
        Route::post('/profile/aor', [UserProfileController::class, 'updateAor'])->name('profile.aor.update');
        Route::get('/profile/photo', [UserProfileController::class, 'photo'])->name('profile.photo');
        Route::get('/profile/psgc/regions/{regionCode}/provinces', [PsgcController::class, 'provinces'])->name('profile.psgc.provinces');
        Route::get('/profile/psgc/provinces/{provinceCode}/cities-municipalities', [PsgcController::class, 'citiesMunicipalities'])->name('profile.psgc.cities-municipalities');
        Route::get('/profile/psgc/provinces/{provinceCode}/districts', [PsgcController::class, 'districts'])->name('profile.psgc.districts');
        Route::get('/profile/psgc/districts/{districtCode}/cities-municipalities', [PsgcController::class, 'districtCitiesMunicipalities'])->name('profile.psgc.district-cities-municipalities');
        Route::middleware(EnsureUserAccessApproved::class)->group(function (): void {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::get('/dashboard', DashboardController::class);
            Route::get('/rros-dashboard', [DashboardController::class, 'rros'])->name('dashboard.rros');

            Route::get('/inventory/e-stock-card', EStockCardController::class)->name('inventory.e-stock-card')->middleware('permission:manage inventory');
            Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index')->middleware('permission:manage inventory|view dashboards');
            Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store')->middleware('permission:manage inventory');
            Route::post('/inventory/sync-google-sheet', [InventoryController::class, 'syncGoogleSheet'])->name('inventory.sync-google-sheet')->middleware('permission:manage inventory');
            Route::post('/inventory/receipts', [InventoryController::class, 'receipt'])->name('inventory.receipts')->middleware('permission:manage inventory');
            Route::post('/inventory/releases', [InventoryController::class, 'release'])->name('inventory.releases')->middleware('permission:manage inventory');
            Route::put('/inventory/{inventory}', [InventoryController::class, 'update'])->name('inventory.update')->middleware('permission:manage inventory')->whereNumber('inventory');
            Route::get('/inventory/batches', [InventoryController::class, 'batches'])->name('inventory.batches')->middleware('permission:manage inventory|manage near expiry');
            Route::get('/near-expiry', [DistributionPlanController::class, 'index'])->name('near-expiry.index')->middleware('permission:manage near expiry');
            Route::post('/near-expiry/plans', [DistributionPlanController::class, 'store'])->name('near-expiry.store')->middleware('permission:manage near expiry');
            Route::get('/fni-issuances', FniIssuanceController::class)->name('fni-issuances.index')->middleware('permission:manage inventory|view dashboards');

            Route::post('/warehouses/sync-google-sheet', [WarehouseController::class, 'syncGoogleSheet'])->name('warehouses.sync-google-sheet')->middleware('permission:manage warehouses');
            Route::post('/warehouses/generate-identity', [WarehouseController::class, 'generateIdentity'])->name('warehouses.generate-identity')->middleware('permission:manage warehouses');
            Route::resource('warehouses', WarehouseController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('permission:manage warehouses');

            Route::get('/psgc/regions', [PsgcController::class, 'regions'])->name('psgc.regions')->middleware('permission:manage warehouses|manage psgc addresses');
            Route::get('/psgc/regions/{regionCode}/provinces', [PsgcController::class, 'provinces'])->name('psgc.provinces')->middleware('permission:manage warehouses|manage psgc addresses');
            Route::get('/psgc/provinces/{provinceCode}/cities-municipalities', [PsgcController::class, 'citiesMunicipalities'])->name('psgc.cities-municipalities')->middleware('permission:manage warehouses|manage psgc addresses');
            Route::get('/psgc/provinces/{provinceCode}/districts', [PsgcController::class, 'districts'])->name('psgc.districts')->middleware('permission:manage warehouses|manage psgc addresses');
            Route::get('/psgc/districts/{districtCode}/cities-municipalities', [PsgcController::class, 'districtCitiesMunicipalities'])->name('psgc.districts.cities-municipalities')->middleware('permission:manage warehouses|manage psgc addresses');
            Route::get('/psgc/cities-municipalities/{cityMunicipalityCode}/barangays', [PsgcController::class, 'barangays'])->name('psgc.barangays')->middleware('permission:manage warehouses|manage psgc addresses');

            Route::get('/requests', [RequestController::class, 'index'])->name('requests.index')->middleware('permission:encode requests|monitor requests|process requests');
            Route::post('/requests', [RequestController::class, 'store'])->name('requests.store')->middleware('permission:encode requests');
            Route::get('/drmd-aa/requests', [DrmdAaRequestController::class, 'index'])->name('drmd-aa.requests.index')->middleware('role:DRMD AA');
            Route::post('/drmd-aa/requests', [DrmdAaRequestController::class, 'store'])->name('drmd-aa.requests.store')->middleware('role:DRMD AA');
            Route::get('/drmd-aa/proposals', [DrmdAaRequestController::class, 'proposals'])->name('drmd-aa.proposals.index')->middleware('role:DRMD AA');
            Route::post('/drmd-aa/proposals', [DrmdAaRequestController::class, 'storeProposal'])->name('drmd-aa.proposals.store')->middleware('role:DRMD AA');
            Route::get('/lgu/dromic-sitrep', [LguDromicRequestController::class, 'index'])->name('lgu.dromic-requests.index')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep', [LguDromicRequestController::class, 'store'])->name('lgu.dromic-requests.store')->middleware('permission:submit lgu dromic requests');
            Route::patch('/lgu/dromic-sitrep/{assistanceRequest}', [LguDromicRequestController::class, 'update'])->name('lgu.dromic-requests.update')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/{assistanceRequest}/correction-draft', [LguDromicRequestController::class, 'startCorrectionDraft'])->name('lgu.dromic-requests.correction-draft')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/{assistanceRequest}/signed-copies', [LguDromicRequestController::class, 'uploadSignedCopies'])->name('lgu.dromic-requests.signed-copies')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/{assistanceRequest}/submit', [LguDromicRequestController::class, 'submitToDswd'])->name('lgu.dromic-requests.submit')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/polish', [LguDromicRequestController::class, 'polish'])->name('lgu.dromic-requests.polish')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/official-advisories', [LguDromicRequestController::class, 'officialAdvisories'])->name('lgu.dromic-requests.official-advisories')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/official-advisories/import', [LguDromicRequestController::class, 'importOfficialAdvisory'])->name('lgu.dromic-requests.official-advisories.import')->middleware('permission:submit lgu dromic requests');
            Route::post('/lgu/dromic-sitrep/official-advisories/extract-screenshot', [LguDromicRequestController::class, 'extractOfficialAdvisoryScreenshot'])->name('lgu.dromic-requests.official-advisories.extract-screenshot')->middleware(['permission:submit lgu dromic requests', 'throttle:12,1']);
            Route::get('/lgu/dromic-sitrep/{assistanceRequest}/pdf', [LguDromicRequestController::class, 'pdf'])->name('lgu.dromic-requests.pdf');
            Route::get('/lgu/dromic-sitrep/{assistanceRequest}/encoded-data', [LguDromicRequestController::class, 'encodedData'])->name('lgu.dromic-requests.encoded-data');
            Route::get('/lgu/dromic-sitrep/{assistanceRequest}/signed-copy/{kind}', [LguDromicRequestController::class, 'signedCopy'])->name('lgu.dromic-requests.signed-copy');
            Route::get('/lgu/dromic-sitrep/{assistanceRequest}/validation-screenshot/{kind}/{index}', [LguDromicRequestController::class, 'validationScreenshot'])->name('lgu.dromic-requests.validation-screenshot')->whereNumber('index');
            Route::get('/lgu/dromic-sitrep/{assistanceRequest}/validation-history-screenshot/{kind}/{reviewIndex}/{screenshotIndex}', [LguDromicRequestController::class, 'validationHistoryScreenshot'])->name('lgu.dromic-requests.validation-history-screenshot')->whereNumber(['reviewIndex', 'screenshotIndex']);
            Route::get('/lgu/dromic-sitrep/signed-history/{version}', [LguDromicRequestController::class, 'signedHistory'])->name('lgu.dromic-requests.signed-history');
            Route::patch('/lgu/dromic-sitrep/{assistanceRequest}/document-viewed', [LguDromicRequestController::class, 'documentViewed'])->name('lgu.dromic-requests.document-viewed');
            Route::post('/lgu/profile', [LguProfileController::class, 'update'])->name('lgu.profile.update')->middleware('permission:submit lgu dromic requests');
            Route::get('/ocd/alerts', [OcdAlertController::class, 'index'])->name('ocd.alerts.index')->middleware('permission:manage regional alerts');
            Route::post('/ocd/alerts', [OcdAlertController::class, 'store'])->name('ocd.alerts.store')->middleware('permission:manage regional alerts');
            Route::post('/ocd/profile', [OcdAlertController::class, 'updateProfile'])->name('ocd.profile.update')->middleware('permission:manage regional alerts');
            Route::get('/alert-acknowledgments', [OcdAlertController::class, 'acknowledgements'])->name('alert-acknowledgments.index')->middleware('permission:view regional alert acknowledgements');
            Route::get('/regional-alert-acknowledgements', [OcdAlertController::class, 'acknowledgements'])->name('regional-alert-acknowledgements.index')->middleware('permission:view regional alert acknowledgements');
            Route::get('/drmd-aa/lgu-intake', fn () => redirect()->route('drmd-aa.requests.index'))->name('drmd-aa.lgu-intake.index')->middleware('role:DRMD AA');
            Route::patch('/drmd-aa/lgu-intake/{assistanceRequest}/drn', [DrmdLguRoutingController::class, 'recordDrn'])->name('drmd-aa.lgu-intake.drn')->middleware('role:DRMD AA');
            Route::post('/drmd-aa/requests/{assistanceRequest}/photos', [DrmdAaRequestController::class, 'addPhotos'])->name('drmd-aa.requests.photos.store')->middleware('role:DRMD AA');
            Route::get('/requests/{assistanceRequest}/drmd-aa-photo/{index}', [DrmdAaRequestController::class, 'photo'])->name('requests.drmd-aa-photo')->whereNumber('index')->middleware('permission:submit drmd aa requests|encode requests|monitor requests|process requests');
            Route::patch('/drmd-aa/lgu-intake/{assistanceRequest}/chief', [DrmdLguRoutingController::class, 'routeToChief'])->name('drmd-aa.lgu-intake.route-chief')->middleware('role:DRMD AA');
            Route::patch('/drmd-aa/lgu-intake/{assistanceRequest}/drrs', [DrmdLguRoutingController::class, 'routeToDrrs'])->name('drmd-aa.lgu-intake.route-drrs')->middleware('role:DRMD AA');
            Route::get('/drmd-chief/lgu-intake', [DrmdLguRoutingController::class, 'chiefIndex'])->name('drmd-chief.lgu-intake.index')->middleware('permission:route lgu dromic requests');
            Route::patch('/drmd-chief/lgu-intake/{assistanceRequest}/directive', [DrmdLguRoutingController::class, 'chiefDirective'])->name('drmd-chief.lgu-intake.directive')->middleware('permission:route lgu dromic requests');
            Route::post('/requests/{assistanceRequest}/decision', [RequestController::class, 'approve'])->name('requests.decision')->middleware('permission:process requests');
            Route::get('/requests/{assistanceRequest}/previous-augmentations', [RequestController::class, 'previousAugmentations'])->name('requests.previous-augmentations')->middleware('permission:encode requests|monitor requests|process requests');
            Route::get('/requests/{assistanceRequest}/assessment-form', [RequestController::class, 'assessmentForm'])->name('requests.assessment');
            Route::get('/requests/{assistanceRequest}/source-document', [RequestController::class, 'sourceDocument'])->name('requests.source-document')->middleware('permission:submit drmd aa requests|encode requests|monitor requests|process requests');
            Route::get('/requests/{assistanceRequest}/assessment-pdf', [RequestController::class, 'assessmentPdf'])->name('requests.assessment-pdf');
            Route::patch('/requests/{assistanceRequest}/assessment-form', [RequestController::class, 'updateAssessment'])->name('requests.assessment.update')->middleware('permission:encode requests|monitor requests|process requests');
            Route::patch('/requests/{assistanceRequest}/complete-assessment', [RequestController::class, 'completeAssessment'])->name('requests.assessment.complete')->middleware('permission:encode requests');
            Route::patch('/requests/{assistanceRequest}/assessment-status', [RequestController::class, 'assessmentStatus'])->name('requests.assessment.status')->middleware('permission:encode requests');
            Route::post('/requests/{assistanceRequest}/epirma/sign', [EpirmaSigningController::class, 'start'])->name('requests.epirma.sign')->middleware('permission:encode requests');
            Route::get('/requests/{assistanceRequest}/epirma/status', [EpirmaSigningController::class, 'latestSignedStatus'])->name('requests.epirma.status')->middleware('permission:encode requests');
            Route::post('/requests/{assistanceRequest}/epirma/retry', [EpirmaSigningController::class, 'retrySigning'])->name('requests.epirma.retry')->middleware('permission:encode requests');
            Route::post('/requests/polish-assessment', [RequestController::class, 'polishAssessment'])->name('requests.assessment.polish')->middleware('permission:encode requests');
            Route::get('/requests/{assistanceRequest}/response-letter', [RequestController::class, 'responseLetter'])->name('requests.response-letter');
            Route::get('/requests/{assistanceRequest}/response-letter-pdf', [RequestController::class, 'responseLetterPdf'])->name('requests.response-letter-pdf');
            Route::patch('/requests/{assistanceRequest}/response-drn', [RequestController::class, 'updateResponseDrn'])->name('requests.response-drn.update')->middleware('permission:encode requests');

            Route::get('/dispatches', [DispatchPlanController::class, 'index'])->name('dispatches.index')->middleware('permission:manage dispatches');
            Route::post('/dispatches', [DispatchPlanController::class, 'store'])->name('dispatches.store')->middleware('permission:manage dispatches');

            Route::get('/fni-library', [FniLibraryController::class, 'legacyRedirect']);
            Route::get('/libraries', [FniLibraryController::class, 'index'])->name('fni-library.index')->middleware('permission:manage inventory|encode requests|manage users');
            Route::post('/fni-library', [FniLibraryController::class, 'store'])->name('fni-library.store')->middleware('permission:manage inventory');
            Route::put('/fni-library/{fniLibraryItem}', [FniLibraryController::class, 'update'])->name('fni-library.update')->middleware('permission:manage inventory');
            Route::delete('/fni-library/{fniLibraryItem}', [FniLibraryController::class, 'destroy'])->name('fni-library.destroy')->middleware('permission:manage inventory');
            Route::post('/warehouse-library', [FniLibraryController::class, 'storeWarehouseValue'])->name('warehouse-library.store')->middleware('permission:manage inventory');
            Route::put('/warehouse-library/{warehouseLibraryValue}', [FniLibraryController::class, 'updateWarehouseValue'])->name('warehouse-library.update')->middleware('permission:manage inventory');
            Route::delete('/warehouse-library/{warehouseLibraryValue}', [FniLibraryController::class, 'destroyWarehouseValue'])->name('warehouse-library.destroy')->middleware('permission:manage inventory');
            Route::post('/operational-library', [FniLibraryController::class, 'storeOperationalValue'])->name('operational-library.store')->middleware('permission:manage inventory|encode requests');
            Route::put('/operational-library/{operationalLibraryValue}', [FniLibraryController::class, 'updateOperationalValue'])->name('operational-library.update')->middleware('permission:manage inventory|encode requests');
            Route::delete('/operational-library/{operationalLibraryValue}', [FniLibraryController::class, 'destroyOperationalValue'])->name('operational-library.destroy')->middleware('permission:manage inventory|encode requests');

            Route::get('/dromic', [DromicReportController::class, 'index'])->name('dromic.index')->middleware('permission:manage dromic reports');
            Route::post('/dromic', [DromicReportController::class, 'store'])->name('dromic.store')->middleware('permission:manage dromic reports');
            Route::get('/dromic/lgu-reports', [DromicReportController::class, 'lguReports'])->name('dromic.lgu-reports');
            Route::patch('/dromic/lgu-reports/{assistanceRequest}/validation', [DromicReportController::class, 'updateLguReportValidation'])->name('dromic.lgu-reports.validation.update');
            Route::post('/dromic/lgu-reports/{assistanceRequest}/validation', [DromicReportController::class, 'updateLguReportValidation']);
            Route::patch('/dromic/lgu-reports/{assistanceRequest}/relief-validation', [DromicReportController::class, 'updateLguReliefValidation'])->name('dromic.lgu-reports.relief-validation.update');
            Route::post('/dromic/lgu-reports/{assistanceRequest}/relief-validation', [DromicReportController::class, 'updateLguReliefValidation']);

            Route::get('/audit-trail', AuditTrailController::class)->name('audit-trail.index')->middleware('permission:view audit logs');
            Route::get('/access-management', [AccessManagementController::class, 'index'])->name('access-management.index')->middleware('permission:manage users');
            Route::post('/access-management/super-admin', [AccessManagementController::class, 'assignSuperAdmin'])->name('access-management.super-admin')->middleware('permission:manage users');
            Route::post('/access-management/{user}/restore', [AccessManagementController::class, 'restore'])->name('access-management.restore')->middleware('permission:manage users')->whereNumber('user');
            Route::delete('/access-management/{user}/force', [AccessManagementController::class, 'forceDelete'])->name('access-management.force-delete')->middleware('permission:manage users')->whereNumber('user');
            Route::patch('/access-management/{user}', [AccessManagementController::class, 'update'])->name('access-management.update')->middleware('permission:manage users');
            Route::delete('/access-management/{user}', [AccessManagementController::class, 'destroy'])->name('access-management.destroy')->middleware('permission:manage users');
            Route::post('/access-management/{user}/decision', [AccessManagementController::class, 'decide'])->name('access-management.decision')->middleware('permission:manage users');
            Route::get('/lgu-library', [LguDirectoryController::class, 'index'])->name('lgu-library.index')->middleware('permission:manage users');
            Route::post('/lgu-library/sync', [LguDirectoryController::class, 'sync'])->name('lgu-library.sync')->middleware('permission:manage users');
            Route::post('/lgu-library/sync-preview', [LguDirectoryController::class, 'preview'])->name('lgu-library.preview')->middleware('permission:manage users');
            Route::patch('/lgu-library/{lguDirectoryEntry}', [LguDirectoryController::class, 'update'])->name('lgu-library.update')->middleware('permission:manage users');
            Route::get('/psgc-addresses', [PsgcAddressController::class, 'index'])->name('psgc-addresses.index')->middleware('permission:manage users|manage psgc addresses');
            Route::post('/psgc-addresses/sync', [PsgcAddressController::class, 'sync'])->name('psgc-addresses.sync')->middleware('permission:manage users|manage psgc addresses');
            Route::post('/psgc-addresses/upload', [PsgcAddressController::class, 'upload'])->name('psgc-addresses.upload')->middleware('permission:manage users|manage psgc addresses');
            Route::patch('/psgc-addresses/settings', [PsgcAddressController::class, 'updateSettings'])->name('psgc-addresses.settings')->middleware('permission:manage users|manage psgc addresses');
            Route::post('/psgc-addresses/districts/sync-warehouses', [PsgcAddressController::class, 'syncDistricts'])->name('psgc-addresses.districts.sync')->middleware('permission:manage users|manage psgc addresses');
            Route::post('/psgc-addresses/districts/sync-reference-sheet', [PsgcAddressController::class, 'syncDistrictReferenceSheet'])->name('psgc-addresses.districts.sync-reference-sheet')->middleware('permission:manage users|manage psgc addresses');
            Route::post('/psgc-addresses/districts', [PsgcAddressController::class, 'storeDistrict'])->name('psgc-addresses.districts.store')->middleware('permission:manage users|manage psgc addresses');
            Route::patch('/psgc-addresses/districts/{district}', [PsgcAddressController::class, 'updateDistrict'])->name('psgc-addresses.districts.update')->middleware('permission:manage users|manage psgc addresses');
            Route::patch('/psgc-addresses/cities/{city}/district', [PsgcAddressController::class, 'assignCityDistrict'])->name('psgc-addresses.cities.district')->middleware('permission:manage users|manage psgc addresses');
            Route::get('/population', [PopulationController::class, 'index'])->name('population.index')->middleware('permission:manage users|manage population');
            Route::post('/population/import', [PopulationController::class, 'import'])->name('population.import')->middleware('permission:manage users|manage population');
            Route::put('/population/{population}', [PopulationController::class, 'update'])->name('population.update')->middleware('permission:manage users|manage population');

            Route::get('/standby-funds', [StandbyFundController::class, 'index'])->name('standby-funds.index')->middleware('permission:manage standby funds');
            Route::put('/standby-funds', [StandbyFundController::class, 'update'])->name('standby-funds.update')->middleware('permission:manage standby funds');
            Route::post('/standby-funds/sync-google-sheet', [StandbyFundController::class, 'sync'])->name('standby-funds.sync')->middleware('permission:manage standby funds');
        });
    });
});

Route::get('/api/system-config', [FniLibraryController::class, 'systemConfig'])->name('api.system-config');
