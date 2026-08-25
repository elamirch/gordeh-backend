<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use App\Services\Support\FaqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FaqController extends Controller
{
    public function __construct(private readonly FaqService $service) {}

    // GET /admin/faq
    public function index(): JsonResponse
    {
        $items = $this->service->listAll();

        return response()->json($items->map(fn (FaqItem $i) => $this->service->presentForAdmin($i))->values());
    }

    // POST /admin/faq
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'answer' => ['required', 'string', 'min:1', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(FaqService::STATUSES)],
        ]);

        $item = $this->service->create($data);

        return response()->json($this->service->presentForAdmin($item), 201);
    }

    // PATCH /admin/faq/{faqItem}
    public function update(Request $request, FaqItem $faqItem): JsonResponse
    {
        $data = $request->validate([
            'question' => ['sometimes', 'string', 'min:3', 'max:500'],
            'answer' => ['sometimes', 'string', 'min:1', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(FaqService::STATUSES)],
        ]);

        $item = $this->service->update($faqItem, $data);

        return response()->json($this->service->presentForAdmin($item));
    }
}
