<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingAdminManagementController;
use App\Http\Controllers\BookingApproverManagementController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingSuperAdminManagementController;
use App\Http\Controllers\ManagementAuditController;
use App\Http\Controllers\ManagementBookingController;
use App\Http\Controllers\ManagementInsightController;
use App\Http\Controllers\ManagementOrgController;
use App\Http\Controllers\ManagementSettingsController;
use App\Http\Controllers\ManagementSyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->middleware(['throttle:api']);
    Route::post('/logout', 'logout')->middleware(['auth:api', 'throttle:api']);
    Route::get('/user', [UserController::class, 'user'])->middleware(['auth:api', 'throttle:api']);
});

Route::controller(UserController::class)
    ->prefix('users')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin'])
    ->group(function () {
        Route::get('/{identifier?}', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
    });

// Reads the json files waiting in the bucket right now (what the scheduled import commands do) and returns the imported data.
// Open on a local machine (APP_ENV=local) so Postman needs no token; on any other environment only the super admin may call it.
Route::get('/test-import', [UserController::class, 'testImport'])
    ->middleware(app()->environment('local') ? [] : ['auth:api', 'throttle:api', 'role:super_admin']);

// The only endpoints a person with just the user role may call (besides GET /user and POST /logout).
Route::controller(BookingController::class)
    ->prefix('bookings')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|user'])
    ->group(function () {
        Route::get('/my-bookings', [BookingController::class, 'myBookings']);
        Route::get('/{bookingUuid}', [BookingController::class, 'show'])->whereUuid('bookingUuid');
    });

Route::controller(BookingApproverManagementController::class)
    ->prefix('bookings-approver-management')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver'])
    ->group(function () {
        Route::get('/dashboard/{bookingType}', [BookingApproverManagementController::class, 'getBookingsDashboard']);
        Route::get('/filtered-dashboard/{bookingType}', [BookingApproverManagementController::class, 'getFilteredDashboard']);
        Route::get('/get-vice-approvers/{bookingTypeId}', [BookingApproverManagementController::class, 'getViceApprovers']);
        Route::get('/get-bookings/{bookingTypeId}', [BookingApproverManagementController::class, 'getBookings']);
        Route::get('/system-references/{bookingType}', [BookingApproverManagementController::class, 'getSystemReferences']);
        Route::get('/barber-bookings/dates', [BookingApproverManagementController::class, 'getBarberBookingDate']);
        Route::get('/barber-bookings/order-bookings', [BookingApproverManagementController::class, 'getOrderBarberBooking']);
        Route::put('/system-references', [BookingApproverManagementController::class, 'updateSystemReferences']);
    });

// Managing the bookings. Every role with a managing permission reaches these routes; what each may see and do is decided per booking
// type (and unit) by AccessScope and the Workflow, so a role that has no turn gets a refusal, not an empty menu.
Route::middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|transportation_control_officer'])
    ->prefix('bookings-approver-management')
    ->group(function () {
        // The bookings: the list with stage and age, one booking in full, the status change and the correction of date/time values.
        Route::controller(ManagementBookingController::class)->group(function () {
            Route::get('/bookings', 'index');
            Route::get('/bookings/{serial}', 'show')->whereNumber('serial');
            Route::get('/bookings/{serial}/attachments/{kind}/{id}', 'attachment')->whereNumber('serial')->whereIn('kind', ['file', 'image'])->whereNumber('id');
            Route::post('/bookings/{serial}/status', 'changeStatus')->whereNumber('serial');
            Route::put('/bookings/{serial}/values', 'updateValues')->whereNumber('serial');
            // This system never opens a booking: the attempt is answered with 405 and audited.
            Route::match(['post', 'put'], '/bookings', 'createBlocked');
            Route::post('/bookings/{bookingTypeId}/create', 'createBlocked')->whereNumber('bookingTypeId');
        });

        // The settings of a booking type in a unit, and what blocking dates would cancel.
        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::get('/settings/{unitId}/{bookingTypeId}', 'settings')->whereNumber(['unitId', 'bookingTypeId']);
            Route::post('/settings/references/{id}/impact', 'restrictionImpact')->whereNumber('id');
        });

        // What a screen opens with, and the figures. (The domain dashboard and the bottlenecks are for the roles that lead a domain; the
        // service says no to the others.)
        Route::controller(ManagementInsightController::class)->group(function () {
            Route::get('/me', 'me');
            Route::get('/catalog', 'catalog');
            Route::get('/overview', 'overview');
            Route::get('/bottlenecks', 'bottlenecks');
            Route::get('/domain-dashboard', 'domainDashboard');
        });
    });

Route::controller(BookingAdminManagementController::class)
    ->prefix('bookings-admin-management')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin'])
    ->group(function () {
        Route::post('/assign-approver-role', [BookingAdminManagementController::class, 'assignApproverRole']);
        Route::get('/get-approvers', [BookingAdminManagementController::class, 'getApprovers']);
        Route::get('/get-approver-permissions/{userUuid}', [BookingAdminManagementController::class, 'getApproverPermissions']);
    });

