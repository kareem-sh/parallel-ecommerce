<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'E-Commerce backend API',
        'docs' => '/docs/NFR_REQUIREMENTS_AR.md',
    ]);
});
