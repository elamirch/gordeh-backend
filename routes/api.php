<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StoredFileController;
use App\Http\Controllers\LabTestController;
use App\Http\Controllers\LabTestFileController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DietPlanController;

use App\Http\Controllers\Provider\PatientController as ProviderPatientController;
use App\Http\Controllers\Provider\RequestController as ProviderRequestController;
use App\Http\Controllers\Provider\AppointmentController as ProviderAppointmentController;
use App\Http\Controllers\Provider\ScheduleController as ProviderScheduleController;
use App\Http\Controllers\Provider\DietPlanController as ProviderDietPlanController;
use App\Http\Controllers\Provider\NotificationController as ProviderNotificationController;
use App\Http\Controllers\Provider\ProfileController as ProviderProfileController;

use App\Http\Controllers\Admin\NutritionMonitoringController;
use App\Http\Controllers\Admin\AlertRuleController;
use App\Http\Controllers\Admin\MonitoringSettingsController;
use App\Http\Controllers\Admin\AuditLogController;

use App\Http\Controllers\NutritionConsultation\TypeController as NutritionTypeController;
use App\Http\Controllers\NutritionConsultation\AvailabilityController as NutritionAvailabilityController;
use App\Http\Controllers\NutritionConsultation\BookingController as NutritionBookingController;
use App\Http\Controllers\NutritionConsultation\PlanController as NutritionPlanController;
use App\Http\Controllers\NutritionConsultation\DocController as NutritionDocController;

use App\Http\Controllers\Support\FaqController as SupportFaqController;
use App\Http\Controllers\Support\TicketController as SupportTicketController;
use App\Http\Controllers\Support\CallbackRequestController as SupportCallbackRequestController;
use App\Http\Controllers\Support\ChatController as SupportChatController;

use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Admin\CallbackRequestController as AdminCallbackRequestController;
use App\Http\Controllers\Admin\FaqController as AdminFaqController;
use App\Http\Controllers\Admin\AgentController as AdminAgentController;
use App\Http\Controllers\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Admin\SupportReportController;

Route::post('auth/send-otp', [AuthController::class, 'sendotp']);
Route::post('auth/authenticate', [AuthController::class, 'authenticate']);
Route::post('auth/refresh', [AuthController::class, 'refreshTokens']);

// Short-lived signed URL for viewing a private lab test file (issued only via the
// authenticated /lab-tests/{labTest}/files and /lab-test-files endpoints above).
Route::get('lab-test-files/{labTestFile}/download', [LabTestFileController::class, 'download'])
    ->name('lab-test-files.download')
    ->middleware('signed');


