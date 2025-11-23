<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    protected AdminDashboardService $service;

    public function __construct(AdminDashboardService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/admin/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        // Optionally add auth/authorization middleware to routes instead of checking here
        $stats = $this->service->getDashboardStats();

        return response()->json($stats);
    }
}
