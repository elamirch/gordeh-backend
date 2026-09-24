<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use App\Services\Support\FaqService;
use Illuminate\Http\JsonResponse;

class FaqController extends Controller
{
    public function __construct(private readonly FaqService $service) {}

    // GET /support/faq
    public function index(): JsonResponse
    {
        $items = $this->service->listPublished();

        return response()->json($items->map(fn (FaqItem $item) => $this->service->presentForPatient($item))->values());
    }
}
