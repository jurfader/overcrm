<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LicenseController extends Controller
{
    public function __construct(protected LicenseService $license) {}

    public function show()
    {
        return Inertia::render('License/Index', [
            'license' => $this->license->status(),
            'domain'  => parse_url(config('app.url'), PHP_URL_HOST),
        ]);
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'license_key' => 'required|string|min:8|max:50',
        ]);

        $result = $this->license->activate($data['license_key']);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function refresh()
    {
        $result = $this->license->validate();
        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }
}
