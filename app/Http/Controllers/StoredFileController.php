<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use Illuminate\Http\Request;

class StoredFileController extends Controller
{
    public function index()
    {
        return StoredFile::with('user')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'url'               => 'nullable|string',
            'fileName'          => 'nullable|string',
            'originalFileName'  => 'nullable|string',
            'mainImageUrl'      => 'nullable|string',
        ]);

        $data['user_id'] = auth()->id();

        $file = StoredFile::create($data);

        return response()->json($file, 201);
    }

    public function show(StoredFile $storedFile)
    {
        return $storedFile->load('user');
    }

    public function update(Request $request, StoredFile $storedFile)
    {
        // Optional permission logic
        // if (auth()->id() !== $storedFile->user_id && auth()->user()->role !== 'admin') {
        //     return response()->json(['message' => 'Forbidden'], 403);
        // }

        $data = $request->validate([
            'url'               => 'nullable|string',
            'fileName'          => 'nullable|string',
            'originalFileName'  => 'nullable|string',
            'mainImageUrl'      => 'nullable|string',
        ]);

        $storedFile->update($data);

        return $storedFile;
    }

    public function destroy(StoredFile $storedFile)
    {
        // Optional permission logic
        // if (auth()->id() !== $storedFile->user_id && auth()->user()->role !== 'admin') {
        //     return response()->json(['message' => 'Forbidden'], 403);
        // }

        $storedFile->delete();

        return response()->json(null, 204);
    }

    public function ocrSaveFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string',
            'fileName' => 'required|string',
            'originalFileName' => 'required|string',
            'mainImageUrl' => 'nullable|string',
        ]);

        try {
            $data['user_id'] = auth()->id();
            $data['mainImageUrl'] = $data['mainImageUrl'] ?? $data['url'];

            $file = StoredFile::create($data);

            return response()->json([
                'message' => 'uploaded successfully',
                'data' => [
                    'url' => $file->url,
                    'fileName' => $file->fileName,
                    'originalFileName' => $file->originalFileName,
                    'mainImageUrl' => $file->mainImageUrl,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Save a profile image file (same behavior as OCR save)
     */
    public function profileSaveFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string',
            'fileName' => 'required|string',
            'originalFileName' => 'required|string',
            'mainImageUrl' => 'nullable|string',
        ]);

        try {
            $data['user_id'] = auth()->id();
            $data['mainImageUrl'] = $data['mainImageUrl'] ?? $data['url'];

            $file = StoredFile::create($data);

            return response()->json([
                'message' => 'uploaded successfully',
                'data' => [
                    'url' => $file->url,
                    'fileName' => $file->fileName,
                    'originalFileName' => $file->originalFileName,
                    'mainImageUrl' => $file->mainImageUrl,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }
}
