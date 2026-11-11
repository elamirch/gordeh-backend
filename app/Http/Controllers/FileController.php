<?php

namespace App\Http\Controllers;

use App\Models\DietPlan;
use App\Services\Support\ChatUploadService;
use App\Models\StoredFile;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    // GET /files?path=... (signed URL only — issued by the API after an authorization check)
    public function serve()
    {
        $path = PrivateFiles::path(request()->query('path'));

        // Only files that belong to a record are served; never an arbitrary path. Chat uploads
        // are the exception: guests have no record to attach them to, and the directory is
        // written exclusively by the authenticated chat upload endpoint.
        $known = $path !== null && (
            Str::startsWith($path, ChatUploadService::DIRECTORY.'/')
            || StoredFile::where('url', $path)->exists()
            || DietPlan::where('file_url', $path)->exists()
        );

        $disk = $known ? PrivateFiles::diskFor($path) : null;
        if ($disk === null) {
            abort(404);
        }

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
