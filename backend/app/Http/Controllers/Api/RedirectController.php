<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Support\UrlPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedirectController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $url = trim((string) $request->query('url', ''));

        abort_if($url === '', 404);

        $normalizedPath = $this->normalizedPath($url);
        $sourcePathHash = Redirect::sourcePathHash($url);

        abort_if($sourcePathHash === null, 404);

        $redirect = Redirect::query()
            ->where('is_active', true)
            ->where('source_path_hash', $sourcePathHash)
            ->oldest('id')
            ->limit(25)
            ->get()
            ->first(function (Redirect $redirect) use ($normalizedPath): bool {
                $sourcePath = $this->normalizedPath($redirect->source_url);
                $targetPath = $this->normalizedPath($redirect->target_url);

                if ($targetPath !== '' && $targetPath === $normalizedPath) {
                    return false;
                }

                return $sourcePath === $normalizedPath;
            })
            ?? abort(404);

        return response()->json([
            'data' => [
                'target_url' => $redirect->target_url,
                'status_code' => $redirect->status_code,
            ],
        ]);
    }

    private function normalizedPath(?string $url): string
    {
        return UrlPath::normalize($url);
    }
}
