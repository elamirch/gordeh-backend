<?php

namespace App\Services;

use App\Models\LabTestFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extracts lab values from uploaded lab-report files via Metis AI's Gemini proxy.
 *
 * Never log the file contents, the API key or the extracted values — only ids.
 */
class LabTestOcrService
{
    /** Metis unreachable, timing out or failing on its side — worth retrying later. */
    public const ERROR_SERVICE_UNAVAILABLE = 'service_unavailable';

    /** Metis answered but not with the JSON shape the prompt asks for. */
    public const ERROR_INVALID_RESPONSE = 'invalid_response';

    /** Our side: missing API key, or a stored file that can't be read back. */
    public const ERROR_INTERNAL = 'internal_error';

    public const ERROR_MESSAGES = [
        self::ERROR_SERVICE_UNAVAILABLE => 'سرویس در دسترس نیست',
        self::ERROR_INVALID_RESPONSE => 'پردازش تصویر آزمایش ناموفق بود. لطفاً دوباره تلاش کنید.',
        self::ERROR_INTERNAL => 'خطای داخلی در پردازش آزمایش رخ داد. لطفاً بعداً تلاش کنید.',
    ];

    private const CONNECT_TIMEOUT = 10;

    private const TIMEOUT = 120;

    /** Extra attempts for transient Metis failures (connection, 5xx, 429). */
    private const RETRIES = 2;

    private const RETRY_DELAY_MS = 1500;

    /** Worst case: 3 connect attempts + retry delays + one full model call, with headroom. */
    public const MAX_DURATION_SECONDS = 180;

    private const PROMPT = <<<'PROMPT'
You are extracting lab test values from an image of a medical lab report.

Follow these steps in order:

1. First, check if the image is a legible lab report at all (not blank, not too blurry to read any text, not an unrelated photo). If you cannot read the report's text/structure at all, return:
{"error": true, "errorType": "unreadable", "message": "متن آزمایش واضح نیست"}

2. If the image IS a legible, readable lab report, look for these mandatory fields: creatinine, calcium, phosphorus, albumin, urine_creatinine, urine_albumin.
Being able to read the report but not finding one or more of these specific test values in it is NOT the same as "unreadable" — in that case return:
{"error": true, "errorType": "missing_fields", "message": "این آیتم‌ها در آزمایش شما یافت نشد", "missingItems": ["<Persian name of each missing item>"]}

Example: if the image clearly shows a readable lab report with creatinine, calcium, phosphorus, and albumin, but no urine_creatinine or urine_albumin values anywhere in it, that is "missing_fields" with missingItems: ["کراتینین ادرار", "آلبومین ادرار"] — NOT "unreadable".

3. If the image is readable AND all mandatory fields are present, return:
{"error": false, "name":"...","age":...,"gender":"just return m or f","creatinine":{"value":...,"unit":"..."},"calcium":{"value":...,"unit":"..."},"phosphorus":{"value":...,"unit":"..."},"albumin":{"value":...,"unit":"..."},"urine_creatinine":{"value":...,"unit":"..."},"urine_albumin":{"value":...,"unit":"..."},"bicarbonate":{"value":...,"unit":"..."} (if available),"urine_acr":{"value":...,"unit":"..."} (only if the report prints an albumin/creatinine ratio)}

Copy every value and its unit EXACTLY as printed on the report:
- "value" is the number as printed (a JSON number, using "." as the decimal point). "unit" is the unit text printed next to that value, e.g. "mg/dL", "mg/L", "g/L", "µmol/L", "mmol/L", "mg/g", "mg/mmol".
- Do NOT convert units, do NOT rescale values, and do NOT calculate anything (in particular never compute the albumin/creatinine ratio yourself). Unit conversion is done by our software.
- If no unit is printed for a value, set "unit" to null. Never guess a unit.
- Serum/blood creatinine goes in "creatinine"; urine creatinine (often in a separate urine section, e.g. "Creatinine, urine", "Urine Creatinine") goes in "urine_creatinine". Urine albumin ("Microalbumin", "Micro Albumin Urine", "Albumin, urine") goes in "urine_albumin". Serum albumin goes in "albumin". Never mix serum and urine values.
- Accept test-name variants (e.g. "Creatinine, urine", "Microalbumin", "Albumin/Creatinine ratio", "UACR", "ACR").

Return ONLY valid JSON, no other text, no markdown formatting.
PROMPT;

