<?php

namespace App\Services;

use App\Models\LabTestOcrError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records a failed lab-report OCR attempt and hands back a short reference code
 * for the patient to quote to support. Admins look it up at /admin/lab-test-ocr-errors.
 *
 * Never logs file contents or extracted lab values — names, sizes and ids only.
 */
class LabTestOcrErrorTracker
{
    // No 0/O or 1/I/L — the patient may read the code out over the phone.
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Never throws: tracking must not turn one failure into another. */
    public function record(
        Request $request,
        string $source,
        string $stage,
        string $errorType,
        ?int $httpStatus,
        ?string $detail = null,
        array $context = [],
    ): string {
        $reference = $this->newReference();
        $userId = $request->user()?->getAuthIdentifier();

        Log::error('lab_test_ocr.error', [
            'reference' => $reference,
            'user_id' => $userId,
            'source' => $source,
            'stage' => $stage,
            'error_type' => $errorType,
            'http_status' => $httpStatus,
            'detail' => $detail,
        ]);

        try {
            LabTestOcrError::create([
                'reference' => $reference,
                'user_id' => $userId,
                'source' => $source,
                'stage' => $stage,
                'error_type' => Str::limit($errorType, 40, ''),
                'http_status' => $httpStatus,
                'detail' => $detail !== null ? Str::limit($detail, 2000) : null,
                'context' => $context ?: null,
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ]);
        } catch (\Throwable $e) {
            // The log line above still carries the reference.
            Log::error('lab_test_ocr.error_tracking_failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        return $reference;
    }

    /** Name/size/type of each uploaded image — enough to spot a bad file. */
    public function describeUploads(Request $request): array
    {
        $images = $request->file('images');
        if (! is_array($images)) {
            return [];
        }

        return collect($images)->map(fn ($image) => $image ? [
            'name' => $image->getClientOriginalName(),
            'size' => $image->getSize(),
            'mime' => $image->getClientMimeType(),
            'uploadError' => $image->getError() ?: null,
        ] : null)->values()->all();
    }

    private function newReference(): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'OCR-'.$code;
    }
}
