<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocController extends Controller
{
    private const CATEGORY = 'nutrition-consultation';

    public function index()
    {
        $docs = StoredFile::where('user_id', auth()->id())
            ->where('category', self::CATEGORY)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($docs->map(fn (StoredFile $f) => [
            'id' => $f->id,
            'title' => $f->originalFileName ?? $f->fileName,
            'meta' => $f->created_at->toIso8601String(),
        ])->values());
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $uploadedFile = $request->file('file');
        $url = $uploadedFile->store('uploads', 'public');

        $doc = StoredFile::create([
            'url' => $url,
            'fileName' => $uploadedFile->hashName(),
            'originalFileName' => $uploadedFile->getClientOriginalName(),
            'user_id' => auth()->id(),
            'category' => self::CATEGORY,
        ]);

        return response()->json($doc, 201);
    }

    public function destroy(StoredFile $storedFile)
    {
        if ($storedFile->user_id !== auth()->id() || $storedFile->category !== self::CATEGORY) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        Storage::disk('public')->delete($storedFile->url);
        $storedFile->delete();

        return response()->json(null, 204);
    }
}
