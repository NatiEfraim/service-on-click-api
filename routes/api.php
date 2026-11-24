<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingAdminManagementController;
use App\Http\Controllers\BookingApproverManagementController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingSuperAdminManagementController;
use App\Http\Controllers\ManagementApplicationSyncStatusController;
use App\Http\Controllers\ManagementAuditController;
use App\Http\Controllers\ManagementBookingController;
use App\Http\Controllers\ManagementBookingReportController;
use App\Http\Controllers\ManagementDigitalHatchController;
use App\Http\Controllers\ManagementInsightController;
use App\Http\Controllers\ManagementOrgController;
use App\Http\Controllers\ManagementSettingsController;
use App\Http\Controllers\ManagementSyncController;
use App\Http\Controllers\RequesterController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->middleware(['throttle:api']);
    Route::post('/logout', 'logout')->middleware(['auth:api', 'throttle:api']);
    Route::get('/user', [UserController::class, 'user'])->middleware(['auth:api', 'throttle:api']);
});



Route::controller(BookingController::class)
    ->prefix('bookings')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|transportation_control_officer|in_unit_food_control_officer|user|infosec_officer|ict_handler|department_security_officer|interview_coordinator'])
    ->group(function () {
        Route::get('/my-bookings', 'myBookings');
        Route::get('/{bookingUuid}', 'show')->whereUuid('bookingUuid');
    });

// Requester portal (web submission): catalog, form, person lookup, counters, create booking.
Route::controller(RequesterController::class)
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|transportation_control_officer|in_unit_food_control_officer|user|infosec_officer|ict_handler|department_security_officer|interview_coordinator'])
    ->group(function () {
        Route::prefix('requester')->group(function () {
            Route::get('/catalog', 'catalog');
            Route::get('/form/{bookingTypeId}', 'form')->whereNumber('bookingTypeId');
            // A personal-data oracle: its own, tighter limit.
            Route::get('/person-lookup', 'personLookup')->middleware('throttle:20,1');
            Route::get('/counters', 'counters');
        });
        Route::post('/bookings/{bookingTypeId}', 'store')->whereNumber('bookingTypeId');
    });


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


Route::middleware(['auth:api', 'throttle:api', 'role:super_admin|admin|approver|vice_approver|transportation_control_officer|in_unit_food_control_officer|infosec_officer|ict_handler|interview_coordinator'])
    ->prefix('bookings-approver-management')
    ->group(function () {
        Route::controller(ManagementBookingController::class)->group(function () {
            Route::get('/bookings', 'index');
            // Every booking route takes the booking's uuid; the serial variants stay for one release (the transition the frontend asked for).
            Route::get('/bookings/{uuid}', 'show')->whereUuid('uuid');
            Route::get('/bookings/{serial}', 'show')->whereNumber('serial');
            Route::get('/bookings/{uuid}/attachments/{kind}/{id}', 'attachment')
                ->whereUuid('uuid')->whereIn('kind', ['file', 'image'])->whereNumber('id');
            Route::get('/bookings/{serial}/attachments/{kind}/{id}', 'attachment')
                ->whereNumber('serial')->whereIn('kind', ['file', 'image'])->whereNumber('id');
            Route::post('/bookings/{uuid}/status', 'changeStatus')->whereUuid('uuid');
            Route::post('/bookings/{serial}/status', 'changeStatus')->whereNumber('serial');
            Route::put('/bookings/{uuid}/values', 'updateValues')->whereUuid('uuid');
            Route::put('/bookings/{serial}/values', 'updateValues')->whereNumber('serial');
            // Route types (the clearance grant): the assigned handler (מטפל) and who may be assigned.
            Route::put('/bookings/{uuid}/handler', 'setHandler')->whereUuid('uuid');
            Route::put('/bookings/{serial}/handler', 'setHandler')->whereNumber('serial');
            Route::get('/bookings/{uuid}/handler-candidates', 'handlerCandidates')->whereUuid('uuid');
            Route::get('/bookings/{serial}/handler-candidates', 'handlerCandidates')->whereNumber('serial');
            Route::match(['post', 'put'], '/bookings', 'createBlocked');
            Route::post('/bookings/{bookingTypeId}/create', 'createBlocked')->whereNumber('bookingTypeId');
        });

        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::get('/settings/{unitId}/{bookingTypeId}', 'settings')->whereNumber(['unitId', 'bookingTypeId']);
            Route::post('/settings/references/{id}/impact', 'restrictionImpact')->whereNumber('id');
        });

        Route::controller(ManagementInsightController::class)->group(function () {
            Route::get('/me', 'me');
            Route::get('/catalog', 'catalog');
            Route::get('/overview', 'overview');
            Route::get('/bottlenecks', 'bottlenecks');
            Route::get('/domain-dashboard', 'domainDashboard');
        });

        // The handler dropdown of the request list: [{uuid, full_name}] (admin and super admin; the paged get-approvers stays for the users screen).
        Route::get('/handlers', [BookingAdminManagementController::class, 'getHandlers']);
    });

