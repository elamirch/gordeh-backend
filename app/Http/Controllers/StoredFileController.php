<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;

class StoredFileController extends Controller
{
    public function index()
    {
        $query = StoredFile::query();

        if (auth()->user()->role === 'admin') {
            $query->with('user');
        } else {
            $query->where('user_id', auth()->id());
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file'              => 'required|file|mimes:jpg,png,pdf|max:10240',
            'fileName'          => 'nullable|string',
            'originalFileName'  => 'nullable|string',
            'mainImageUrl'      => 'nullable|string',
            'category'          => 'nullable|string|max:50',
        ]);

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            
            $data['url'] = PrivateFiles::store($uploadedFile, 'uploads');
            $data['fileName'] = $data['fileName'] ?? $uploadedFile->hashName();
            $data['originalFileName'] = $data['originalFileName'] ?? $uploadedFile->getClientOriginalName(); // file.originalname
        }

        $data['user_id'] = auth()->id();
        $file = StoredFile::create($data);

        return response()->json($file, 201);
    }

    public function show(StoredFile $storedFile)
    {
        $this->authorizeAccess($storedFile, true);

        return $storedFile->load('user');
    }

    public function destroy(StoredFile $storedFile)
    {
        $this->authorizeAccess($storedFile, false);

        PrivateFiles::delete($storedFile->url);
        $storedFile->delete();

        return response()->json(null, 204);
    }

    /** Owner and admins always; providers may also view (not delete) patients' files. */
    private function authorizeAccess(StoredFile $storedFile, bool $providerMayView): void
    {
        $user = auth()->user();
        $allowed = $storedFile->user_id === $user->id
            || $user->role === 'admin'
            || ($providerMayView && $user->role === 'provider');

        abort_unless($allowed, 403, 'Forbidden');
    }
}
