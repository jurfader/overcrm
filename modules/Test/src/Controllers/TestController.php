<?php

namespace Modules\Test\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class TestController extends Controller
{
    public function index()
    {
        return Inertia::render('Test/Index', [
            'title' => 'Test',
        ]);
    }
}