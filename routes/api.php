<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StoredFileController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LabTestController;
use App\Http\Controllers\LabTestFileController;
use App\Http\Controllers\Admin\LabTestFileController as AdminLabTestFileController;
use App\Http\Controllers\Admin\LabTestOcrErrorController as AdminLabTestOcrErrorController;
use App\Http\Controllers\Admin\PushSubscriptionController as AdminPushSubscriptionController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\MobilePaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\QuickAssessmentController;
use App\Http\Controllers\Admin\QuickAssessmentController as AdminQuickAssessmentController;
use App\Http\Controllers\Webinar\WebinarController;
use App\Http\Controllers\Nephrology\DoctorController as NephrologyDoctorController;
use App\Http\Controllers\Nephrology\ConsultationController as NephrologyConsultationController;
use App\Http\Controllers\Nephrologist\ApplicationController as NephrologistApplicationController;
use App\Http\Controllers\Nephrologist\ConsultationController as NephrologistConsultationController;
use App\Http\Controllers\Nephrologist\PanelController as NephrologistPanelController;
use App\Http\Controllers\Admin\NephrologistController as AdminNephrologistController;
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
use App\Http\Controllers\Admin\SpecialistScheduleController;
use App\Http\Controllers\Admin\CreditController as AdminCreditController;
use App\Http\Controllers\Admin\SmsReminderController as AdminSmsReminderController;

use App\Http\Controllers\NutritionConsultation\TypeController as NutritionTypeController;
use App\Http\Controllers\NutritionConsultation\AvailabilityController as NutritionAvailabilityController;
use App\Http\Controllers\NutritionConsultation\SpecialistController as NutritionSpecialistController;
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
use App\Http\Controllers\Admin\WebinarRegistrationController as AdminWebinarRegistrationController;
use App\Http\Controllers\Webinar\RegistrationController as WebinarRegistrationController;

Route::post('auth/send-otp', [AuthController::class, 'sendotp']);
Route::post('auth/authenticate', [AuthController::class, 'authenticate']);
Route::post('auth/refresh', [AuthController::class, 'refreshTokens']);

// Mobile payment page + gateway callback (public: the token, or the gateway's authority, is the credential).
Route::prefix('payments/mobile')->middleware('throttle:30,1')->group(function () {
    Route::post('callback', [MobilePaymentController::class, 'callback']);
    Route::get('{token}', [MobilePaymentController::class, 'show'])->where('token', '[A-Za-z0-9]{32,128}');
    Route::post('{token}/continue', [MobilePaymentController::class, 'continue'])->where('token', '[A-Za-z0-9]{32,128}');
});

// Short-lived signed URL for viewing a private lab test file (issued only via the
// authenticated /lab-tests/{labTest}/files and /lab-test-files endpoints above).
Route::get('lab-test-files/{labTestFile}/download', [LabTestFileController::class, 'download'])
    ->name('lab-test-files.download')
    ->middleware('signed:relative');


// Signed, short-lived URL for viewing an uploaded file (avatars, documents, diet plans).
Route::get('files', [FileController::class, 'serve'])
    ->name('files.serve')
    ->middleware('signed:relative');

// The webinars on offer (public: the website and the apps list them before anyone signs in).
Route::get('webinars', [WebinarController::class, 'index']);

