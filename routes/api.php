<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingAdminManagementController;
use App\Http\Controllers\BookingApproverManagementController;
use App\Http\Controllers\BookingSuperAdminManagementController;
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

Route::controller(BookingAdminManagementController::class)
    ->prefix('bookings-admin-management')
    ->middleware(['auth:api', 'throttle:api', 'role:super_admin|admin'])
    ->group(function () {
        Route::post('/assign-approver-role', [BookingAdminManagementController::class, 'assignApproverRole']);
        Route::get('/get-approvers', [BookingAdminManagementController::class, 'getApprovers']);
        Route::get('/get-approver-permissions/{userUuid}', [BookingAdminManagementController::class, 'getApproverPermissions']);
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
