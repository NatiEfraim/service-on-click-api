<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingAdminManagementController;
use App\Http\Controllers\BookingApproverManagementController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingSuperAdminManagementController;
use App\Http\Controllers\ManagementAuditController;
use App\Http\Controllers\ManagementBookingController;
use App\Http\Controllers\ManagementBookingReportController;
use App\Http\Controllers\ManagementDigitalHatchController;
use App\Http\Controllers\ManagementInsightController;
use App\Http\Controllers\ManagementOrgController;
use App\Http\Controllers\ManagementSettingsController;
use App\Http\Controllers\ManagementSyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ── Authentication ───────────────────────────────────────────────────────────────────────────────
// Token-based login / logout and reading the authenticated user's own profile.
Route::controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->middleware(['throttle:api']);
    Route::post('/logout', 'logout')->middleware(['auth:api', 'throttle:api']);
    Route::get('/user', [UserController::class, 'user'])->middleware(['auth:api', 'throttle:api']);
});

// Trigger a full bucket import now (bookings → logs → files); token-free on APP_ENV=local so
// the developer can call it without logging in first; super admin only on every other environment.
Route::get('/test-import', [UserController::class, 'testImport'])
    ->middleware(app()->environment('local') ? [] : ['auth:api', 'throttle:api', 'role:super_admin']);

// ── super_admin | admin | approver | vice_approver | user ────────────────────────────────────────
// The booking requester (the mobile-app user) can read their own bookings from the web app as well.
Route::controller(BookingController::class)
    ->prefix('bookings')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|user'])
    ->group(function () {
        Route::get('/my-bookings', 'myBookings');
        Route::get('/{bookingUuid}', 'show')->whereUuid('bookingUuid');
    });

// ── super_admin | admin | approver | vice_approver ───────────────────────────────────────────────
// Dashboard, filtered views, handler lists and booking-type reference data.
// The transportation control officer is excluded here because they cannot see these screens.
Route::controller(BookingApproverManagementController::class)
    ->prefix('bookings-approver-management')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver'])
    ->group(function () {
        Route::get('/dashboard/{bookingType}', 'getBookingsDashboard');
        Route::get('/filtered-dashboard/{bookingType}', 'getFilteredDashboard');
        Route::get('/get-vice-approvers/{bookingTypeId}', 'getViceApprovers');
        Route::get('/get-bookings/{bookingTypeId}', 'getBookings');
        Route::get('/system-references/{bookingType}', 'getSystemReferences');
        Route::get('/barber-bookings/dates', 'getBarberBookingDate');
        Route::get('/barber-bookings/order-bookings', 'getOrderBarberBooking');
        Route::put('/system-references', 'updateSystemReferences');
    });

// ── super_admin | admin | approver | vice_approver | transportation_control_officer ─────────────
// Acting on a booking (changing its status, correcting values) and reading the domain insights.
// Access inside each action is further checked per booking by AccessScope and the Workflow rules.
Route::middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|transportation_control_officer'])
    ->prefix('bookings-approver-management')
    ->group(function () {
        // Booking list, detail, attachment download and status / value changes.
        Route::controller(ManagementBookingController::class)->group(function () {
            Route::get('/bookings', 'index');
            Route::get('/bookings/{serial}', 'show')->whereNumber('serial');
            Route::get('/bookings/{serial}/attachments/{kind}/{id}', 'attachment')
                ->whereNumber('serial')->whereIn('kind', ['file', 'image'])->whereNumber('id');
            Route::post('/bookings/{serial}/status', 'changeStatus')->whereNumber('serial');
            Route::put('/bookings/{serial}/values', 'updateValues')->whereNumber('serial');
            // Creating a booking from the web is blocked (405) and recorded in the audit log.
            Route::match(['post', 'put'], '/bookings', 'createBlocked');
            Route::post('/bookings/{bookingTypeId}/create', 'createBlocked')->whereNumber('bookingTypeId');
        });

        // Booking-type settings and restriction-impact check.
        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::get('/settings/{unitId}/{bookingTypeId}', 'settings')->whereNumber(['unitId', 'bookingTypeId']);
            Route::post('/settings/references/{id}/impact', 'restrictionImpact')->whereNumber('id');
        });

        // Insights: personal summary, catalog, overview, bottlenecks and the domain dashboard.
        // The domain dashboard and bottlenecks additionally check access inside the service.
        Route::controller(ManagementInsightController::class)->group(function () {
            Route::get('/me', 'me');
            Route::get('/catalog', 'catalog');
            Route::get('/overview', 'overview');
            Route::get('/bottlenecks', 'bottlenecks');
            Route::get('/domain-dashboard', 'domainDashboard');
        });
    });

