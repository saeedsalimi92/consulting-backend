<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Counselor\Controllers\CounselorController;
use App\Modules\Counselor\Controllers\SpecialtyController;
use App\Modules\Counselor\Controllers\CommentController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\{ClientAuthController,AdminBookingController,AdminOperationsController,PaymentController};
use App\Http\Controllers\Booking\FinanceController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Booking\ClinicalController;
use App\Http\Controllers\Booking\ReceptionController;

// Health
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/docs/openapi.json', [\App\Http\Controllers\OpenApiController::class, 'json']);
Route::get('/docs', [\App\Http\Controllers\OpenApiController::class, 'ui'])->middleware('web');
Route::middleware('auth:sanctum')->group(function(){
    Route::get('/account/notification-preferences',[\App\Http\Controllers\Booking\SmsReminderController::class,'preference']);
    Route::patch('/account/notification-preferences',[\App\Http\Controllers\Booking\SmsReminderController::class,'preference']);
});

// Public endpoints consumed by Nuxt 4
Route::get('/counselors', [CounselorController::class, 'index']);
Route::get('/counselors/{counselor}', [CounselorController::class, 'show']);
Route::get('/specialties', [SpecialtyController::class, 'index']);
Route::get('/comments', [CommentController::class, 'index']);

// Public CMS endpoints
Route::get('/content/{type}', [\App\Http\Controllers\Admin\CmsController::class, 'publicIndex']);
Route::get('/content/{type}/{slug}', [\App\Http\Controllers\Admin\CmsController::class, 'show']);
Route::get('/content-media/{media}', [\App\Http\Controllers\Admin\CmsController::class, 'publicMedia']);

// Legacy appointment requests
Route::post('/appointment-requests', [
    \App\Http\Controllers\Admin\ManagementController::class,
    'appointmentStore'
])->middleware('throttle:5,1');

// Admin login
Route::post('/admin/login', [
    \App\Http\Controllers\Admin\AuthController::class,
    'login'
]);
Route::get('/auth/config',[OtpController::class,'config']);
Route::post('/auth/otp/request',[OtpController::class,'request'])->middleware('throttle:otp-request');
Route::post('/auth/otp/resend',[OtpController::class,'resend'])->middleware('throttle:otp-request');
Route::post('/auth/otp/verify',[OtpController::class,'verify'])->middleware('throttle:otp-verify');
Route::post('/client/register',[ClientAuthController::class,'register'])->middleware('throttle:5,1');
Route::post('/client/login',[ClientAuthController::class,'login'])->middleware('throttle:10,1');
Route::post('/counselor/login',[\App\Http\Controllers\Booking\CounselorAuthController::class,'login'])->middleware('throttle:10,1');
Route::post('/client/password/forgot',[ClientAuthController::class,'forgot'])->middleware('throttle:5,1');
Route::post('/client/password/reset',[ClientAuthController::class,'reset'])->middleware('throttle:5,1');
Route::match(['get','post'],'/booking/payments/callback',[PaymentController::class,'callback'])->name('booking.payment.callback')->middleware('throttle:30,1');
Route::get('/booking/fake-bank/payments/{payment}',[PaymentController::class,'fakeCheckout'])->middleware('throttle:30,1');
Route::post('/booking/fake-bank/payments/{payment}/result',[PaymentController::class,'fakeResult'])->middleware('throttle:10,1');
Route::get('/booking/fake-bank/checkout/{payment}',[PaymentController::class,'fakeCheckoutPage'])->middleware('throttle:30,1');

// ======================================================
// Admin routes
// ======================================================

