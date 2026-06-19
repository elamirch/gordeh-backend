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
            'file'              => 'required|file|mimes:jpg,png,pdf|max:10240',
            'fileName'          => 'nullable|string',
            'originalFileName'  => 'nullable|string',
            'mainImageUrl'      => 'nullable|string',
        ]);

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            
            $data['url'] = $uploadedFile->store('uploads');
            $data['fileName'] = $data['fileName'] ?? $uploadedFile->hashName();
            $data['originalFileName'] = $data['originalFileName'] ?? $uploadedFile->getClientOriginalName(); // file.originalname
        }

        $data['user_id'] = auth()->id();
        $file = StoredFile::create($data);

        return response()->json($file, 201);
    }

    public function show(StoredFile $storedFile)
    {
        return $storedFile->load('user');
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
}