// ── super_admin | admin ──────────────────────────────────────────────────────────────────────────
// User list / creation, approver-role assignment, booking-type targets, the admin home screen,
// the bookings report with its Excel export and department assignment.
Route::middleware(['auth:api', 'throttle:api', 'role:super_admin|admin'])
    ->group(function () {
        // User list and creation; the admin is scoped to their own unit by the service.
        Route::controller(UserController::class)
            ->prefix('users')
            ->group(function () {
                Route::get('/{identifier?}', 'index');
                Route::post('/', 'store');
            });

        Route::prefix('bookings-admin-management')->group(function () {
            // Approver and vice-approver role assignment per booking type.
            Route::controller(BookingAdminManagementController::class)->group(function () {
                Route::post('/assign-approver-role', 'assignApproverRole');
                Route::get('/get-approvers', 'getApprovers');
                Route::get('/get-approver-permissions/{userUuid}', 'getApproverPermissions');
            });

            // Booking-type targets (read and set) and admin home screen.
            Route::controller(ManagementSettingsController::class)->group(function () {
                Route::get('/targets', 'targets');
                Route::put('/targets', 'setTarget');
            });

            // Admin home screen figures and report list.
            Route::controller(ManagementInsightController::class)->group(function () {
                Route::get('/home', 'home');
                Route::get('/reports', 'reports');
            });

            // Bookings report: filtered list (GET) and Excel export kept in the bucket as a backup (POST).
            Route::controller(ManagementBookingReportController::class)
                ->prefix('bookings-report')
                ->group(function () {
                    Route::get('/', 'index');
                    Route::post('/export', 'export');
                });

            // Move a user to a different department within the admin's unit.
            Route::put('/users/{uuid}/department', [ManagementOrgController::class, 'setUserDepartment'])->whereUuid('uuid');
        });
    });

// ── super_admin ──────────────────────────────────────────────────────────────────────────────────
// Everything only the super admin may do: assign admin roles, edit booking-type forms,
// manage the organisation tree, inspect the mobile sync, read the audit log and
// manually drive the digital hatch (the FTP / bucket channel to the mobile app).
Route::middleware(['auth:api', 'throttle:api', 'role:super_admin'])
    ->prefix('bookings-super-admin-management')
    ->group(function () {
        // Admin-role assignment and the form-field options / statement editor.
        Route::controller(BookingSuperAdminManagementController::class)->group(function () {
            Route::post('/assign-admin-role', 'assignAdminRole');
            Route::get('/get-admins', 'getAdmins');
            Route::get('/get-admin-permissions/{userUuid}', 'getAdminPermissions');
            Route::get('/index-selects/{unitId}/{bookingType}', 'indexSelects');
            Route::get('/get-select-options/{formFieldId}', 'getSelectOptions');
            Route::get('/get-options', 'getOptions');
            Route::get('/get-statement/{bookingTypeId}', 'getStatement');
            Route::get('/get-siblings-options/{optionFieldId}', 'getSiblingsOptions');
            Route::put('/update-select-option/{formFieldId}', 'updateSelectOptions');
            Route::put('/update-options/{formFieldId}', 'updateOptions');
            Route::put('/update-option/{formFieldId}', 'updateOption');
            Route::put('/update-statement/{unitId}/{bookingTypeId}', 'updateStatement');
            Route::delete('/remove-options/{formFieldId}', 'removeSelectOptions');
            Route::delete('/remove-option/{formFieldId}', 'removeOption');
        });

        // Organisation tree: units and departments.
        Route::controller(ManagementOrgController::class)->group(function () {
            Route::get('/org', 'listing');
            Route::post('/org/units', 'createUnit');
            Route::put('/org/units/{id}', 'updateUnit')->whereNumber('id');
            Route::post('/org/units/{unitId}/departments', 'createDepartment')->whereNumber('unitId');
            Route::put('/org/departments/{id}', 'updateDepartment')->whereNumber('id');
        });

        // Booking-type form fields: changes are pushed to the mobile app on the next sync round;
        // a field that already has values is archived (not deleted) to keep the history intact.
        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::post('/form-fields/{unitId}/{bookingTypeId}', 'createField')->whereNumber(['unitId', 'bookingTypeId']);
            Route::put('/form-fields/{unitId}/{bookingTypeId}/order', 'reorderFields')->whereNumber(['unitId', 'bookingTypeId']);
            Route::put('/form-fields/{id}/editable', 'setFieldEditable')->whereNumber('id');
            Route::put('/form-fields/{id}', 'updateField')->whereNumber('id');
            Route::delete('/form-fields/{id}', 'removeField')->whereNumber('id');
        });

        // Mobile app sync: health overview, file inspection, conflict resolution and a manual send.
        Route::controller(ManagementSyncController::class)->group(function () {
            Route::get('/sync', 'overview');
            Route::get('/sync/files/{id}', 'file')->whereNumber('id');
            Route::post('/sync/files/{id}/retry', 'retry')->whereNumber('id');
            Route::post('/sync/conflicts/{id}/resolve', 'resolve')->whereNumber('id');
            Route::post('/sync/run', 'run');
        });

        // Audit log: every write attempt is refused (405) and recorded; the log is append-only.
        Route::controller(ManagementAuditController::class)->group(function () {
            Route::get('/audit', 'index');
            Route::get('/audit/verify', 'verify');
            Route::match(['put', 'patch', 'delete'], '/audit/{id}', 'immutable');
        });

        // Digital hatch control panel: manually trigger the r2b exports (booking status, users)
        // and the b2r imports (booking JSON, application log JSON, files) that the scheduler
        // runs automatically. Every endpoint accepts GET or POST.
        // Pass sync=0 to queue the work instead of running it inline and returning the result.
        Route::controller(ManagementDigitalHatchController::class)
            ->prefix('digital-hatch')
            ->group(function () {
                Route::match(['get', 'post'], '/export-booking-status', 'exportBookingStatus');
                Route::match(['get', 'post'], '/export-users', 'exportUsers');
                Route::match(['get', 'post'], '/import-booking-json', 'importBookingJson');
                Route::match(['get', 'post'], '/import-application-log-json', 'importApplicationLogJson');
                Route::match(['get', 'post'], '/import-files', 'importFiles');
            });
    });