Route::middleware(['auth:api', 'check_last_logout'])->group(function () {

    // --- Auth ---
    Route::get('auth/profile', [AuthController::class, 'profile']);
    Route::post('auth/token-info', [AuthController::class, 'tokenInfo']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAllDevices']);

    // --- Users ---
    Route::apiResource('users', UserController::class);

    // --- Stored Files ---
    Route::apiResource('stored-files', StoredFileController::class)->except(['update']);

    // --- Lab Tests ---
    Route::post('lab-tests', [LabTestController::class, 'store']);
    Route::get('lab-tests', [LabTestController::class, 'index']);
    Route::get('lab-tests/own', [LabTestController::class, 'indexOwn']);
    Route::get('lab-tests/{id}', [LabTestController::class, 'show']);

    Route::get('lab-tests/user/monthly-avg', [LabTestController::class, 'userMonthlyAvgGfr']);
    Route::get('lab-tests/monthly-avg', [LabTestController::class, 'allUsersMonthlyAvgGfr']);

    // --- Lab Test Files ---
    Route::post('lab-test-files', [LabTestFileController::class, 'store']);
    Route::patch('lab-tests/{labTest}/files', [LabTestFileController::class, 'attach']);
    Route::get('lab-tests/{labTest}/files', [LabTestFileController::class, 'index']);

    // --- Insurances ---
    Route::get('insurances', [InsuranceController::class, 'index']);
    Route::get('insurances/me', [InsuranceController::class, 'indexMe']);
    Route::post('insurances', [InsuranceController::class, 'store']);
    Route::get('insurances/{id}', [InsuranceController::class, 'show']);
    Route::patch('insurances/{id}', [InsuranceController::class, 'update']);
    Route::delete('insurances/{id}', [InsuranceController::class, 'destroy']);

    // --- Admin Dashboard ---
    Route::middleware('is_admin')->group(function () {
        Route::get('admin/dashboard', [AdminDashboardController::class, 'index']);

        Route::prefix('admin/nutrition-monitoring')->group(function () {
            Route::get('overview', [NutritionMonitoringController::class, 'overview']);

            Route::get('queue', [NutritionMonitoringController::class, 'queue']);
            Route::post('queue/{consultationBooking}/remind', [NutritionMonitoringController::class, 'remindQueueItem']);
            Route::patch('queue/{consultationBooking}', [NutritionMonitoringController::class, 'reassignQueueItem']);

            Route::get('specialists', [NutritionMonitoringController::class, 'specialists']);

            Route::get('alert-rules', [AlertRuleController::class, 'index']);
            Route::patch('alert-rules/{alertRule}', [AlertRuleController::class, 'update']);
            Route::get('breaches', [AlertRuleController::class, 'breaches']);

            Route::get('settings', [MonitoringSettingsController::class, 'show']);
            Route::patch('settings', [MonitoringSettingsController::class, 'update']);

            Route::get('audit-log', [AuditLogController::class, 'index']);
        });
    });

    // --- Payments ---
    Route::post('/payments/request', [PaymentController::class, 'request']);
    Route::post('/payments/verify', [PaymentController::class, 'verify']);
    Route::get('/payments/getOwnPayments', [PaymentController::class, 'getOwnPayments']);

    // --- Diet ---
    Route::apiResource('/diet-plans', DietPlanController::class);

    // --- Provider Panel ---
    Route::prefix('provider')->middleware('is_provider')->group(function () {
        Route::get('patients', [ProviderPatientController::class, 'index']);
        Route::get('patients/{user}', [ProviderPatientController::class, 'show']);
        Route::post('patients/{patient}/diet-plans', [ProviderDietPlanController::class, 'store']);

        Route::get('requests', [ProviderRequestController::class, 'index']);
        Route::patch('requests/{consultationRequest}/status', [ProviderRequestController::class, 'updateStatus']);
        Route::post('requests/{consultationRequest}/calls', [ProviderRequestController::class, 'storeCall']);

        Route::get('appointments', [ProviderAppointmentController::class, 'index']);
        Route::patch('appointments/{appointment}/status', [ProviderAppointmentController::class, 'updateStatus']);

        Route::get('schedule/weekly-hours', [ProviderScheduleController::class, 'weeklyHours']);
        Route::patch('schedule/weekly-hours', [ProviderScheduleController::class, 'updateWeeklyHours']);
        Route::get('schedule/available-slots', [ProviderScheduleController::class, 'availableSlots']);

        Route::get('notifications', [ProviderNotificationController::class, 'index']);
        Route::patch('notifications/read-all', [ProviderNotificationController::class, 'readAll']);

        Route::get('profile', [ProviderProfileController::class, 'show']);
        Route::patch('profile', [ProviderProfileController::class, 'update']);
    });

    // --- Nutrition Consultation ---
    Route::prefix('nutrition-consultation')->group(function () {
        Route::get('types', [NutritionTypeController::class, 'index']);
        Route::get('availability', [NutritionAvailabilityController::class, 'index']);

        Route::post('bookings', [NutritionBookingController::class, 'store']);
        Route::get('upcoming', [NutritionBookingController::class, 'upcoming']);
        Route::get('past', [NutritionBookingController::class, 'past']);
        Route::post('bookings/{appointment}/reschedule', [NutritionBookingController::class, 'reschedule']);
        Route::post('bookings/{appointment}/cancel', [NutritionBookingController::class, 'cancel']);

        Route::get('plans', [NutritionPlanController::class, 'index']);

        Route::get('docs', [NutritionDocController::class, 'index']);
        Route::post('docs', [NutritionDocController::class, 'store']);
        Route::delete('docs/{storedFile}', [NutritionDocController::class, 'destroy']);
    });

    // --- Support Center ---
    Route::prefix('support')->group(function () {
        Route::get('faq', [SupportFaqController::class, 'index']);

        Route::get('tickets', [SupportTicketController::class, 'index']);
        Route::post('tickets', [SupportTicketController::class, 'store']);
        Route::get('tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::get('tickets/{ticket}/messages', [SupportTicketController::class, 'messages']);
        Route::post('tickets/{ticket}/messages', [SupportTicketController::class, 'storeMessage']);

        Route::post('callback-requests', [SupportCallbackRequestController::class, 'store']);

        Route::get('chat/messages', [SupportChatController::class, 'index']);
        Route::post('chat/messages', [SupportChatController::class, 'storeMessage']);
    });

    // --- Support Admin (admin + support_agent; a broader gate than the strict is_admin
    // block above, since agents who aren't full admins still need this slice of /admin/*) ---
    Route::prefix('admin')->middleware('is_support_staff')->group(function () {
        Route::get('tickets/queue-summary', [AdminTicketController::class, 'queueSummary']);
        Route::get('tickets', [AdminTicketController::class, 'index']);
        Route::get('tickets/{ticket}', [AdminTicketController::class, 'show']);
        Route::patch('tickets/{ticket}', [AdminTicketController::class, 'update']);
        Route::post('tickets/{ticket}/messages', [AdminTicketController::class, 'storeMessage']);

        Route::get('callback-requests', [AdminCallbackRequestController::class, 'index']);
        Route::patch('callback-requests/{callbackRequest}', [AdminCallbackRequestController::class, 'update']);

        Route::get('faq', [AdminFaqController::class, 'index']);
        Route::post('faq', [AdminFaqController::class, 'store']);
        Route::patch('faq/{faqItem}', [AdminFaqController::class, 'update']);

        Route::get('agents/shifts', [AdminAgentController::class, 'shifts']);
        Route::patch('agents/shifts', [AdminAgentController::class, 'updateShifts']);
        Route::get('agents', [AdminAgentController::class, 'index']);

        Route::get('chat/sessions', [AdminChatController::class, 'sessions']);
        Route::patch('chat/sessions/{chatSession}', [AdminChatController::class, 'updateSessionStatus']);
        Route::get('chat/messages', [AdminChatController::class, 'index']);
        Route::post('chat/messages', [AdminChatController::class, 'storeMessage']);

        Route::get('support/reports', [SupportReportController::class, 'index']);
    });
});
