<?php

namespace App\Services\Support;

use App\Models\FaqItem;
use Illuminate\Support\Collection;

class FaqService
{
    public const STATUSES = ['published', 'draft'];

    public function listPublished(): Collection
    {
        return FaqItem::where('status', 'published')->orderBy('sort_order')->orderBy('created_at')->get();
    }

    public function listAll(): Collection
    {
        return FaqItem::orderBy('sort_order')->orderBy('created_at')->get();
    }

    public function create(array $data): FaqItem
    {
        return FaqItem::create([
            'question' => $data['question'],
            'answer' => $data['answer'],
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'sort_order' => (int) (FaqItem::max('sort_order') ?? 0) + 1,
        ]);
    }

    public function update(FaqItem $item, array $data): FaqItem
    {
        $item->fill(array_intersect_key($data, array_flip(['question', 'answer', 'category', 'status'])));
        $item->save();

        return $item->fresh();
    }

    public function presentForPatient(FaqItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'question' => $item->question,
            'answer' => $item->answer,
        ];
    }

    public function presentForAdmin(FaqItem $item): array
    {
        return [
            'id' => $item->id,
            'question' => $item->question,
            'answer' => $item->answer,
            'category' => $item->category,
            'status' => $item->status,
            'order' => $item->sort_order,
            'views' => $item->views,
            'helpfulPct' => $item->helpful_pct,
            'createdAt' => $item->created_at->toIso8601String(),
            'updatedAt' => $item->updated_at->toIso8601String(),
        ];
    }
}