// The admin (camp commander) and the super admin: the handling targets of the booking types, the main screen and the reports.
Route::middleware(['auth:api', 'throttle:api', 'role:super_admin|admin'])
    ->prefix('bookings-admin-management')
    ->group(function () {
        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::get('/targets', 'targets');
            Route::put('/targets', 'setTarget');
        });

        Route::controller(ManagementInsightController::class)->group(function () {
            Route::get('/home', 'home');
            Route::get('/reports', 'reports');
        });

        // Which department a person is in (an admin: the people of their own unit).
        Route::put('/users/{uuid}/department', [ManagementOrgController::class, 'setUserDepartment'])->whereUuid('uuid');
    });

Route::controller(BookingSuperAdminManagementController::class)
    ->prefix('bookings-super-admin-management')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin'])
    ->group(function () {
        Route::post('/assign-admin-role', [BookingSuperAdminManagementController::class, 'assignAdminRole']);
        Route::get('/get-admins', [BookingSuperAdminManagementController::class, 'getAdmins']);
        Route::get('/get-admin-permissions/{userUuid}', [BookingSuperAdminManagementController::class, 'getAdminPermissions']);
        Route::get('/index-selects/{unitId}/{bookingType}', [BookingSuperAdminManagementController::class, 'indexSelects']);
        Route::get('/get-select-options/{formFieldId}', [BookingSuperAdminManagementController::class, 'getSelectOptions']);
        Route::get('/get-options', [BookingSuperAdminManagementController::class, 'getOptions']);
        Route::get('/get-statement/{bookingTypeId}', [BookingSuperAdminManagementController::class, 'getStatement']);
        Route::get('/get-siblings-options/{optionFieldId}', [BookingSuperAdminManagementController::class, 'getSiblingsOptions']);
        Route::put('/update-select-option/{formFieldId}', [BookingSuperAdminManagementController::class, 'updateSelectOptions']);
        Route::put('/update-options/{formFieldId}', [BookingSuperAdminManagementController::class, 'updateOptions']);
        Route::put('/update-option/{formFieldId}', [BookingSuperAdminManagementController::class, 'updateOption']);
        Route::put('/update-statement/{unitId}/{bookingTypeId}', [BookingSuperAdminManagementController::class, 'updateStatement']);
        Route::delete('/remove-options/{formFieldId}', [BookingSuperAdminManagementController::class, 'removeSelectOptions']);
        Route::delete('/remove-option/{formFieldId}', [BookingSuperAdminManagementController::class, 'removeOption']);
    });

// The super admin: the organisation (units and departments) and the audit log.
Route::middleware(['auth:api', 'throttle:api', 'role:super_admin'])
    ->prefix('bookings-super-admin-management')
    ->group(function () {
        Route::controller(ManagementOrgController::class)->group(function () {
            Route::get('/org', 'listing');
            Route::post('/org/units', 'createUnit');
            Route::put('/org/units/{id}', 'updateUnit')->whereNumber('id');
            Route::post('/org/units/{unitId}/departments', 'createDepartment')->whereNumber('unitId');
            Route::put('/org/departments/{id}', 'updateDepartment')->whereNumber('id');
        });

        // The form of a booking type: add, change, move and remove its fields, and whether an approver may correct the value of a date / time field.
        // A change is told to the mobile app (system_config); a field with values is archived, never deleted.
        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::post('/form-fields/{unitId}/{bookingTypeId}', 'createField')->whereNumber(['unitId', 'bookingTypeId']);
            Route::put('/form-fields/{unitId}/{bookingTypeId}/order', 'reorderFields')->whereNumber(['unitId', 'bookingTypeId']);
            Route::put('/form-fields/{id}/editable', 'setFieldEditable')->whereNumber('id');
            Route::put('/form-fields/{id}', 'updateField')->whereNumber('id');
            Route::delete('/form-fields/{id}', 'removeField')->whereNumber('id');
        });

        // The traffic with the mobile app: the files sent back, the decisions waiting to be sent, the sync conflicts.
        Route::controller(ManagementSyncController::class)->group(function () {
            Route::get('/sync', 'overview');
            Route::get('/sync/files/{id}', 'file')->whereNumber('id');
            Route::post('/sync/files/{id}/retry', 'retry')->whereNumber('id');
            Route::post('/sync/conflicts/{id}/resolve', 'resolve')->whereNumber('id');
            Route::post('/sync/run', 'run');
        });

        // The audit log is append-only: reading it and checking its chain are the only things allowed; any other attempt is refused and audited.
        Route::controller(ManagementAuditController::class)->group(function () {
            Route::get('/audit', 'index');
            Route::get('/audit/verify', 'verify');
            Route::match(['put', 'patch', 'delete'], '/audit/{id}', 'immutable');
        });
    });
