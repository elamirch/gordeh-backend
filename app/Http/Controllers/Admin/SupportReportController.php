<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Support\ReportService;
use Illuminate\Http\JsonResponse;

class SupportReportController extends Controller
{
    public function __construct(private readonly ReportService $service) {}

    // GET /admin/support/reports
    public function index(): JsonResponse
    {
        return response()->json($this->service->summary());
    }
}
