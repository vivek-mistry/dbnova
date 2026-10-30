<?php

namespace App\Http\Controllers;

use App\Services\System\SystemCheck;
use App\Services\System\SystemCheckService;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    /**
     * Show the system health page.
     */
    public function index(SystemCheckService $systemCheckService): Response
    {
        return Inertia::render('system/Health', [
            'checks' => array_map(fn (SystemCheck $check): array => $check->toArray(), $systemCheckService->run()),
        ]);
    }
}
