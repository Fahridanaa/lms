<?php

namespace App\Http\Controllers;

use App\Services\BenchmarkResultsService;
use Illuminate\Contracts\View\View;

class BenchmarkController extends Controller
{
    public function __invoke(BenchmarkResultsService $benchmarkResults): View
    {
        return view('benchmarks.index', [
            'benchmarkData' => $benchmarkResults->dashboardData(),
        ]);
    }
}
