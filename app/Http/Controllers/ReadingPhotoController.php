<?php

namespace App\Http\Controllers;

use App\Models\Reading;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReadingPhotoController extends Controller
{
    public function __invoke(Reading $reading): StreamedResponse
    {
        abort_unless($reading->photo_path && Storage::disk('local')->exists($reading->photo_path), 404);

        return Storage::disk('local')->response($reading->photo_path);
    }
}
