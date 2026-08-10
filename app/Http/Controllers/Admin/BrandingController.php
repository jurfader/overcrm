<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BrandingService;
use Illuminate\Http\Request;

class BrandingController extends Controller
{
    public function __construct(protected BrandingService $branding) {}

    public function update(Request $request)
    {
        $data = $request->validate(BrandingService::rules());

        $this->branding->update($data);

        return back()->with('success', 'Branding zapisany');
    }

    public function uploadAsset(Request $request)
    {
        $request->validate([
            'asset' => 'required|in:'.implode(',', BrandingService::ASSETS),
            'file' => 'required|image|mimes:jpeg,png,gif,svg,webp,ico|max:2048',
        ]);

        $this->branding->uploadAsset($request->input('asset'), $request->file('file'));

        return back()->with('success', 'Plik przesłany');
    }

    public function removeAsset(Request $request)
    {
        $request->validate([
            'asset' => 'required|in:'.implode(',', BrandingService::ASSETS),
        ]);

        $this->branding->removeAsset($request->input('asset'));

        return back()->with('success', 'Plik usunięty');
    }
}