Route::middleware(['auth:api', 'throttle:api', 'role:super_admin|admin'])
    ->group(function () {
        Route::controller(UserController::class)
            ->prefix('users')
            ->group(function () {
                Route::get('/lookup-vatican', 'lookupVatican');
                Route::get('/{identifier?}', 'index');
                Route::post('/', 'store');
            });

        Route::prefix('bookings-admin-management')->group(function () {
            Route::controller(BookingAdminManagementController::class)->group(function () {
                Route::post('/assign-approver-role', 'assignApproverRole');
                Route::get('/get-approvers', 'getApprovers');
                Route::get('/get-approver-permissions/{userUuid}', 'getApproverPermissions');
            });

            Route::controller(ManagementSettingsController::class)->group(function () {
                Route::get('/targets', 'targets');
                Route::put('/targets', 'setTarget');
            });

            Route::controller(ManagementInsightController::class)->group(function () {
                Route::get('/home', 'home');
                Route::get('/reports', 'reports');
            });

            Route::controller(ManagementBookingReportController::class)
                ->prefix('bookings-report')
                ->group(function () {
                    Route::get('/', 'index');
                    Route::post('/export', 'export');
                });

            Route::put('/users/{uuid}/department', [ManagementOrgController::class, 'setUserDepartment'])->whereUuid('uuid');
        });
    });

Route::middleware(['auth:api', 'throttle:api', 'role:super_admin'])
    ->prefix('bookings-super-admin-management')
    ->group(function () {
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

        Route::controller(ManagementOrgController::class)->group(function () {
            Route::get('/org', 'listing');
            Route::post('/org/units', 'createUnit');
            Route::put('/org/units/{id}', 'updateUnit')->whereNumber('id');
            Route::post('/org/units/{unitId}/departments', 'createDepartment')->whereNumber('unitId');
            Route::put('/org/departments/{id}', 'updateDepartment')->whereNumber('id');
        });

        Route::controller(ManagementSettingsController::class)->group(function () {
            Route::post('/form-fields/{unitId}/{bookingTypeId}', 'createField')->whereNumber(['unitId', 'bookingTypeId']);
            Route::put('/form-fields/{unitId}/{bookingTypeId}/order', 'reorderFields')->whereNumber(['unitId', 'bookingTypeId']);
            Route::put('/form-fields/{id}/editable', 'setFieldEditable')->whereNumber('id');
            Route::put('/form-fields/{id}', 'updateField')->whereNumber('id');
            Route::delete('/form-fields/{id}', 'removeField')->whereNumber('id');
        });

        Route::controller(ManagementSyncController::class)->group(function () {
            Route::get('/sync', 'overview');
            Route::get('/sync/files/{id}', 'file')->whereNumber('id');
            Route::post('/sync/files/{id}/retry', 'retry')->whereNumber('id');
            Route::post('/sync/conflicts/{id}/resolve', 'resolve')->whereNumber('id');
            Route::post('/sync/run', 'run');
        });

        Route::controller(ManagementAuditController::class)->group(function () {
            Route::get('/audit', 'index');
            Route::get('/audit/verify', 'verify');
            Route::match(['put', 'patch', 'delete'], '/audit/{id}', 'immutable');
        });

        Route::controller(ManagementApplicationSyncStatusController::class)
            ->prefix('application-sync')
            ->group(function () {
                Route::get('/bookings', 'bookings');
                Route::get('/users', 'users');
            });

        Route::controller(ManagementDigitalHatchController::class)
            ->prefix('digital-hatch')
            ->group(function () {
                Route::match(['get', 'post'], '/export-booking-status', 'exportBookingStatus');
                Route::match(['get', 'post'], '/export-users', 'exportUsers');
                Route::match(['get', 'post'], '/export-system-references', 'exportSystemReferences');
                Route::match(['get', 'post'], '/import-booking-json', 'importBookingJson');
                Route::match(['get', 'post'], '/import-application-log-json', 'importApplicationLogJson');
                Route::match(['get', 'post'], '/import-files', 'importFiles');
            });
    });
