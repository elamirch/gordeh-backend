<?php

namespace App\Services;

use App\Models\LabTestFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class LabTestFileService
{
    private const DISK = 'local';

    public function store(UploadedFile $file, User $patient, User $uploader): LabTestFile
    {
        $directory = 'lab-test-files/'.$patient->id;
        $storedPath = $file->store($directory, self::DISK);

        if ($storedPath === false) {
            Log::error('lab_test_file.storage_failed', [
                'patient_id' => $patient->id,
                'uploaded_by' => $uploader->id,
                'original_filename' => $file->getClientOriginalName(),
            ]);

            throw new RuntimeException('Failed to store lab test file.');
        }

        try {
            return LabTestFile::create([
                'user_id' => $patient->id,
                'uploaded_by' => $uploader->id,
                'disk' => self::DISK,
                'storage_key' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        } catch (\Throwable $e) {
            // Storage write already succeeded but the DB record failed — remove the
            // orphaned file rather than leaving unreferenced PHI sitting on disk.
            Storage::disk(self::DISK)->delete($storedPath);

            Log::error('lab_test_file.db_insert_failed_after_storage', [
                'patient_id' => $patient->id,
                'uploaded_by' => $uploader->id,
                'storage_key' => $storedPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function signedUrl(LabTestFile $file, int $minutes = 5): string
    {
        return URL::temporarySignedRoute(
            'lab-test-files.download',
            now()->addMinutes($minutes),
            ['labTestFile' => $file->id]
        );
    }
}
