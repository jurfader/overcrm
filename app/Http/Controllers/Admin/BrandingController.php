<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name'            => 'nullable|string|max:80',
            'short_name'      => 'nullable|string|max:30',
            'company_name'    => 'nullable|string|max:120',
            'primary_color'   => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'use_gradient'    => 'nullable|boolean',
            'support_email'   => 'nullable|email|max:120',
            'support_phone'   => 'nullable|string|max:40',
            'default_theme'   => 'nullable|in:dark,light',
        ]);

        foreach ($data as $key => $value) {
            $stored = is_bool($value) ? ($value ? '1' : '0') : $value;
            Setting::set('brand_' . $key, $stored, 'branding');
        }

        Cache::flush();

        return back()->with('success', 'Branding zapisany');
    }

    public function uploadAsset(Request $request)
    {
        $request->validate([
            'asset' => 'required|in:logo_url,logo_dark_url,favicon_url',
            'file'  => 'required|image|mimes:jpeg,png,gif,svg,webp,ico|max:2048',
        ]);

        $key = 'brand_' . $request->input('asset');
        $old = Setting::get($key, null, 'branding');
        if ($old && str_starts_with($old, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $old));
        }

        $path = $request->file('file')->store('branding', 'public');
        $url = '/storage/' . $path;

        Setting::set($key, $url, 'branding');
        Cache::flush();

        return back()->with('success', 'Plik przesłany');
    }

    public function removeAsset(Request $request)
    {
        $request->validate(['asset' => 'required|in:logo_url,logo_dark_url,favicon_url']);

        $key = 'brand_' . $request->input('asset');
        $old = Setting::get($key, null, 'branding');
        if ($old && str_starts_with($old, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $old));
        }

        Setting::set($key, null, 'branding');
        Cache::flush();

        return back()->with('success', 'Plik usunięty');
    }
}