    /**
     * Runs OCR over every file of one batch in a single Gemini call and stores the
     * outcome on each file. On failure the stored ocr_result holds the error code and
     * its Persian message, so admins can see why a file wasn't extracted.
     *
     * @param  Collection<int, LabTestFile>  $files
     * @return array{result: ?array, error: ?string, reason: ?string}  `reason` is internal — for logs/admins only
     */
    public function process(Collection $files, int $userId): array
    {
        try {
            $result = $this->extract($files);
            $status = $this->statusFor($result);
            $error = null;
            $reason = null;
        } catch (LabTestOcrFailure $e) {
            $result = null;
            $status = LabTestFile::OCR_FAILED;
            $error = $e->errorCode;
            $reason = $e->getMessage();

            Log::error('lab_test_ocr.failed', [
                'user_id' => $userId,
                'file_ids' => $files->pluck('id')->all(),
                'error' => $error,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            $result = null;
            $status = LabTestFile::OCR_FAILED;
            $error = self::ERROR_INTERNAL;
            $reason = get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine();

            Log::error('lab_test_ocr.failed', [
                'user_id' => $userId,
                'file_ids' => $files->pluck('id')->all(),
                'error' => $error,
                'reason' => get_class($e),
            ]);
        }

        $stored = $error === null
            ? $result
            : ['error' => true, 'errorType' => $error, 'message' => self::ERROR_MESSAGES[$error]];

        LabTestFile::whereIn('id', $files->pluck('id'))->update([
            'ocr_status' => $status,
            'ocr_result' => json_encode($stored, JSON_UNESCAPED_UNICODE),
            'ocr_processed_at' => now(),
        ]);

        $files->each->refresh();

        return ['result' => $result, 'error' => $error, 'reason' => $reason];
    }

    /**
     * @param  Collection<int, LabTestFile>  $files
     */
    private function extract(Collection $files): array
    {
        $apiKey = config('services.metis.api_key');
        if (! $apiKey) {
            throw new LabTestOcrFailure(self::ERROR_INTERNAL, 'METIS_API_KEY is not configured');
        }

        $parts = [['text' => self::PROMPT]];
        foreach ($files as $file) {
            $contents = Storage::disk($file->disk)->get($file->storage_key);
            if ($contents === null) {
                throw new LabTestOcrFailure(self::ERROR_INTERNAL, "Stored file {$file->id} could not be read");
            }

            $parts[] = ['inline_data' => [
                'mime_type' => $file->mime_type,
                'data' => base64_encode($contents),
            ]];
        }

        $url = rtrim(config('services.metis.base_url'), '/')
            .'/'.config('services.metis.gemini_model').':generateContent';

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->retry(self::RETRIES + 1, self::RETRY_DELAY_MS, fn (\Throwable $e) => $this->isTransient($e), throw: false)
                ->post($url, [
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    // JSON mode: the reply is the object itself, no prose or code fences.
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ]);
        } catch (ConnectionException $e) {
            throw new LabTestOcrFailure(self::ERROR_SERVICE_UNAVAILABLE, 'Could not connect to Metis: '.$e->getMessage());
        }

        if (! $response->successful()) {
            // 401/403 means our key is wrong — not something the user can retry past.
            $code = in_array($response->status(), [401, 403], true)
                ? self::ERROR_INTERNAL
                : self::ERROR_SERVICE_UNAVAILABLE;

            throw new LabTestOcrFailure($code, 'Metis responded with HTTP '.$response->status());
        }

        return $this->parse((string) $response->json('candidates.0.content.parts.0.text', ''));
    }

    /** Same cleanup as the old frontend route: drop fences/escapes, parse from the first "{". */
    private function parse(string $text): array
    {
        $cleaned = str_replace(['```', '\\n', '\\"'], ['', '', '"'], $text);
        $start = strpos($cleaned, '{');
        if ($start === false) {
            throw new LabTestOcrFailure(self::ERROR_INVALID_RESPONSE, 'No JSON object in model output');
        }

        $decoded = json_decode(substr($cleaned, $start), true);
        if (! is_array($decoded)) {
            throw new LabTestOcrFailure(self::ERROR_INVALID_RESPONSE, 'Model output is not valid JSON');
        }

        return $decoded;
    }

    private function statusFor(array $result): string
    {
        if (($result['error'] ?? null) === false) {
            return LabTestFile::OCR_SUCCESS;
        }

        if (($result['error'] ?? null) === true) {
            $type = $result['errorType'] ?? null;
            if (in_array($type, [LabTestFile::OCR_UNREADABLE, LabTestFile::OCR_MISSING_FIELDS], true)) {
                return $type;
            }
        }

        throw new LabTestOcrFailure(self::ERROR_INVALID_RESPONSE, 'Unexpected model output shape');
    }

    private function isTransient(\Throwable $e): bool
    {
        // Retry connect-phase failures only: a read timeout means Metis is already
        // working on it, and retrying would multiply the user's 120s wait.
        if ($e instanceof ConnectionException) {
            return ! str_contains($e->getMessage(), 'Operation timed out');
        }

        return $e instanceof RequestException
            && ($e->response->serverError() || $e->response->status() === 429);
    }
}
