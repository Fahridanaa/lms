<?php

use App\Http\Controllers\BenchmarkController;
use Illuminate\Support\Facades\Route;

Route::get('/', BenchmarkController::class)->name('benchmarks.index');

Route::redirect('/benchmarks', '/');