// The nephrologists on offer (public: the landing page lists them before anyone signs in).
Route::get('nephrology/doctors', [NephrologyDoctorController::class, 'index'])->middleware('throttle:60,1');
Route::get('nephrology/doctors/{id}', [NephrologyDoctorController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1');

// Public webinar sign-up (guests welcome; linked to an account when the phone matches one).
Route::post('webinar-registrations', [WebinarRegistrationController::class, 'store'])
    ->middleware('throttle:webinar-register');

// --- Support chat widget (global: members *and* guests) ---
// `chat.actor` only identifies the caller (JWT member / X-Chat-Token guest); each endpoint
// then enforces what it needs. Limiters are named so every endpoint gets its own bucket.
Route::prefix('support')->group(function () {
    Route::get('chat/config', [SupportChatController::class, 'config'])->middleware('throttle:chat-config');

    Route::middleware('chat.actor')->group(function () {
        Route::post('chat/sessions', [SupportChatController::class, 'start'])->middleware('throttle:chat-session');
        Route::get('chat/sessions/{chatSession}', [SupportChatController::class, 'show']);
        Route::post('chat/sessions/{chatSession}/end', [SupportChatController::class, 'end']);
        Route::post('chat/sessions/{chatSession}/rating', [SupportChatController::class, 'rate']);

        Route::get('chat/messages', [SupportChatController::class, 'index']);
        Route::post('chat/messages', [SupportChatController::class, 'storeMessage']);
        Route::post('chat/uploads', [SupportChatController::class, 'upload'])->middleware('throttle:chat-upload');

        Route::post('callback-requests', [SupportCallbackRequestController::class, 'store'])
            ->middleware('throttle:chat-callback');
    });
});

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
    Route::delete('lab-tests/{id}', [LabTestController::class, 'destroy']);

    Route::get('lab-tests/user/monthly-avg', [LabTestController::class, 'userMonthlyAvgGfr']);
    Route::get('lab-tests/monthly-avg', [LabTestController::class, 'allUsersMonthlyAvgGfr']);

    // --- Lab Test Files ---
    Route::post('lab-test-files', [LabTestFileController::class, 'store']);
    Route::patch('lab-tests/{labTest}/files', [LabTestFileController::class, 'attach']);
    Route::get('lab-tests/{labTest}/files', [LabTestFileController::class, 'index']);
    Route::post('lab-tests/ocr', [LabTestFileController::class, 'ocr'])->middleware('throttle:lab-ocr');
    Route::post('lab-tests/ocr/errors', [LabTestFileController::class, 'reportClientError'])->middleware('throttle:lab-ocr-errors');

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
        Route::get('admin/lab-test-files', [AdminLabTestFileController::class, 'index']);
        Route::get('admin/lab-test-ocr-errors', [AdminLabTestOcrErrorController::class, 'index']);
        Route::get('admin/credits', [AdminCreditController::class, 'index']);
        Route::post('admin/credits', [AdminCreditController::class, 'store']);
        Route::get('admin/quick-assessments', [AdminQuickAssessmentController::class, 'index']);

        Route::prefix('admin/nephrology')->group(function () {
            Route::get('overview', [AdminNephrologistController::class, 'overview']);
            Route::get('doctors', [AdminNephrologistController::class, 'index']);
            Route::get('doctors/{profile}', [AdminNephrologistController::class, 'show'])->whereNumber('profile');
            Route::post('doctors/{profile}/review', [AdminNephrologistController::class, 'review'])->whereNumber('profile');
            Route::get('payouts', [AdminNephrologistController::class, 'payouts']);
            Route::post('payouts/{payout}', [AdminNephrologistController::class, 'processPayout'])->whereNumber('payout');
        });

        Route::get('admin/webinar-registrations', [AdminWebinarRegistrationController::class, 'index']);
        Route::post('admin/webinar-registrations', [AdminWebinarRegistrationController::class, 'store']);
        Route::delete('admin/webinar-registrations/{webinarRegistration}', [AdminWebinarRegistrationController::class, 'destroy']);

        Route::get('admin/sms-reminders/overview', [AdminSmsReminderController::class, 'overview']);
        Route::get('admin/sms-reminders', [AdminSmsReminderController::class, 'index']);
        Route::post('admin/sms-reminders/{scheduledSms}/retry', [AdminSmsReminderController::class, 'retry']);

        Route::prefix('admin/nutrition-monitoring')->group(function () {
            Route::get('overview', [NutritionMonitoringController::class, 'overview']);

            Route::get('queue', [NutritionMonitoringController::class, 'queue']);
            Route::post('queue/{consultationBooking}/remind', [NutritionMonitoringController::class, 'remindQueueItem']);
            Route::patch('queue/{consultationBooking}', [NutritionMonitoringController::class, 'reassignQueueItem']);

            Route::get('specialists', [NutritionMonitoringController::class, 'specialists']);
            Route::post('specialists', [NutritionMonitoringController::class, 'storeSpecialist']);
            Route::patch('specialists/{user}', [NutritionMonitoringController::class, 'updateSpecialist']);
            Route::get('specialists/{user}/weekly-hours', [SpecialistScheduleController::class, 'weeklyHours']);
            Route::put('specialists/{user}/weekly-hours', [SpecialistScheduleController::class, 'updateWeeklyHours']);
            Route::get('specialists/{user}/available-slots', [SpecialistScheduleController::class, 'availableSlots']);

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
    Route::post('/payments/redeem-discount', [PaymentController::class, 'redeemDiscount']);
    Route::post('/payments/bazaar/verify', [PaymentController::class, 'verifyBazaar']);
    Route::post('/payments/mobile/init', [MobilePaymentController::class, 'init'])->middleware('throttle:20,1');
    Route::get('/payments/{payment}/status', [MobilePaymentController::class, 'status'])->whereNumber('payment');

    // --- Wallet ---
    Route::get('wallet', [WalletController::class, 'show']);
    Route::get('wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('wallet/topup', [WalletController::class, 'topup']);

    // --- Quick kidney-risk assessment (once per account, not per device) ---
    Route::get('quick-assessment', [QuickAssessmentController::class, 'show']);
    Route::put('quick-assessment', [QuickAssessmentController::class, 'store']);

    // --- Diet ---
    Route::apiResource('/diet-plans', DietPlanController::class);

    // --- Provider Panel ---
    Route::prefix('provider')->middleware('is_provider')->group(function () {
        Route::get('patients', [ProviderPatientController::class, 'index']);
        Route::get('patients/{user}', [ProviderPatientController::class, 'show']);
        Route::get('files', [ProviderPatientController::class, 'allFiles']);
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
        Route::get('specialists', [NutritionSpecialistController::class, 'index']);

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

    // --- Nephrology text consultation (patient) ---
    Route::prefix('nephrology')->group(function () {
        Route::get('consultations', [NephrologyConsultationController::class, 'index']);
        Route::post('consultations', [NephrologyConsultationController::class, 'store'])->middleware('throttle:10,1');
        Route::get('consultations/{consultation}', [NephrologyConsultationController::class, 'show'])->whereNumber('consultation');
        Route::get('consultations/{consultation}/messages', [NephrologyConsultationController::class, 'messagesIndex'])->whereNumber('consultation');
        Route::post('consultations/{consultation}/messages', [NephrologyConsultationController::class, 'storeMessage'])->whereNumber('consultation')->middleware('throttle:40,1');
        Route::post('consultations/{consultation}/cancel', [NephrologyConsultationController::class, 'cancel'])->whereNumber('consultation');
        Route::post('consultations/{consultation}/review', [NephrologyConsultationController::class, 'review'])->whereNumber('consultation');
        Route::post('uploads', [NephrologyConsultationController::class, 'upload'])->middleware('throttle:20,1');
    });

    // --- Nephrologist: sign-up application (any signed-in account) ---
    Route::prefix('nephrologist/application')->group(function () {
        Route::get('/', [NephrologistApplicationController::class, 'show']);
        Route::put('/', [NephrologistApplicationController::class, 'update']);
        Route::post('submit', [NephrologistApplicationController::class, 'submit']);
        Route::post('documents/{key}', [NephrologistApplicationController::class, 'uploadDocument'])->middleware('throttle:30,1');
        Route::delete('documents/{key}', [NephrologistApplicationController::class, 'destroyDocument']);
    });

    // --- Nephrologist panel ---
    Route::prefix('nephrologist')->middleware('is_nephrologist')->group(function () {
        Route::get('me', [NephrologistPanelController::class, 'me']);
        Route::patch('profile', [NephrologistPanelController::class, 'updateProfile']);
        Route::get('dashboard', [NephrologistPanelController::class, 'dashboard']);
        Route::get('patients', [NephrologistPanelController::class, 'patients']);
        Route::get('earnings', [NephrologistPanelController::class, 'earnings']);
        Route::post('payouts', [NephrologistPanelController::class, 'requestPayout'])->middleware('throttle:10,1');
        Route::get('reviews', [NephrologistPanelController::class, 'reviews']);
        Route::post('reviews/{review}/reply', [NephrologistPanelController::class, 'replyReview'])->whereNumber('review');

        Route::get('consultations', [NephrologistConsultationController::class, 'index']);
        Route::get('consultations/{consultation}', [NephrologistConsultationController::class, 'show'])->whereNumber('consultation');
        Route::get('consultations/{consultation}/record', [NephrologistConsultationController::class, 'record'])->whereNumber('consultation');
        Route::get('consultations/{consultation}/messages', [NephrologistConsultationController::class, 'messagesIndex'])->whereNumber('consultation');
        Route::post('consultations/{consultation}/messages', [NephrologistConsultationController::class, 'storeMessage'])->whereNumber('consultation')->middleware('throttle:60,1');
        Route::post('consultations/{consultation}/quick-action', [NephrologistConsultationController::class, 'quickAction'])->whereNumber('consultation');
        Route::post('consultations/{consultation}/end', [NephrologistConsultationController::class, 'end'])->whereNumber('consultation');
        Route::post('consultations/{consultation}/decline', [NephrologistConsultationController::class, 'decline'])->whereNumber('consultation');
    });

    // --- Support Center ---
    Route::prefix('support')->group(function () {
        Route::get('faq', [SupportFaqController::class, 'index']);

        Route::get('tickets', [SupportTicketController::class, 'index']);
        Route::post('tickets', [SupportTicketController::class, 'store']);
        Route::get('tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::get('tickets/{ticket}/messages', [SupportTicketController::class, 'messages']);
        Route::post('tickets/{ticket}/messages', [SupportTicketController::class, 'storeMessage']);

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

        Route::patch('agents/me/state', [AdminAgentController::class, 'updateMyState'])->middleware('throttle:agent-presence');
        Route::get('agents/shifts', [AdminAgentController::class, 'shifts']);
        Route::patch('agents/shifts', [AdminAgentController::class, 'updateShifts']);
        Route::get('agents', [AdminAgentController::class, 'index']);

        Route::get('chat/sessions', [AdminChatController::class, 'sessions']);
        Route::patch('chat/sessions/{chatSession}', [AdminChatController::class, 'updateSessionStatus']);
        Route::post('chat/sessions/{chatSession}/typing', [AdminChatController::class, 'typing']);
        Route::get('chat/messages', [AdminChatController::class, 'index']);
        Route::post('chat/messages', [AdminChatController::class, 'storeMessage']);

        Route::get('support/reports', [SupportReportController::class, 'index']);

        Route::get('push/config', [AdminPushSubscriptionController::class, 'config']);
        Route::post('push/subscriptions', [AdminPushSubscriptionController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('push/subscriptions', [AdminPushSubscriptionController::class, 'destroy']);
    });
});