Route::middleware([
    'auth:sanctum',
    \App\Http\Middleware\EnsureAdmin::class
])->prefix('admin')->group(function () {
    Route::middleware('\App\Http\Middleware\EnsureAdmin:appointments.manage')->group(function() {
        Route::get('/booking/reception/dashboard',[ReceptionController::class,'dashboard']);
        Route::get('/booking/rooms',[ReceptionController::class,'rooms']);
        Route::post('/booking/rooms',[ReceptionController::class,'roomStore']);
        Route::patch('/booking/rooms/{room}',[ReceptionController::class,'roomUpdate']);
        Route::get('/booking/rooms/{room}/calendar',[ReceptionController::class,'roomCalendar']);
        Route::get('/booking/rooms/{room}/availability',[ReceptionController::class,'roomAvailability']);
        Route::match(['get','put'],'/booking/rooms/{room}/working-hours',[ReceptionController::class,'roomHours']);
        Route::match(['get','post'],'/booking/rooms/{room}/unavailability',[ReceptionController::class,'roomUnavailability']);
        Route::delete('/booking/room-unavailability/{window}',[ReceptionController::class,'deleteRoomUnavailability']);
        Route::delete('/booking/rooms/{room}',[ReceptionController::class,'roomDestroy']);
        Route::get('/booking/waitlist',[ReceptionController::class,'waitlist']);
        Route::patch('/booking/waitlist/{entry}',[ReceptionController::class,'assignWaitlist']);
        Route::get('/booking/services',[AdminBookingController::class,'services']);
        Route::post('/booking/services',[AdminBookingController::class,'serviceStore']);
        Route::patch('/booking/services/{service}',[AdminBookingController::class,'serviceUpdate']);
        Route::put('/booking/counselors/{counselor}/services',[AdminBookingController::class,'assign']);
        Route::delete('/booking/counselors/{counselor}/services/{service}',[AdminBookingController::class,'unassign']);
        Route::put('/booking/counselors/{counselor}/account',[AdminBookingController::class,'account']);
        Route::get('/booking/counselors/{counselor}/shifts',[AdminBookingController::class,'shifts']);
        Route::post('/booking/counselors/{counselor}/shifts',[AdminBookingController::class,'shiftStore']);
        Route::patch('/booking/shifts/{shift}',[AdminBookingController::class,'shiftUpdate']);
        Route::get('/booking/counselors/{counselor}/time-off',[AdminBookingController::class,'timeOff']);
        Route::post('/booking/counselors/{counselor}/time-off',[AdminBookingController::class,'timeOffStore']);
        Route::delete('/booking/time-off/{id}',[AdminBookingController::class,'timeOffDelete']);
        Route::get('/booking/calendar',[AdminBookingController::class,'calendar']);
        Route::get('/booking/clients',[AdminBookingController::class,'clients']);
        Route::post('/booking/clients',[AdminBookingController::class,'clientStore']);
        Route::get('/booking/reservations',[AdminOperationsController::class,'bookings']);
        Route::get('/booking/reservations/{booking}',[AdminOperationsController::class,'show']);
        Route::post('/booking/reservations',[AdminOperationsController::class,'create']);
        Route::patch('/booking/reservations/{booking}/status',[AdminOperationsController::class,'sessionStatus']);
        Route::patch('/booking/reservations/{booking}/reschedule',[AdminOperationsController::class,'reschedule']);
        Route::post('/booking/reservations/{booking}/cancel',[AdminOperationsController::class,'cancel']);
        Route::patch('/booking/reservations/{booking}/session-details',[AdminOperationsController::class,'sessionDetails']);
        Route::post('/booking/reservations/{booking}/check-in',[ReceptionController::class,'checkIn']);
        Route::patch('/booking/reservations/{booking}/room',[ReceptionController::class,'assignRoom']);
        Route::post('/booking/reservations/{booking}/payments/in-person',[PaymentController::class,'recordInPerson']);
        Route::get('/booking/notifications',[AdminOperationsController::class,'notifications']);
        Route::get('/booking/notification-templates',[AdminOperationsController::class,'notificationTemplates']);
        Route::post('/booking/notification-templates',[AdminOperationsController::class,'notificationTemplates']);
        Route::post('/booking/notifications/{notice}/retry',[AdminOperationsController::class,'retryNotification']);
    });
    Route::middleware('\App\Http\Middleware\EnsureAdmin:notes.manage')->group(function() {
        Route::get('/booking/clinical/clients/{client}',[ClinicalController::class,'record']);
        Route::put('/booking/clinical/clients/{client}',[ClinicalController::class,'record']);
        Route::get('/booking/clinical/clients/{client}/intake-answers',[ClinicalController::class,'answers']);
        Route::get('/booking/clinical/clients/{client}/consents',[ClinicalController::class,'consents']);
        Route::get('/booking/clinical/clients/{client}/follow-ups',[ClinicalController::class,'followUps']);
        Route::post('/booking/clinical/clients/{client}/follow-ups',[ClinicalController::class,'followUps']);
        Route::post('/booking/clinical/clients/{client}/attachments',[ClinicalController::class,'upload']);
        Route::get('/booking/clinical/clients/{client}/attachments',[ClinicalController::class,'attachments']);
        Route::get('/booking/clinical/attachments/{attachment}',[ClinicalController::class,'download']);
        Route::get('/booking/clinical/forms',[ClinicalController::class,'forms']);
        Route::post('/booking/clinical/forms',[ClinicalController::class,'forms']);
        Route::post('/booking/clinical/forms/{form}/versions',[ClinicalController::class,'newFormVersion']);
        Route::get('/booking/reservations/{booking}/session-notes',[ClinicalController::class,'notes']);
        Route::post('/booking/reservations/{booking}/session-notes',[ClinicalController::class,'notes']);
        Route::get('/booking/session-notes/{note}/versions',[ClinicalController::class,'noteVersions']);
        Route::get('/booking/session-notes/{note}/versions/{version}',[ClinicalController::class,'noteVersion']);
        Route::post('/booking/clinical/follow-ups/{task}/complete',[ClinicalController::class,'completeFollowUp']);
    });
    Route::middleware('\App\Http\Middleware\EnsureAdmin:finance.view')->group(function() {
        Route::get('/booking/finance/dashboard',[FinanceController::class,'dashboard']);
        Route::get('/booking/refunds',[AdminOperationsController::class,'refunds']);
        Route::get('/booking/payments',[AdminOperationsController::class,'payments']);
        Route::get('/booking/payments/export',[AdminOperationsController::class,'exportPayments']);
        Route::get('/booking/reports',[AdminOperationsController::class,'reports']);
        Route::get('/booking/reports/export',[AdminOperationsController::class,'exportReports']);
        Route::get('/booking/counselors/{counselor}/payable',[FinanceController::class,'payable']);
        Route::get('/booking/settlements',[FinanceController::class,'settlements']);
        Route::get('/booking/reconciliation',[FinanceController::class,'reconcile']);
    });
    Route::middleware('\App\Http\Middleware\EnsureAdmin:notes.manage')->group(function() {
        Route::get('/booking/reservations/{booking}/notes',[AdminOperationsController::class,'notes']);
        Route::post('/booking/reservations/{booking}/notes',[AdminOperationsController::class,'noteStore']);
    });
    Route::middleware('\App\Http\Middleware\EnsureAdmin:finance.manage')->group(function() {
        Route::get('/booking/promo-codes',[FinanceController::class,'promoCodes']);
        Route::post('/booking/promo-codes',[FinanceController::class,'promoCodeStore']);
        Route::patch('/booking/promo-codes/{promo}',[FinanceController::class,'promoCodeUpdate']);
        Route::post('/booking/reservations/{booking}/refunds',[AdminOperationsController::class,'partialRefundRequest']);
        Route::patch('/booking/refunds/{refund}',[AdminOperationsController::class,'refundDecision']);
        Route::post('/booking/settlements',[FinanceController::class,'recordSettlement']);
        Route::post('/booking/refunds/{refund}/record-transfer',[FinanceController::class,'markRefunded']);
        Route::get('/booking/gateways',[\App\Http\Controllers\Booking\BookingSettingsController::class,'gateways']);
        Route::put('/booking/gateways',[\App\Http\Controllers\Booking\BookingSettingsController::class,'updateGateway']);
        Route::get('/booking/tax-rules',[\App\Http\Controllers\Booking\BookingSettingsController::class,'taxRules']);
        Route::post('/booking/services/{service}/tax-rules',[\App\Http\Controllers\Booking\BookingSettingsController::class,'saveTaxRule']);
        Route::get('/booking/policy',[\App\Http\Controllers\Booking\BookingSettingsController::class,'policy']);
        Route::put('/booking/policy',[\App\Http\Controllers\Booking\BookingSettingsController::class,'updatePolicy']);
    });
    Route::middleware('\App\Http\Middleware\EnsureAdmin:settings.manage')->prefix('settings/sms-reminders')->group(function() {
        Route::get('/rules',[\App\Http\Controllers\Booking\SmsReminderController::class,'rules']);
        Route::post('/rules',[\App\Http\Controllers\Booking\SmsReminderController::class,'rules']);
        Route::patch('/rules/{rule}',[\App\Http\Controllers\Booking\SmsReminderController::class,'update']);
        Route::post('/rules/{rule}/preview',[\App\Http\Controllers\Booking\SmsReminderController::class,'preview']);
        Route::delete('/rules/{rule}',[\App\Http\Controllers\Booking\SmsReminderController::class,'destroy']);
        Route::get('/scheduled',[\App\Http\Controllers\Booking\SmsReminderController::class,'scheduled']);
        Route::get('/history',[\App\Http\Controllers\Booking\SmsReminderController::class,'history']);
        Route::get('/attempts',[\App\Http\Controllers\Booking\SmsReminderController::class,'attempts']);
        Route::post('/{reminder}/retry',[\App\Http\Controllers\Booking\SmsReminderController::class,'retry']);
        Route::post('/{reminder}/cancel',[\App\Http\Controllers\Booking\SmsReminderController::class,'cancel']);
    });
    Route::middleware('\App\Http\Middleware\EnsureAdmin:settings.manage')->group(function() {
        Route::match(['get','put'],'/settings/center-profile',[\App\Http\Controllers\Admin\SettingsController::class,'centerProfile']);
        Route::match(['get','put'],'/settings/working-hours',[\App\Http\Controllers\Admin\SettingsController::class,'operatingHours']);
        Route::match(['get','put'],'/settings/sms',[\App\Http\Controllers\Admin\SettingsController::class,'sms']);
        Route::match(['get','put'],'/settings/seo',[\App\Http\Controllers\Admin\SettingsController::class,'seo']);
        Route::get('/settings/holidays',[\App\Http\Controllers\Booking\ReceptionController::class,'holidays']);
        Route::post('/settings/holidays',[\App\Http\Controllers\Booking\ReceptionController::class,'holidays']);
        Route::delete('/settings/holidays/{holiday}',[\App\Http\Controllers\Booking\ReceptionController::class,'deleteHoliday']);
    });
    Route::get('/booking/reports/operational',[AdminOperationsController::class,'operationalReport']);
    Route::get('/booking/exports/{type}.xlsx',[\App\Http\Controllers\Booking\ExportController::class,'export']);

    // Authentication
    Route::get('/me', [
        \App\Http\Controllers\Admin\AuthController::class,
        'me'
    ]);
    Route::get('/session',[\App\Http\Controllers\AccountSecurityController::class,'session']);
    Route::put('/password',[\App\Http\Controllers\AccountSecurityController::class,'changePassword']);
    Route::post('/logout-all',[\App\Http\Controllers\AccountSecurityController::class,'logoutEverywhere']);

    Route::post('/logout', [
        \App\Http\Controllers\Admin\AuthController::class,
        'logout'
    ]);

    // Dashboard
    Route::get('/dashboard', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'dashboard'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:dashboard.view');

    // Users
    Route::get('/users', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'users'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:users.manage');

    Route::get('/roles', [\App\Http\Controllers\Admin\AdminAccessController::class, 'roles'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::post('/roles', [\App\Http\Controllers\Admin\AdminAccessController::class, 'createRole'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::get('/permissions', [\App\Http\Controllers\Admin\AdminAccessController::class, 'permissions'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::patch('/roles/{role}', [\App\Http\Controllers\Admin\AdminAccessController::class, 'updateRole'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::delete('/roles/{role}', [\App\Http\Controllers\Admin\AdminAccessController::class, 'deleteRole'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::get('/users/{user}/roles', [\App\Http\Controllers\Admin\AdminAccessController::class, 'userRoles'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::put('/users/{user}/roles', [\App\Http\Controllers\Admin\AdminAccessController::class, 'assignUserRoles'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::get('/audit-log', [\App\Http\Controllers\Admin\AdminAccessController::class, 'auditLog'])->middleware('\\App\\Http\\Middleware\\EnsureAdmin:users.manage');
    Route::get('/notifications', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'index']);
    Route::get('/notifications/{notification}', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'show']);
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'read']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'readAll']);

    Route::post('/users', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'userStore'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:users.manage');

    Route::patch('/users/{user}', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'userUpdate'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:users.manage');

    Route::delete('/users/{user}', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'userDestroy'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:users.manage');

    // Settings
    Route::get('/settings', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'settings'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:settings.manage');

    Route::put('/settings', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'settingUpdate'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:settings.manage');

    // Legacy appointments
    Route::get('/appointments', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'appointments'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:appointments.manage');

    Route::patch('/appointments/{id}', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'appointmentUpdate'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:appointments.manage');

    // Counselors
    Route::get('/counselors', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'counselors'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:counselors.manage');

    Route::post('/counselors', [
        CounselorController::class,
        'store'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:counselors.manage');

    Route::put('/counselors/{counselor}', [
        CounselorController::class,
        'update'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:counselors.manage');

    // Multipart form support
    Route::post('/counselors/{counselor}', [
        CounselorController::class,
        'update'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:counselors.manage');

    Route::delete('/counselors/{counselor}', [
        CounselorController::class,
        'destroy'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:counselors.manage');

    // Specialties
    Route::get('/specialties', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'specialties'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:specialties.manage');

    Route::post('/specialties', [
        SpecialtyController::class,
        'store'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:specialties.manage');

    Route::put('/specialties/{specialty}', [
        SpecialtyController::class,
        'update'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:specialties.manage');

    Route::delete('/specialties/{specialty}', [
        SpecialtyController::class,
        'destroy'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:specialties.manage');

    // Comments
    Route::get('/comments', [
        \App\Http\Controllers\Admin\ManagementController::class,
        'comments'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:comments.manage');

    Route::post('/comments', [
        CommentController::class,
        'store'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:comments.manage');

    Route::patch('/comments/{comment}', [
        CommentController::class,
        'update'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:comments.manage');

    Route::delete('/comments/{comment}', [
        CommentController::class,
        'destroy'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:comments.manage');

    // CMS
    Route::get('/content/{type}', [
        \App\Http\Controllers\Admin\CmsController::class,
        'index'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');

    Route::post('/content/{type}', [
        \App\Http\Controllers\Admin\CmsController::class,
        'store'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');

    Route::put('/content/{type}/{entry}', [
        \App\Http\Controllers\Admin\CmsController::class,
        'update'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');

    Route::delete('/content/{type}/{entry}', [
        \App\Http\Controllers\Admin\CmsController::class,
        'destroy'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');

    // Gallery upload
    Route::post('/gallery/upload', [
        \App\Http\Controllers\Admin\CmsController::class,
        'uploadGalleryImage'
    ])->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');
    Route::get('/content-media', [\App\Http\Controllers\Admin\CmsController::class, 'mediaIndex'])
        ->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');
    Route::delete('/content-media/{media}', [\App\Http\Controllers\Admin\CmsController::class, 'mediaDestroy'])
        ->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');
    Route::get('/content-media/{media}/preview', [\App\Http\Controllers\Admin\CmsController::class, 'adminMedia'])
        ->middleware('\App\Http\Middleware\EnsureAdmin:content.manage');

});

// ======================================================
// Booking system — Public endpoints
// ======================================================

Route::get('/booking/services', [
    BookingController::class,
    'services'
]);
Route::get('/booking/counselors/{counselor}/services',[BookingController::class,'counselorServices']);

Route::get('/booking/availability', [
    BookingController::class,
    'availability'
]);

// ======================================================
// Booking system — Authenticated users
// ======================================================

Route::middleware(['auth:sanctum',\App\Http\Middleware\EnsureClient::class])->group(function () {
    Route::middleware(\App\Http\Middleware\EnsureClient::class)->group(function() {
        Route::get('/client/me',[ClientAuthController::class,'me']);
        Route::get('/auth/me',[OtpController::class,'me']);
        Route::put('/auth/profile',[OtpController::class,'updateProfile']);
        Route::post('/auth/password/set',[OtpController::class,'setPassword']);
        Route::post('/auth/otp/step-up/request',[OtpController::class,'requestStepUp'])->middleware('throttle:otp-request');
        Route::post('/auth/otp/step-up/verify',[OtpController::class,'verifyStepUp'])->middleware('throttle:otp-verify');
        Route::post('/auth/mobile/change/request',[OtpController::class,'requestMobileChange'])->middleware('throttle:otp-request');
        Route::post('/auth/mobile/change/verify',[OtpController::class,'verifyMobileChange'])->middleware('throttle:otp-verify');
        Route::get('/client/notification-preferences',[\App\Http\Controllers\Booking\SmsReminderController::class,'preference']);
        Route::patch('/client/notification-preferences',[\App\Http\Controllers\Booking\SmsReminderController::class,'preference']);
        Route::patch('/client/me',[ClientAuthController::class,'update']);
        Route::post('/client/logout',[ClientAuthController::class,'logout']);
        Route::get('/client/session',[\App\Http\Controllers\AccountSecurityController::class,'session']);
        Route::put('/client/password',[\App\Http\Controllers\AccountSecurityController::class,'changePassword']);
        Route::post('/client/logout-all',[\App\Http\Controllers\AccountSecurityController::class,'logoutEverywhere']);
    });

    Route::post('/booking/reservations', [
        BookingController::class,
        'store'
    ])->middleware('throttle:booking-reservation');
    Route::post('/booking/waitlist',[BookingController::class,'joinWaitlist'])->middleware('throttle:booking-reservation');
    Route::delete('/booking/waitlist/{entry}',[BookingController::class,'leaveWaitlist']);
    Route::post('/booking/intake-forms/{form}/answers',[ClinicalController::class,'submitIntake']);
    Route::get('/booking/intake-forms/{form}',[ClinicalController::class,'intakeForm']);
    Route::post('/client/consents',[ClinicalController::class,'consent']);

    Route::get('/booking/my-reservations', [
        BookingController::class,
        'mine'
    ]);
    Route::get('/booking/my-reservations/{booking}',[BookingController::class,'show']);
    Route::get('/booking/my-reservations/{booking}/payments',[PaymentController::class,'mine']);
    Route::get('/booking/my-reservations/{booking}/receipts',[PaymentController::class,'receipts']);
    Route::get('/client/notifications',[\App\Http\Controllers\Booking\InAppNotificationController::class,'index']);
    Route::post('/client/notifications/{notification}/read',[\App\Http\Controllers\Booking\InAppNotificationController::class,'read']);
    Route::post('/booking/my-reservations/{booking}/payments',[PaymentController::class,'initiate'])->middleware('throttle:booking-payment');

    Route::get('/booking/my-reservations/{booking}/notes', [
        BookingController::class,
        'notes'
    ]);

    Route::post('/booking/my-reservations/{booking}/cancellation-request', [
        BookingController::class,
        'cancelRequest'
    ])->middleware('throttle:booking-cancellation');
    Route::patch('/booking/my-reservations/{booking}/reschedule',[BookingController::class,'reschedule'])
        ->middleware('throttle:booking-cancellation');

});

Route::middleware(['auth:sanctum',\App\Http\Middleware\EnsureCounselor::class])->prefix('counselor')->group(function () {
    Route::get('/session',[\App\Http\Controllers\AccountSecurityController::class,'session']);
    Route::put('/password',[\App\Http\Controllers\AccountSecurityController::class,'changePassword']);
    Route::post('/logout-all',[\App\Http\Controllers\AccountSecurityController::class,'logoutEverywhere']);
    Route::post('/logout', function (\Illuminate\Http\Request $request) {
        $request->user()->currentAccessToken()?->delete();
        return response()->noContent();
    });
    Route::get('/appointments',[\App\Http\Controllers\Booking\CounselorPortalController::class,'appointments']);
    Route::patch('/appointments/{booking}/status',[\App\Http\Controllers\Booking\CounselorPortalController::class,'status']);
    Route::patch('/appointments/{booking}/session-details',[\App\Http\Controllers\Booking\CounselorPortalController::class,'sessionDetails']);
    Route::get('/appointments/{booking}/notes',[ClinicalController::class,'notes']);
    Route::post('/appointments/{booking}/notes',[ClinicalController::class,'notes']);
    Route::patch('/session-notes/{note}',[ClinicalController::class,'updateNote']);
    Route::get('/session-notes/{note}/versions',[ClinicalController::class,'noteVersions']);
    Route::get('/session-notes/{note}/versions/{version}',[ClinicalController::class,'noteVersion']);
    Route::get('/clients/{client}/clinical-record',[ClinicalController::class,'record']);
    Route::put('/clients/{client}/clinical-record',[ClinicalController::class,'record']);
    Route::get('/clients/{client}/intake-answers',[ClinicalController::class,'answers']);
    Route::get('/clients/{client}/consents',[ClinicalController::class,'consents']);
    Route::get('/clients/{client}/follow-ups',[ClinicalController::class,'followUps']);
    Route::post('/clients/{client}/follow-ups',[ClinicalController::class,'followUps']);
    Route::post('/clients/{client}/attachments',[ClinicalController::class,'upload']);
    Route::get('/clients/{client}/attachments',[ClinicalController::class,'attachments']);
    Route::get('/clinical/attachments/{attachment}',[ClinicalController::class,'download']);
    Route::post('/follow-ups/{task}/complete',[ClinicalController::class,'completeFollowUp']);
    Route::get('/finances',[\App\Http\Controllers\Booking\CounselorPortalController::class,'finances']);
    Route::get('/availability',[\App\Http\Controllers\Booking\CounselorPortalController::class,'availability']);
    Route::post('/time-off',[\App\Http\Controllers\Booking\CounselorPortalController::class,'timeOff']);
    Route::get('/notifications',[\App\Http\Controllers\Booking\InAppNotificationController::class,'index']);
    Route::post('/notifications/{notification}/read',[\App\Http\Controllers\Booking\InAppNotificationController::class,'read']);
});
