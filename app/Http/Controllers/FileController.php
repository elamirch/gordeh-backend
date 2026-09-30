<?php

namespace App\Http\Controllers;

use App\Models\DietPlan;
use App\Models\StoredFile;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    // GET /files?path=... (signed URL only — issued by the API after an authorization check)
    public function serve()
    {
        $path = PrivateFiles::path(request()->query('path'));

        // Only files that belong to a record are served; never an arbitrary path.
        $known = $path !== null && (
            StoredFile::where('url', $path)->exists()
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
