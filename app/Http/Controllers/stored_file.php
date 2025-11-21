<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use Illuminate\Http\Request;

class StoredFileController extends Controller
{
    public function __construct()
    {
        // Only index & show are public
        $this->middleware('auth:sanctum')->except(['index', 'show']);
    }

    /**
     * GET /stored-files
     */
    public function index()
    {
        return StoredFile::with('user')->get();
    }

    /**
     * POST /stored-files
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'url'               => 'nullable|string',
            'fileName'          => 'nullable|string',
            'originalFileName'  => 'nullable|string',
            'mainImageUrl'      => 'nullable|string',
        ]);

        // Automatically assign authenticated user
        $data['user_id'] = auth()->id();

        $file = StoredFile::create($data);

        return response()->json($file, 201);
    }

    /**
     * GET /stored-files/{storedFile}
     */
    public function show(StoredFile $storedFile)
    {
        return $storedFile->load('user');
    }

    /**
     * PUT/PATCH /stored-files/{storedFile}
     */
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

    /**
     * DELETE /stored-files/{storedFile}
     */
    public function destroy(StoredFile $storedFile)
    {
        // Optional permission logic
        // if (auth()->id() !== $storedFile->user_id && auth()->user()->role !== 'admin') {
        //     return response()->json(['message' => 'Forbidden'], 403);
        // }

        $storedFile->delete();

        return response()->json(null, 204);
    }
}
